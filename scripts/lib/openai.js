/**
 * Tiny zero-dependency OpenAI helper for the Vila Akoya tools.
 *
 * Provides:
 *   - embed(texts)         -> array of embedding vectors
 *   - embedOne(text)       -> single embedding vector
 *   - classifyTask(text)   -> structured task fields (for smart intake)
 *   - transcribeAudio(buf) -> speech-to-text (voice notes)
 *   - answerFromDocs(...)  -> grounded answer over retrieved doc chunks
 *
 * Requires OPENAI_API_KEY in the environment. If it is missing, isEnabled()
 * returns false and callers should degrade gracefully.
 */

"use strict";

require("./load-env");
const { withVavaVoice } = require("./vava-voice");

const OPENAI_API_KEY = process.env.OPENAI_API_KEY || "";
const OPENAI_BASE = process.env.OPENAI_BASE_URL || "https://api.openai.com/v1";
// gpt-4o: stronger routing and replies for messy village chat (override via OPENAI_CHAT_MODEL).
const CHAT_MODEL = process.env.OPENAI_CHAT_MODEL || "gpt-4o";
// Natural replies (can match CHAT_MODEL; keep a separate knob if classification stays cheap).
const CONVERSE_MODEL = process.env.OPENAI_CONVERSE_MODEL || CHAT_MODEL;
// Vision-capable model for receipt photos / PDFs.
const VISION_MODEL = process.env.OPENAI_VISION_MODEL || "gpt-4o";
const EMBED_MODEL = process.env.OPENAI_EMBED_MODEL || "text-embedding-3-small";
const TRANSCRIBE_MODEL =
  process.env.OPENAI_TRANSCRIBE_MODEL || "gpt-4o-mini-transcribe";
// Tool-calling agent for tagged messages and DMs (scripts/lib/vava-tool-agent.js).
const AGENT_MODEL = process.env.OPENAI_AGENT_MODEL || "gpt-6.1-sol";
const AGENT_API = (process.env.OPENAI_AGENT_API || "responses").toLowerCase();
const AGENT_REASONING = process.env.OPENAI_AGENT_REASONING ?? "low";

function isEnabled() {
  return Boolean(OPENAI_API_KEY);
}

async function openai(path, body) {
  if (!OPENAI_API_KEY) throw new Error("OPENAI_API_KEY is not set.");
  const res = await fetch(`${OPENAI_BASE}${path}`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${OPENAI_API_KEY}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify(body),
  });
  const json = await res.json();
  if (!res.ok) {
    throw new Error(
      `OpenAI ${path} failed: ${json.error?.message || res.status}`
    );
  }
  return json;
}

async function embed(texts) {
  const input = Array.isArray(texts) ? texts : [texts];
  const json = await openai("/embeddings", { model: EMBED_MODEL, input });
  return json.data
    .sort((a, b) => a.index - b.index)
    .map((d) => d.embedding);
}

async function embedOne(text) {
  const [vec] = await embed([text]);
  return vec;
}

async function chatJSON(system, user) {
  return chatJSONMessages(system, user, { model: CHAT_MODEL });
}

async function chatJSONMessages(system, userContent, { model, temperature } = {}) {
  const json = await openai("/chat/completions", {
    model: model || CHAT_MODEL,
    temperature: temperature ?? 0,
    response_format: { type: "json_object" },
    messages: [
      { role: "system", content: system },
      { role: "user", content: userContent },
    ],
  });
  const content = json.choices?.[0]?.message?.content || "{}";
  try {
    return JSON.parse(content);
  } catch {
    return {};
  }
}

/** One chat turn with function tools. Returns the assistant message and token usage. */
/**
 * One model turn with function tools. Takes/returns Chat Completions-shaped messages
 * ({role, content, tool_calls} / {role:"tool", tool_call_id, content}) whichever API is used.
 * Default API is /responses (reasoning models only support tools there); the assistant
 * message carries `_items` (raw output incl. encrypted reasoning) to replay next round.
 */
