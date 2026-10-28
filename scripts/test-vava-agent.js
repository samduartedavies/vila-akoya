#!/usr/bin/env node
/**
 * Tests for the VAVA tool agent loop.
 *
 *   node scripts/test-vava-agent.js          # offline: mocked model
 *   node scripts/test-vava-agent.js --live   # also asks the real model which tools it picks
 *                                            # (stub tools — nothing touches Notion)
 */
"use strict";

require("./lib/load-env");
const assert = require("assert");
const { runToolAgent } = require("./lib/vava-tool-agent");
const openai = require("./lib/openai");

function scriptedChat(steps) {
  let i = 0;
  return async ({ messages }) => {
    const step = steps[i++];
    if (!step) throw new Error("model called more times than scripted");
    return {
      message: typeof step === "function" ? step(messages) : step,
      usage: { prompt_tokens: 100, completion_tokens: 10 },
    };
  };
}

function toolCall(id, name, args) {
  return { id, type: "function", function: { name, arguments: JSON.stringify(args) } };
}

function stubTools(calls, overrides = {}) {
  const names = {
    get_schedule: { events: [] },
    find_markets: { markets: [{ place: "Loulé", kind: "farmers / gypsy market", venue: "Mercado Municipal" }] },
    rate_market: { saved: true, average: 5, ratings: 2 },
    create_task: { created: true, url: "https://notion.so/task", linkTitle: "Fix gate" },
    cancel_event: { needs_confirmation: true, would_cancel: ["Yoga Mon", "Yoga Wed"] },
    my_day: { handedOff: true },
    where_is: { found: false },
    faq_lookup: { found: false },
    search_docs: { found: false },
    notion_search: { rows: [] },
    notion_write: { ok: true },
  };
  const str = { type: "string" };
  const schemas = {
    rate_market: {
      description: "Save someone's 1–10 rating of a market (call once per market).",
      properties: { place: str, score: { type: "integer" }, comment: str },
      required: ["place", "score"],
    },
    find_markets: {
      description: "Markets: which are on a date, where one is, when it runs, rating.",
      properties: { date: str, place: str, kind: { type: "string", enum: ["flea", "farmers"] } },
    },
    cancel_event: {
      description:
        "Cancel event(s). all_matching=true returns needs_confirmation first; call again with confirmed=true.",
      properties: { title: str, all_matching: { type: "boolean" }, confirmed: { type: "boolean" } },
      required: ["title"],
    },
    create_task: { description: "Add a task.", properties: { title: str }, required: ["title"] },
    get_schedule: { description: "Village events, rota, meals. Not markets.", properties: { range: str, date: str } },
    my_day: { description: "Send the speaker their briefing. Replies itself.", properties: {} },
  };
  return Object.entries(names).map(([name, result]) => ({
    name,
    description: schemas[name]?.description || `Village ${name.replace(/_/g, " ")}`,
    writes: ["rate_market", "create_task", "cancel_event", "notion_write"].includes(name),
    parameters: {
      type: "object",
      properties: schemas[name]?.properties || {},
      ...(schemas[name]?.required ? { required: schemas[name].required } : {}),
    },
    run: async (args) => {
      calls.push({ name, args });
      return overrides[name] || result;
    },
  }));
}

