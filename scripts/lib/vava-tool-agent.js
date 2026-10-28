/**
 * VAVA tool-calling agent for messages addressed to the bot (tagged, replied-to, or DMs).
 *
 * One model reads the conversation and either replies directly or calls tools
 * (lookups, village actions, or generic Notion reads/writes), sees the results,
 * and then writes the reply. Tools are supplied by the caller; this module only
 * runs the loop.
 */

"use strict";

const openai = require("./openai");
const { withVavaVoice } = require("./vava-voice");

const MAX_ROUNDS = Number(process.env.VAVA_AGENT_MAX_ROUNDS || 4);
const MAX_TOOL_CALLS = Number(process.env.VAVA_AGENT_MAX_TOOL_CALLS || 8);
const TOOL_RESULT_CHARS = 6000;

function systemPrompt({ databases = [] } = {}) {
  return withVavaVoice(
    [
      "You are talking in a Telegram chat. The LATEST MESSAGE is addressed to you.",
      "",
      "HOW TO DECIDE",
      "- First work out what this person wants from THIS message. Use the conversation only to resolve",
      "  'it', 'that', 'yes', 'do it', and replies to your own last message.",
      "- Banter, jokes, thanks, feelings, or questions about you (are you alive, did someone program you,",
      "  why did you say that) need no tools — just talk back naturally and briefly.",
      "- General knowledge or advice (recipes, how to fix a bike, Portuguese phrases) needs no tools either.",
      "- Anything about the village's own records — schedule, rota, events, markets, tasks, duties, where",
      "  things are, FAQs, docs, childcare, kids programme, members, projects, stays — look it up with a tool.",
      "  Never answer those from memory and never invent them.",
      "- When they ask you to do something (log, add, change, mark done, cancel, rate, remember), do it with",
      "  the most specific tool. Several requests in one message → several tool calls.",
      "- If no specific tool fits, use notion_search / notion_write on the right database. Call",
      "  notion_describe_database first if you need its fields or existing titles.",
      "- Only fetch what this message asks about. Don't add market lists, the rota, or events they didn't ask for.",
      "- If a write is genuinely ambiguous (which item? which date?), ask one short question instead of guessing.",
      "- If a tool returns needs_confirmation, ask them to confirm; when they say yes, call it again with confirmed=true.",
      "- If a tool returns ambiguous matches, ask which one, listing the options.",
      "- If a tool returns nothing, say so plainly. If it fails, say it didn't work and why, briefly.",
      "- Tools described as 'Replies itself' send their own message to the chat. Call them on their own,",
      "  after any other actions, and don't write a reply as well.",
      "- A photo/PDF attachment with little or no text is usually a receipt → log_receipt.",
      "",
      "RULES",
      "- You can never delete or archive anything in Notion — only create, update, or add notes.",
      "  Marking a task done or an event cancelled is fine.",
      "- Private details (childcare rosters, allergies, phone numbers, receipts, campsite guests) are only",
      "  returned by tools when this person may see them. Pass on only what the tool gave you.",
      "",
      "REPLY STYLE",
      "- Plain text for Telegram (no markdown headings, no ** bold **). Short. Lists only for several items.",
      "- After an action, confirm what you did in one line. Notion links are added for you — don't write URLs.",
      "- Same language as the person.",
      databases.length ? `\nNOTION DATABASES: ${databases.join(", ")}` : "",
    ]
      .filter((line) => line !== null)
      .join("\n")
  );
}

function safeParseArgs(raw) {
  if (!raw) return {};
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === "object" ? parsed : {};
  } catch {
    return {};
  }
}

function addUsage(total, usage) {
  if (!usage) return total;
  total.prompt += usage.prompt_tokens || 0;
  total.completion += usage.completion_tokens || 0;
  total.cached += usage.prompt_tokens_details?.cached_tokens || 0;
  return total;
}

function toolSpec(tool) {
  return {
    type: "function",
    function: {
      name: tool.name,
      description: tool.description,
      parameters: tool.parameters || { type: "object", properties: {} },
    },
  };
}