async function chatWithTools({ messages, tools, model } = {}) {
  const useModel = model || AGENT_MODEL;
  if (AGENT_API === "chat") {
    const body = {
      model: useModel,
      messages: messages.map(({ _items, ...m }) => m),
    };
    if (tools?.length) {
      body.tools = tools;
      body.tool_choice = "auto";
    }
    const json = await openai("/chat/completions", body);
    return {
      message: json.choices?.[0]?.message || { role: "assistant", content: "" },
      usage: json.usage || null,
      model: json.model || body.model,
    };
  }

  const instructions = messages
    .filter((m) => m.role === "system")
    .map((m) => m.content)
    .join("\n\n");
  const input = [];
  for (const m of messages) {
    if (m.role === "system") continue;
    if (m.role === "tool") {
      input.push({ type: "function_call_output", call_id: m.tool_call_id, output: String(m.content ?? "") });
    } else if (m.role === "assistant" && Array.isArray(m._items)) {
      input.push(...m._items);
    } else if (m.role === "assistant") {
      if (m.content) input.push({ role: "assistant", content: m.content });
      for (const call of m.tool_calls || []) {
        input.push({
          type: "function_call",
          call_id: call.id,
          name: call.function?.name,
          arguments: call.function?.arguments || "{}",
        });
      }
    } else {
      input.push({ role: m.role, content: String(m.content ?? "") });
    }
  }

  const body = {
    model: useModel,
    instructions,
    input,
    store: false,
    include: ["reasoning.encrypted_content"],
  };
  if (AGENT_REASONING) body.reasoning = { effort: AGENT_REASONING };
  if (tools?.length) {
    body.tools = tools.map((t) => ({
      type: "function",
      name: t.function.name,
      description: t.function.description,
      parameters: t.function.parameters,
    }));
    body.tool_choice = "auto";
  }

  const json = await openai("/responses", body);
  const output = Array.isArray(json.output) ? json.output : [];
  const text = output
    .filter((item) => item.type === "message")
    .flatMap((item) => item.content || [])
    .filter((c) => c.type === "output_text")
    .map((c) => c.text)
    .join("")
    .trim();
  const toolCalls = output
    .filter((item) => item.type === "function_call")
    .map((item) => ({
      id: item.call_id,
      type: "function",
      function: { name: item.name, arguments: item.arguments || "{}" },
    }));
  const u = json.usage || {};
  return {
    message: {
      role: "assistant",
      content: text,
      ...(toolCalls.length ? { tool_calls: toolCalls } : {}),
      _items: output,
    },
    usage: {
      prompt_tokens: u.input_tokens || 0,
      completion_tokens: u.output_tokens || 0,
      prompt_tokens_details: { cached_tokens: u.input_tokens_details?.cached_tokens || 0 },
    },
    model: json.model || useModel,
  };
}

// ── Smart task intake ────────────────────────────────────────────────────
const AREAS = [
  "Water",
  "Compost & toilets",
  "Solar / electric",
  "Buildings / structures",
  "Tools / workshop",
  "Land / roads / drainage",
  "Campsite / guest",
  "Garden",
  "Community / events",
  "Admin / money",
  "Other",
];

const PRIORITIES = ["Low", "Average", "High"];

const SUGGESTED_DATABASES = [
  "Tasks",
  "Jobs",
  "Internal Events",
  "External Events",
  "Reminders",
  "Projects",
  "Partnerships",
  "Members",
  "Documentation",
];

/**
 * Turn a free-text brain dump into structured task fields.
 * Returns null if OpenAI is disabled or the call fails (caller falls back).
 */
async function classifyTask(text) {
  if (!isEnabled()) return null;

  const system = [
    "You triage messages for Vila Akoya, a campsite that is also a small",
    "community (permanent villagers + paying campsite guests).",
    "Turn each message into structured JSON for a task board.",
    "Be concise and practical. Only mark safety_critical when there is a real",
    "risk to people (fire, electrical, water contamination, structural, gas).",
    "",
    `area must be one of: ${AREAS.join(", ")}.`,
    `priority must be one of: ${PRIORITIES.join(", ")}.`,
    `suggested_board must be one of: ${SUGGESTED_DATABASES.join(", ")}.`,
    "",
    "Return JSON with keys:",
    "title (short imperative task title, max 80 chars),",
    "summary (one or two sentences),",
    "area, priority, suggested_board,",
    "safety_critical (bool), needs_materials (bool), needs_money (bool),",
    "is_documentation_request (bool: true if they are asking to document",
    "something or asking how something works).",
  ].join("\n");

  try {
    const result = await chatJSON(system, text);
    return {
      title: String(result.title || "").slice(0, 80) || null,
      summary: String(result.summary || ""),
      area: AREAS.includes(result.area) ? result.area : "Other",
      priority: PRIORITIES.includes(result.priority) ? result.priority : "Average",
      suggested_board: SUGGESTED_DATABASES.includes(result.suggested_board)
        ? result.suggested_board
        : "Tasks",
      safety_critical: Boolean(result.safety_critical),
      needs_materials: Boolean(result.needs_materials),
      needs_money: Boolean(result.needs_money),
      is_documentation_request: Boolean(result.is_documentation_request),
    };
  } catch (err) {
    console.error("classifyTask failed:", err.message);
    return null;
  }
}