async function offline() {
  // 1. Plain chat: no tools.
  {
    const calls = [];
    const r = await runToolAgent({
      tools: stubTools(calls),
      context: "LATEST MESSAGE:\nhaha did Sam program you to ignore me?",
      chat: scriptedChat([{ content: "Never! Just slow on the uptake sometimes 😅" }]),
    });
    assert.strictEqual(calls.length, 0);
    assert.match(r.reply, /Never/);
    assert.strictEqual(r.rounds, 1);
  }

  // 2. Two ratings in one message → two tool calls, then a reply; links collected; tokens summed.
  {
    const calls = [];
    const r = await runToolAgent({
      tools: stubTools(calls),
      context: "LATEST MESSAGE:\nbarao de Sao Joao: 3 Fatacil: 9",
      chat: scriptedChat([
        {
          content: null,
          tool_calls: [
            toolCall("a", "rate_market", { place: "Barão de São João", score: 3 }),
            toolCall("b", "rate_market", { place: "Fatacil", score: 9 }),
          ],
        },
        (messages) => {
          const toolMsgs = messages.filter((m) => m.role === "tool");
          assert.strictEqual(toolMsgs.length, 2);
          return { content: "Saved both ratings." };
        },
      ]),
    });
    assert.deepStrictEqual(calls.map((c) => c.args.score), [3, 9]);
    assert.strictEqual(r.reply, "Saved both ratings.");
    assert.strictEqual(r.usage.prompt, 200);
    assert.strictEqual(r.trace.filter((t) => t.wrote).length, 2);
  }

  // 3. URLs are given to the bot, not the model.
  {
    const calls = [];
    const r = await runToolAgent({
      tools: stubTools(calls),
      context: "LATEST MESSAGE:\nadd a task to fix the gate",
      chat: scriptedChat([
        { content: null, tool_calls: [toolCall("a", "create_task", { title: "Fix gate" })] },
        (messages) => {
          const tool = messages.find((m) => m.role === "tool");
          assert.ok(!tool.content.includes("notion.so"), "url leaked to model");
          return { content: "Added." };
        },
      ]),
    });
    assert.deepStrictEqual(r.links, [{ title: "Fix gate", url: "https://notion.so/task" }]);
  }

  // 4. Hand-off stops the loop.
  {
    const calls = [];
    const r = await runToolAgent({
      tools: stubTools(calls),
      context: "LATEST MESSAGE:\nwhat's my day",
      chat: scriptedChat([{ content: null, tool_calls: [toolCall("a", "my_day", {})] }]),
    });
    assert.strictEqual(r.handedOff, true);
    assert.strictEqual(r.reply, "");
  }

  // 5. Model error after a write → error returned with the write in the trace.
  {
    const calls = [];
    let n = 0;
    const r = await runToolAgent({
      tools: stubTools(calls),
      context: "LATEST MESSAGE:\nadd a task to fix the gate",
      chat: async () => {
        n += 1;
        if (n === 1) {
          return { message: { content: null, tool_calls: [toolCall("a", "create_task", { title: "Fix gate" })] } };
        }
        throw new Error("boom");
      },
    });
    assert.strictEqual(r.error, "boom");
    assert.strictEqual(r.trace[0].wrote, true);
  }

  // 6. Final round is forced to text (no tools offered).
  {
    const calls = [];
    const seenTools = [];
    const loop = { content: null, tool_calls: [toolCall("x", "notion_search", { database: "Tasks" })] };
    const r = await runToolAgent({
      tools: stubTools(calls),
      context: "LATEST MESSAGE:\nlook around",
      chat: async ({ tools }) => {
        seenTools.push(tools.length);
        return { message: tools.length ? loop : { content: "Here's what I found." } };
      },
    });
    assert.strictEqual(seenTools[seenTools.length - 1], 0);
    assert.strictEqual(r.reply, "Here's what I found.");
  }

  // 7. Unknown tool and thrown tool errors are reported back to the model.
  {
    const tools = [
      { name: "boom", description: "x", run: async () => { throw new Error("kaput"); } },
    ];
    const r = await runToolAgent({
      tools,
      context: "LATEST MESSAGE:\nhi",
      chat: scriptedChat([
        { content: null, tool_calls: [toolCall("a", "boom", {}), toolCall("b", "nope", {})] },
        (messages) => {
          const results = messages.filter((m) => m.role === "tool").map((m) => JSON.parse(m.content));
          assert.match(results[0].error, /kaput/);
          assert.match(results[1].error, /Unknown tool/);
          return { content: "Sorry, that didn't work." };
        },
      ]),
    });
    assert.strictEqual(r.trace.length, 2);
    assert.ok(r.trace.every((t) => !t.ok));
  }

  console.log("vava-tool-agent offline tests OK (7)");
}

const LIVE_CASES = [
  {
    text: "Haha @vila_akoya_bot did Sam program you to ignore me?",
    expect: (calls) => calls.length === 0,
    why: "banter → no tools",
  },
  {
    text: "Hey @vila_akoya_bot, the market in barao de Sao Joao SUCKS. I'll start: barao de Sao Joao: 3 Fatacil: 9",
    expect: (calls) => calls.filter((c) => c.name === "rate_market").length === 2,
    why: "two ratings → rate_market ×2",
  },
  {
    text: "is Loulé market on this Saturday?",
    expect: (calls) => calls.some((c) => c.name === "find_markets"),
    why: "market question → find_markets",
  },
  {
    text: "are you alive?!",
    expect: (calls) => calls.length === 0,
    why: "health ping → no tools",
  },
  {
    text: "cancel all the yoga sessions",
    expect: (calls, r) => calls.some((c) => c.name === "cancel_event") && /yoga/i.test(r.reply),
    why: "multi-cancel → asks to confirm",
  },
];

async function live() {
  if (!openai.isEnabled()) {
    console.log("Skipping live tests — no OPENAI_API_KEY in .env");
    return;
  }
  console.log(`Live tests with ${openai.AGENT_MODEL}…`);
  let failures = 0;
  for (const c of LIVE_CASES) {
    const calls = [];
    const context = [
      "SPEAKER: Sammy (@sammy)",
      'CHAT: group chat "General"',
      "TODAY: 2026-10-25 (Sunday), Europe/Lisbon",
      `LATEST MESSAGE:\n${c.text}`,
    ].join("\n\n");
    const r = await runToolAgent({ tools: stubTools(calls), context });
    const pass = !r.error && (Boolean(r.reply) || r.handedOff) && c.expect(calls, r);
    if (!pass) failures += 1;
    console.log(
      `${pass ? "PASS" : "FAIL"}  ${c.why}\n      tools: ${calls.map((x) => x.name).join(", ") || "none"}` +
        `\n      reply: ${(r.reply || (r.handedOff ? "(handed off)" : r.error || "")).slice(0, 160)}` +
        `\n      tokens: in ${r.usage.prompt} (cached ${r.usage.cached}), out ${r.usage.completion}, rounds ${r.rounds}`
    );
  }
  if (failures) process.exitCode = 1;
}

(async () => {
  await offline();
  if (process.argv.includes("--live")) await live();
})().catch((err) => {
  console.error(err);
  process.exit(1);
});
