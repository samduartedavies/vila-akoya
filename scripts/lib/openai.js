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

const OPENAI_API_KEY = process.env.OPENAI_API_KEY || "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";
const OPENAI_BASE = process.env.OPENAI_BASE_URL || "https://api.openai.com/v1";
const CHAT_MODEL = process.env.OPENAI_CHAT_MODEL || "gpt-4o-mini";
const EMBED_MODEL = process.env.OPENAI_EMBED_MODEL || "text-embedding-3-small";
const TRANSCRIBE_MODEL =
  process.env.OPENAI_TRANSCRIBE_MODEL || "gpt-4o-mini-transcribe";

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
  const json = await openai("/chat/completions", {
    model: CHAT_MODEL,
    temperature: 0,
    response_format: { type: "json_object" },
    messages: [
      { role: "system", content: system },
      { role: "user", content: user },
    ],
  });
  const content = json.choices?.[0]?.message?.content || "{}";
  try {
    return JSON.parse(content);
  } catch {
    return {};
  }
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

const PRIORITIES = ["Low", "Medium", "High", "Urgent"];

const SUGGESTED_DATABASES = [
  "Tasks",
  "Recurring Duties",
  "Maintenance",
  "Guest Issues",
  "Projects",
  "Events",
  "Decisions",
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
      priority: PRIORITIES.includes(result.priority) ? result.priority : "Medium",
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

  const system = [
    "You are the Vila Akoya documentation assistant.",
    "Answer ONLY using the documentation excerpts provided.",
    "If the answer is not clearly contained in them, set answered=false and",
    "do not guess. Keep answers short, friendly and practical, for people",
    "living at or visiting a campsite/village.",
    "",
    'Return JSON: { "answered": bool, "answer": string, "sources": [doc names used] }.',
  ].join("\n");

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
    "Pick ONE intent:",
    "- doc_question: asking how something works, procedures (water, compost, etc.)",
    "- data_question: schedule/rota, who is on duty, what's happening today/this week",
    "- create_task: wants to log a job that needs doing",
    "- create_event: wants to log something happening on a date",
    "- general: greeting, thanks, unclear, or not actionable",
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
      priority: PRIORITIES.includes(result.priority) ? result.priority : "Medium",
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

  const system = [
    "You are the Vila Akoya community assistant.",
    "Answer ONLY using the schedule data provided (chores, events, campsite bookings, meals).",
    "The data includes a WEEK CALENDAR: this week is always Monday–Sunday containing today;",
    "next week is always the Monday–Sunday period after this Sunday.",
    "Only mention events/bookings/meals whose dates fall inside the range the user asked about.",
    "Campsite check-ins, check-outs, who is on site, breakfast/dinner cook counts come from Notion.",
    "If the answer is not in the data, set answered=false and do not guess.",
    "Keep answers short, friendly and practical.",
    'Return JSON: { "answered": bool, "answer": string }.',
  ].join("\n");

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
      "Rewrite this daily community recap to be warm and concise. Keep all facts. Return JSON: { \"text\": string }.",
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
  classifyTask,
  classifyGroupMessage,
  classifyIntent,
  answerFromDocs,
  answerFromData,
  composeRecap,
  summarizeImportantChat,
  structureDocumentation,
  transcribeAudio,
  AREAS,
  EVENT_TYPES,
  EMBED_MODEL,
  CHAT_MODEL,
  TRANSCRIBE_MODEL,
};