// ── Grounded documentation answers ──────────────────────────────────────────
/**
 * Answer a question strictly from the provided doc chunks.
 * contexts: [{ docName, url, text }]
 * Returns { answered: bool, answer: string, sources: [docName] }.
 */
async function answerFromDocs(question, contexts) {
  if (!isEnabled()) {
    return { answered: false, answer: "", sources: [] };
  }

  const context = contexts
    .map(
      (c, i) =>
        `[Doc ${i + 1}: ${c.docName}${c.url ? ` (${c.url})` : ""}]\n${c.text}`
    )
    .join("\n\n---\n\n");

  const system = withVavaVoice(
    [
      "Answer using the documentation excerpts provided — rephrase them as if you live on site, in VAVA's casual friendly voice.",
      "Combine related excerpts. If incomplete, answer what you can and say what's missing.",
      "Set answered=false only when excerpts are irrelevant or you'd invent village-specific facts.",
      "Keep answers short (a few sentences).",
      "",
      'Return JSON: { "answered": bool, "answer": string, "sources": [doc names used] }.',
    ].join("\n")
  );

  const user = `Question: ${question}\n\nDocumentation:\n${context || "(none)"}`;

  try {
    const result = await chatJSON(system, user);
    return {
      answered: Boolean(result.answered),
      answer: String(result.answer || ""),
      sources: Array.isArray(result.sources) ? result.sources : [],
    };
  } catch (err) {
    console.error("answerFromDocs failed:", err.message);
    return { answered: false, answer: "", sources: [] };
  }
}

const {
  buildClassifierPrompt,
  parseClassification,
  EVENT_TYPES,
  EXPENSE_CATEGORIES,
} = require("./community-actions");

/**
 * Classify a community group message into an action + Notion routing hints.
 */
async function classifyGroupMessage(text, todayISO) {
  if (!isEnabled()) {
    return { action: "chat", is_question: false, is_task: false, is_event: false, is_complete: false, is_cancel_event: false, confidence: 0 };
  }

  const system = buildClassifierPrompt({
    todayISO,
    areas: AREAS,
    eventTypes: EVENT_TYPES,
    priorities: PRIORITIES,
  });

  try {
    const result = await chatJSON(system, text);
    return parseClassification(result, {
      areas: AREAS,
      eventTypes: EVENT_TYPES,
      priorities: PRIORITIES,
    });
  } catch (err) {
    console.error("classifyGroupMessage failed:", err.message);
    return {
      action: "chat",
      is_question: false,
      is_task: false,
      is_event: false,
      is_complete: false,
      is_cancel_event: false,
      confidence: 0,
    };
  }
}

/**
 * Classify an addressed message (tagged bot or DM).
 */
async function classifyIntent(text, todayISO) {
  if (!isEnabled()) return { intent: "general" };

  const system = [
    "You route messages to the Vila Akoya community bot.",
    `Today's date is ${todayISO}.`,
    "",
    "Pick ONE intent from meaning, not exact wording. Typos and paraphrases are fine.",
    "- doc_question: asking how something works, procedures (water, compost, etc.)",
    "- data_question: schedule/rota, who is on duty, what's happening today/this week",
    "- create_task: wants to log a job that needs doing (including 'the tap is dripping', 'can you put this on the board')",
    "- create_event: wants to log something happening on a date",
    "- general: greeting, thanks, unclear, or not actionable — still reply helpfully in summary",
    "",
    `For create_task: title, area (${AREAS.join(", ")}), priority (${PRIORITIES.join(", ")}).`,
    `For create_event: title, date (YYYY-MM-DD), event_type (${EVENT_TYPES.join(", ")}).`,
    "",
    'Return JSON: { "intent": string, "title": string, "area": string, "priority": string, "date": string, "event_type": string, "summary": string }',
  ].join("\n");

  try {
    const result = await chatJSON(system, text);
    const intent = [
      "doc_question",
      "data_question",
      "create_task",
      "create_event",
      "general",
    ].includes(result.intent)
      ? result.intent
      : "general";
    return {
      intent,
      title: String(result.title || "").slice(0, 80),
      area: AREAS.includes(result.area) ? result.area : "Other",
      priority: PRIORITIES.includes(result.priority) ? result.priority : "Average",
      date: String(result.date || "").slice(0, 10),
      event_type: EVENT_TYPES.includes(result.event_type) ? result.event_type : "Other",
      summary: String(result.summary || ""),
    };
  } catch (err) {
    console.error("classifyIntent failed:", err.message);
    return { intent: "general" };
  }
}