/**
 * @param {object} opts
 * @param {Array<{name:string, description:string, parameters?:object, writes?:boolean, run:(args:object)=>Promise<object>}>} opts.tools
 *   A tool result may include: url + linkTitle or links[] (appended to the reply by the caller),
 *   done (one-line summary used if the model call fails later), handedOff (the tool already replied).
 * @param {string} opts.context  user-turn text: speaker, chat, date, conversation, latest message
 * @param {string[]} [opts.databases]
 * @param {Function} [opts.chat]  injectable for tests; defaults to openai.chatWithTools
 * @returns {Promise<{reply:string, handedOff:boolean, links:Array<{title:string,url:string}>, trace:Array, usage:object, rounds:number}>}
 */
async function runToolAgent({ tools, context, databases = [], chat = openai.chatWithTools, log = () => {} }) {
  const byName = new Map(tools.map((t) => [t.name, t]));
  const specs = tools.map(toolSpec);
  const messages = [
    { role: "system", content: systemPrompt({ databases }) },
    { role: "user", content: context },
  ];
  const trace = [];
  const links = [];
  const usage = { prompt: 0, completion: 0, cached: 0 };
  let toolCalls = 0;
  let rounds = 0;

  while (rounds < MAX_ROUNDS) {
    rounds += 1;
    const lastRound = rounds === MAX_ROUNDS || toolCalls >= MAX_TOOL_CALLS;
    let response;
    try {
      response = await chat({ messages, tools: lastRound ? [] : specs });
    } catch (err) {
      return { reply: "", handedOff: false, links, trace, usage, rounds, error: err.message || String(err) };
    }
    const { message, usage: u } = response;
    addUsage(usage, u);

    const calls = Array.isArray(message.tool_calls) ? message.tool_calls : [];
    if (!calls.length || lastRound) {
      return {
        reply: String(message.content || "").trim(),
        handedOff: false,
        links,
        trace,
        usage,
        rounds,
      };
    }

    messages.push({
      role: "assistant",
      content: message.content || null,
      tool_calls: calls,
      ...(message._items ? { _items: message._items } : {}),
    });

    for (const call of calls) {
      toolCalls += 1;
      const name = call.function?.name;
      const args = safeParseArgs(call.function?.arguments);
      const tool = byName.get(name);
      const started = Date.now();
      let result;
      if (!tool) {
        result = { error: `Unknown tool ${name}` };
      } else if (toolCalls > MAX_TOOL_CALLS) {
        result = { error: "Too many tool calls for one message — reply with what you have." };
      } else {
        try {
          result = (await tool.run(args)) ?? { ok: true };
        } catch (err) {
          result = { error: err.message || String(err) };
        }
      }
      trace.push({
        tool: name,
        args,
        ok: !result?.error,
        wrote: Boolean(tool?.writes && !result?.error && !result?.needs_confirmation),
        summary: result?.done || "",
        ms: Date.now() - started,
      });
      log(`agent tool ${name} ${JSON.stringify(args).slice(0, 200)} → ${result?.error ? `error: ${result.error}` : "ok"}`);

      if (result?.url && result?.linkTitle) links.push({ title: result.linkTitle, url: result.url });
      for (const link of result?.links || []) {
        if (link?.url) links.push({ title: link.title || "Notion", url: link.url });
      }
      if (result?.handedOff) {
        return { reply: "", handedOff: true, links, trace, usage, rounds };
      }

      const { url, links: _links, linkTitle, ...forModel } = result || {};
      messages.push({
        role: "tool",
        tool_call_id: call.id,
        content: JSON.stringify(forModel).slice(0, TOOL_RESULT_CHARS),
      });
    }
  }

  return { reply: "", handedOff: false, links, trace, usage, rounds };
}

module.exports = {
  runToolAgent,
  systemPrompt,
  MAX_ROUNDS,
  MAX_TOOL_CALLS,
};