async function answerFromData(question, snapshot) {
  if (!isEnabled()) return { answered: false, answer: "" };

  const system = withVavaVoice(
    [
      "Answer using the schedule data provided (chores, events, campsite bookings, meals).",
      "Warm and conversational — not a database dump. Still include the facts.",
      "WEEK CALENDAR: this week = Mon–Sun containing today; next week = the following Mon–Sun.",
      "Only mention items whose dates fall in the range they asked about.",
      "Market / mercado / feira questions: use the Markets section (flea vs farmers & gypsy lists) and Events — ignore chores unless they asked for the rota.",
      "If they ask whether a named market runs on a date, answer yes or no from Markets; if not that day but recurrence is listed, say when it usually runs.",
      "If venue/location is listed for a market, include it when they ask where it is.",
      "Set answered=true when Events data is enough to answer, even when chores are irrelevant.",
      "Check-ins, meals, and headcounts come from website bookings.",
      "If the answer is not in the data, set answered=false and do not guess.",
      "If the question is about the bot, being ignored, jokes, or small talk — set answered=false (do not use schedule data).",
      "Only mention markets when they asked about markets, mercados, feiras, or what is on today/this week in that sense.",
      "Never list bot commands or everything you can do — answer the question only.",
      'Return JSON: { "answered": bool, "answer": string }.',
    ].join("\n")
  );

  try {
    const result = await chatJSON(system, `Question: ${question}\n\nData:\n${snapshot}`);
    return {
      answered: Boolean(result.answered),
      answer: String(result.answer || ""),
    };
  } catch (err) {
    console.error("answerFromData failed:", err.message);
    return { answered: false, answer: "" };
  }
}

async function composeRecap(templateText) {
  if (!isEnabled()) return templateText;
  try {
    const result = await chatJSON(
      "Rewrite this daily community recap to be warm and concise. Keep all facts that are present in the source text only — do not add events, markets, workshops, or meals that are not listed. Do not mention missing or zero items. Never mention campsite check-ins, check-outs, pitch bookings, or who is staying on site. Meal bookings come from the website. Morning village meetings are Mon–Fri only: if the source has no Morning meeting section, do not mention morning meetings. If the source has no 'Community cooking today' section, do not mention anyone cooking or community meals at all. Never use placeholder text like [Name] or [Dish]. If 'Community cooking today' is present, keep those bullet facts exactly (cook, dish, diet, price, time). Return JSON: { \"text\": string }.",
      templateText
    );
    return String(result.text || templateText);
  } catch {
    return templateText;
  }
}

/**
 * Pick only community-relevant messages from a day's group chat for the morning recap.
 * Returns [] when nothing mattered; null on API failure (caller should fall back).
 */
function normalizeTranscriptionFilename(filename) {
  const name =
    String(filename || "audio.ogg")
      .replace(/\\/g, "/")
      .split("/")
      .pop() || "audio.ogg";
  const lower = name.toLowerCase();
  // Telegram voice notes use .oga (Ogg Opus); OpenAI accepts .ogg not .oga.
  if (lower.endsWith(".oga")) return `${name.slice(0, -4)}.ogg`;
  if (/\.(flac|m4a|mp3|mp4|mpeg|mpga|ogg|wav|webm)$/i.test(name)) return name;
  const dot = name.lastIndexOf(".");
  return dot > 0 ? `${name.slice(0, dot)}.ogg` : `${name}.ogg`;
}

async function structureDocumentation(rawText, { proposer, todayISO }) {
  if (!isEnabled()) return null;

  const system = [
    "You turn rough spoken or written notes into clear village/campsite documentation for Vila Akoya.",
    "The input may be messy, repetitive, incomplete or stream-of-consciousness — reorganise it",
    "into proper documentation that a steward or guest could actually use.",
    "",
    `Today's date: ${todayISO || "unknown"}.`,
    proposer ? `Captured by: ${proposer}.` : "",
    "",
    `area must be one of: ${AREAS.join(", ")}.`,
    "",
    "Return JSON:",
    '{ "title": string (clear doc title, max 80 chars),',
    '  "area": string,',
    '  "summary": string (1-2 sentences for a documentation register),',
    '  "markdown": string (full document in markdown) }',
    "",
    "Markdown structure — use sections that fit the material; skip empty ones:",
    "# Title",
    "## Overview",
    "## How it works",
    "## Daily use / Procedure",
    "## Troubleshooting",
    "## Safety notes",
    "## Who to contact",
    "",
    "Use short paragraphs and bullet lists. Write for residents and campsite stewards.",
    "Do NOT invent technical facts absent from the source. Mark gaps as TBC or Needs verification.",
    "If the source is very thin, still produce a sensible skeleton with TBC placeholders.",
  ]
    .filter(Boolean)
    .join("\n");

  try {
    const result = await chatJSON(system, rawText);
    const markdown = String(result.markdown || "").trim();
    if (!markdown) return null;
    return {
      title: String(result.title || "Untitled documentation").slice(0, 80),
      area: AREAS.includes(result.area) ? result.area : "Other",
      summary: String(result.summary || "").slice(0, 300),
      markdown,
    };
  } catch (err) {
    console.error("structureDocumentation failed:", err.message);
    return null;
  }
}

const RECEIPT_CATEGORIES = EXPENSE_CATEGORIES || [
  "Food / groceries",
  "Hardware",
  "Fuel",
  "Transport",
  "Utilities",
  "Maintenance",
  "Supplies",
  "Guest / campsite",
  "Other",
];

/**
 * Extract structured expense fields from a receipt photo, PDF, and/or pasted text.
 * Returns null if OpenAI is disabled or the call fails.
 */
async function extractReceipt({
  text,
  imageBase64,
  imageMime,
  pdfBase64,
  filename,
  todayISO,
  submitter,
} = {}) {
  if (!isEnabled()) return null;

  const hasImage = Boolean(imageBase64);
  const hasPdf = Boolean(pdfBase64);
  const hasText = Boolean(String(text || "").trim());
  if (!hasImage && !hasPdf && !hasText) return null;

  const system = [
    "You extract village expense receipts for Vila Akoya (Portugal).",
    "Input may be a photo of a till receipt / factura / talão, a PDF invoice, and/or pasted text.",
    "Portuguese, English, and mixed receipts are common. IVA = VAT.",
    `Today is ${todayISO || "unknown"}.`,
    submitter ? `Submitted by: ${submitter}.` : "",
    "",
    "If this is clearly NOT a receipt, invoice, or expense (e.g. a photo of a leak, a landscape, a meme),",
    "set is_receipt=false and leave other fields empty.",
    "",
    `category must be one of: ${RECEIPT_CATEGORIES.join(", ")}.`,
    "currency: EUR unless the receipt clearly uses another (GBP, USD). Default EUR for Portugal.",
    "amount is the TOTAL to pay (gross), as a number. Use a dot decimal (12.40 not 12,40).",
    "vat is IVA / VAT amount if printed, else null.",
    "date is YYYY-MM-DD from the receipt; if missing, use today.",
    "merchant is the shop / supplier name.",
    "title is a short label, e.g. \"Continente groceries\" (max 80 chars).",
    "line_items: short list of notable items if readable, else [].",
    "receipt_text: a faithful transcription of the useful receipt text (merchant, date, totals, IVA).",
    "",
    "Return JSON:",
    '{ "is_receipt": bool, "confidence": 0.0-1.0, "title": string, "merchant": string,',
    '  "amount": number | null, "currency": "EUR"|"GBP"|"USD"|"Other",',
    '  "date": "YYYY-MM-DD", "category": string, "vat": number | null,',
    '  "notes": string, "line_items": string[], "receipt_text": string }',
  ]
    .filter(Boolean)
    .join("\n");

  const userParts = [];
  const intro = [
    hasText ? `Caption / pasted text:\n${String(text).slice(0, 4000)}` : "",
    hasImage ? "A receipt image is attached. Extract the totals and merchant." : "",
    hasPdf ? `A PDF (${filename || "receipt.pdf"}) is attached.` : "",
  ]
    .filter(Boolean)
    .join("\n\n") || "Extract the receipt.";
  userParts.push({ type: "text", text: intro });

  if (hasImage) {
    const mime = imageMime && String(imageMime).startsWith("image/")
      ? imageMime
      : "image/jpeg";
    userParts.push({
      type: "image_url",
      image_url: { url: `data:${mime};base64,${imageBase64}` },
    });
  }
  if (hasPdf) {
    userParts.push({
      type: "file",
      file: {
        filename: filename || "receipt.pdf",
        file_data: `data:application/pdf;base64,${pdfBase64}`,
      },
    });
  }

  const userContent =
    hasImage || hasPdf ? userParts : intro;

  try {
    const result = await chatJSONMessages(system, userContent, {
      model: hasImage || hasPdf ? VISION_MODEL : CHAT_MODEL,
    });
    const amount = Number(result.amount);
    const vat = Number(result.vat);
    const currency = String(result.currency || "EUR").toUpperCase();
    return {
      is_receipt: result.is_receipt !== false,
      confidence: Number(result.confidence) || 0,
      title: String(result.title || result.merchant || "Receipt").slice(0, 80),
      merchant: String(result.merchant || "").slice(0, 80),
      amount: Number.isFinite(amount) ? amount : null,
      currency: ["EUR", "GBP", "USD", "Other"].includes(currency)
        ? currency
        : "EUR",
      date: /^\d{4}-\d{2}-\d{2}$/.test(String(result.date || ""))
        ? String(result.date).slice(0, 10)
        : todayISO || null,
      category: RECEIPT_CATEGORIES.includes(result.category)
        ? result.category
        : "Other",
      vat: Number.isFinite(vat) ? vat : null,
      notes: String(result.notes || "").slice(0, 500),
      line_items: Array.isArray(result.line_items)
        ? result.line_items.map((x) => String(x).slice(0, 120)).filter(Boolean).slice(0, 20)
        : [],
      receipt_text: String(result.receipt_text || text || "").slice(0, 1900),
    };
  } catch (err) {
    console.error("extractReceipt failed:", err.message);
    return null;
  }
}

async function transcribeAudio(buf, filename) {
  if (!OPENAI_API_KEY) throw new Error("OPENAI_API_KEY is not set.");

  const uploadName = normalizeTranscriptionFilename(filename);
  const form = new FormData();
  form.append("file", new Blob([buf]), uploadName);
  form.append("model", TRANSCRIBE_MODEL);

  const res = await fetch(`${OPENAI_BASE}/audio/transcriptions`, {
    method: "POST",
    headers: { Authorization: `Bearer ${OPENAI_API_KEY}` },
    body: form,
  });
  const json = await res.json();
  if (!res.ok) {
    throw new Error(
      `OpenAI /audio/transcriptions failed: ${json.error?.message || res.status}`
    );
  }
  return String(json.text || "").trim();
}

/**
 * Interpret a follow-up when VAVA asked villager/management + priority.
 * Understands paraphrases: "anyone can do it", "needs a plumber", "it's urgent".
 */
async function interpretTaskClarification(text) {
  if (!isEnabled()) return { type: null, priority: null, cancel: false };

  const system = [
    "The user is answering how to file a village task.",
    "type: Villager = anyone on site can pitch in; Management = needs a pro or arranging.",
    "priority: Low | Average | High.",
    "cancel=true if they don't want the task logged (never mind, wait, cancel, forget it).",
    "Infer from meaning, not exact words. 'anyone can do it' → Villager. 'needs a plumber' → Management.",
    "'no rush' → Low. 'urgent/asap/safety' → High.",
    "If they only answered one of type/priority, leave the other null.",
    'Return JSON: { "type": "Villager"|"Management"|null, "priority": "Low"|"Average"|"High"|null, "cancel": bool }',
  ].join("\n");

  try {
    const result = await chatJSON(system, text);
    const type = result.type === "Villager" || result.type === "Management" ? result.type : null;
    const priority = ["Low", "Average", "High"].includes(result.priority)
      ? result.priority
      : null;
    return { type, priority, cancel: Boolean(result.cancel) };
  } catch (err) {
    console.error("interpretTaskClarification failed:", err.message);
    return { type: null, priority: null, cancel: false };
  }
}

/**
 * Natural reply when classification is fuzzy, or docs/schedule didn't have an answer.
 * Must not invent village-specific facts that aren't in `facts`.
 */
async function converseAsVava({
  text,
  recent = [],
  classified = null,
  situation = "",
  facts = "",
  todayISO: today = "",
} = {}) {
  if (!isEnabled()) return "";

  const system = withVavaVoice(
    [
      today ? `Today is ${today}.` : "",
      "You can log jobs, duties, events, reminders, RSVPs, receipts; remember where things are;",
      "answer from docs and the schedule; note childcare absence; point to dinner/childcare booking.",
      "You cannot delete or archive Notion pages — only add or edit.",
      "",
      "Do NOT invent village facts unless they appear under FACTS.",
      "Never require slash commands. Do not list bot capabilities or /commands unless they explicitly asked what you can do.",
      "1–5 sentences in VAVA's casual friendly voice — never a dry system notice.",
      'Return JSON: { "reply": string }',
    ]
      .filter(Boolean)
      .join("\n")
  );

  const user = [
    facts ? `FACTS (trust these):\n${String(facts).slice(0, 2500)}` : "",
    situation ? `SITUATION:\n${situation}` : "",
    classified
      ? `CLASSIFIER GUESS: action=${classified.action || "chat"} confidence=${classified.confidence ?? "?"}`
      : "",
    recent.length
      ? `RECENT MESSAGES:\n${recent
          .slice(-8)
          .map((line) => `- ${line}`)
          .join("\n")}`
      : "",
    `LATEST:\n${text}`,
  ]
    .filter(Boolean)
    .join("\n\n");

  try {
    const result = await chatJSONMessages(system, user, {
      model: CONVERSE_MODEL,
      temperature: 0.9,
    });
    return String(result.reply || "").trim().slice(0, 1200);
  } catch (err) {
    console.error("converseAsVava failed:", err.message);
    return "";
  }
}

async function summarizeImportantChat(messages, dateISO) {
  if (!isEnabled() || !messages?.length) return [];

  const system = [
    "You summarise yesterday's village group chat for a morning recap.",
    "Include ONLY messages that matter for running the community or campsite:",
    "- events or gatherings proposed or discussed",
    "- repairs, broken things, leaks, or things not working",
    "- cleaning or facilities issues (e.g. toilets need cleaning)",
    "- guest or campsite problems",
    "- safety issues",
    "- tasks marked done or events cancelled",
    "- community decisions or proposals",
    "EXCLUDE casual chat, greetings, jokes, weather small-talk, vague availability,",
    "general offers to help without a specific task, and anything not actionable.",
    "If nothing important happened, return an empty highlights array.",
    "Write each highlight as one concise sentence in past tense, naming who said it.",
    'Return JSON: { "highlights": string[] }.',
  ].join("\n");

  const body = messages.map((m) => `- ${m.proposer}: ${m.text}`).join("\n");

  try {
    const result = await chatJSON(system, `Date: ${dateISO}\n\nMessages:\n${body}`);
    if (!Array.isArray(result.highlights)) return [];
    return result.highlights.map((h) => String(h).trim()).filter(Boolean);
  } catch (err) {
    console.error("summarizeImportantChat failed:", err.message);
    return null;
  }
}

module.exports = {
  isEnabled,
  embed,
  embedOne,
  completeJSON: chatJSONMessages,
  chatWithTools,
  AGENT_MODEL,
  classifyTask,
  classifyGroupMessage,
  classifyIntent,
  answerFromDocs,
  answerFromData,
  composeRecap,
  summarizeImportantChat,
  converseAsVava,
  interpretTaskClarification,
  structureDocumentation,
  extractReceipt,
  transcribeAudio,
  AREAS,
  EVENT_TYPES,
  EMBED_MODEL,
  CHAT_MODEL,
  CONVERSE_MODEL,
  VISION_MODEL,
  TRANSCRIBE_MODEL,
};
