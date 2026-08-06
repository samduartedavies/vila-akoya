/**
 * Vila Akoya — Telegram <-> Notion bridge
 *
 * Two-way community bot with ZERO npm dependencies (Node 18+ only).
 * It uses Telegram long-polling, so it needs NO public URL / webhook and can
 * run for free on a laptop, Raspberry Pi, home server or any always-on box.
 *
 * ── What it does ──────────────────────────────────────────────────────────
 * Telegram -> Notion
 *   - In groups: VAVA only acts when @tagged, replied-to, or addressed by name in voice
 *     ("Vava", or at the start: "robot", "bot", "vila akoya bot", "personal assistant").
 *     All group messages + voice transcriptions are still buffered for context.
 *   - In private DMs: always on, no tag needed.
 *   - Voice notes are transcribed; documentation dumps become structured Notion pages.
 *   - AI classification routes to Notion actions: tasks, events (internal/external),
 *     reminders, RSVP, notes on projects, completions, cancellations, decisions, docs.
 *   - Daily recap (~08:00 Europe/Lisbon): community recap in the group (events,
 *     campsite, meals, highlights); personal duties + tasks go by DM to registered members.
 *   - /ask, /task, /register, /mytasks, /done, /help as before.
 *   - Schedule and partnership/discount questions answered from Notion.
 *
 * Requires (for smart features): OPENAI_API_KEY, and a docs index built
 * with `node scripts/index-vila-akoya-docs.js`.
 * Voice notes use OPENAI_TRANSCRIBE_MODEL (default gpt-4o-mini-transcribe).
 *
 * Notion -> Telegram (posted to one group chat)
 *   - New pages, completions, status changes on watched databases.
 *
 * ── Setup ─────────────────────────────────────────────────────────────────
 * 1. Run setup-vila-akoya-notion.js first so the databases exist.
 * 2. Create a bot with @BotFather, add to your community group.
 * 3. Run: npm run telegram:bot
 *
 * ── Optional env ──────────────────────────────────────────────────────────
 *   TASK_GROUP_CHAT_ID      group for intake (default: TELEGRAM_CHAT_ID)
 *   AMBIENT_CAPTURE         "true" to enable passive task/event capture (OFF by default)
 *   AMBIENT_MIN_CONFIDENCE  0–1 threshold for task capture (default 0.7)
 *   LOG_DOC_GAPS            "true" to log documentation gaps on failed /ask
 *   DAILY_RECAP             "false" to disable morning recap
 *   RECAP_TIME              HH:MM in RECAP_TZ (default "08:00")
 *   RECAP_TZ                default "Europe/Lisbon"
 *   EVENT_CAPTURE_GROUP     Notion Stage for new events (default "Upcoming")
 *   EVENT_RECURRING_GROUP   Notion Stage for repeating events (default "Recurring")
 *   NOTION_POLL_SECONDS     how often to poll Notion for changes (default 60)
 */

"use strict";

const fs = require("fs");
const path = require("path");
const notion = require("./lib/notion");
const openaiLib = require("./lib/openai");
const docsIndex = require("./lib/docs-index");
const {
  CHAT_ACTIVITY_KIND,
  buildWeekCalendarContext,
  resolveScheduleRange,
} = require("./lib/community-actions");
const {
  parseRecurrence,
  occursOn,
  expandBetween,
  nextOccurrence,
  describeRecurrence,
  normalizeRecurrenceField,
} = require("./lib/event-recurrence");
const { expandRecurringEvents } = require("./expand-notion-recurring-events");
const {
  nextDueAfterCompletion,
  isDutyDueOn,
  appendCompletionLog,
  nameMatchesPerson,
} = require("./lib/duty-schedule");

// ── Config ──────────────────────────────────────────────────────────────────
const TELEGRAM_BOT_TOKEN = process.env.TELEGRAM_BOT_TOKEN || "8991030236:AAEbLuvsa5HRn2rMwCDu8468kAKERo9DhxY";
const TELEGRAM_CHAT_ID = process.env.TELEGRAM_CHAT_ID || "-1003979087550";

const TASK_GROUP_CHAT_ID =
  process.env.TASK_GROUP_CHAT_ID || TELEGRAM_CHAT_ID;
const ALLOW_DM_INTAKE = process.env.ALLOW_DM_INTAKE !== "false";

const TELEGRAM_API = `https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}`;
const TELEGRAM_FILE_API = `https://api.telegram.org/file/bot${TELEGRAM_BOT_TOKEN}`;

// ── Notion database names (env overrides, defaulting to notion.DB.*) ──────────
const TASKS_DB_NAME = process.env.TASKS_DB_NAME || notion.DB.TASKS;
const MEMBERS_DB_NAME = process.env.MEMBERS_DB_NAME || notion.DB.MEMBERS;
const INTERNAL_EVENTS_DB_NAME = process.env.INTERNAL_EVENTS_DB_NAME || notion.DB.INTERNAL_EVENTS;
const EXTERNAL_EVENTS_DB_NAME = process.env.EXTERNAL_EVENTS_DB_NAME || notion.DB.EXTERNAL_EVENTS;
const EVENTS_DB_NAME = EXTERNAL_EVENTS_DB_NAME; // legacy alias
const RECURRING_DB_NAME = process.env.RECURRING_DB_NAME || notion.DB.RECURRING;
const REMINDERS_DB_NAME = process.env.REMINDERS_DB_NAME || notion.DB.REMINDERS;
const PARTNERSHIPS_DB_NAME = process.env.PARTNERSHIPS_DB_NAME || notion.DB.PARTNERSHIPS;
const JOBS_DB_NAME = process.env.JOBS_DB_NAME || notion.DB.JOBS;
const PROJECTS_DB_NAME = process.env.PROJECTS_DB_NAME || notion.DB.PROJECTS;
const DOCS_DB_NAME = process.env.DOCS_DB_NAME || notion.DB.DOCS;
const CAMPSITE_BOOKINGS_DB_NAME =
  process.env.CAMPSITE_BOOKINGS_DB || notion.DB.BOOKINGS;
const CAMPSITE_MEALS_DB_NAME = process.env.CAMPSITE_MEALS_DB || notion.DB.MEALS;

const AMBIENT_CAPTURE = process.env.AMBIENT_CAPTURE === "true";
const AMBIENT_MIN_CONFIDENCE = Number(process.env.AMBIENT_MIN_CONFIDENCE || 0.7);
const AMBIENT_MIN_TEXT_LEN = Number(process.env.AMBIENT_MIN_TEXT_LEN || 4);
const EVENT_MIN_CONFIDENCE = Number(process.env.EVENT_MIN_CONFIDENCE || 0.5);
const LOG_DOC_GAPS = process.env.LOG_DOC_GAPS === "true";
const DAILY_RECAP = process.env.DAILY_RECAP !== "false";
const RECAP_TIME = process.env.RECAP_TIME || "08:00";
const RECAP_TZ = process.env.RECAP_TZ || "Europe/Lisbon";
const EVENT_CAPTURE_GROUP = process.env.EVENT_CAPTURE_GROUP || "Upcoming";
const EVENT_RECURRING_GROUP = process.env.EVENT_RECURRING_GROUP || "Recurring";
const ADDRESS_WINDOW_MS = Number(process.env.ADDRESS_WINDOW_MS || 120000);

const WATCH_DB_NAMES = (process.env.WATCH_DB_NAMES
  ? process.env.WATCH_DB_NAMES.split(",").map((s) => s.trim())
  : notion.WATCH_DB_NAMES
).filter(Boolean);

const NOTION_POLL_SECONDS = Number(process.env.NOTION_POLL_SECONDS || 60);
const ANNOUNCE_NEW_ITEMS = process.env.ANNOUNCE_NEW_ITEMS !== "false";
const STATE_FILE =
  process.env.STATE_FILE ||
  path.join(__dirname, ".vila-akoya-telegram-state.json");

// Group titles / status labels that count as "completed".
const DONE_KEYWORDS = ["done", "logged", "decided", "past", "completed"];

// Chat activity kinds worth surfacing when AI summarisation is unavailable.
const RECAP_IMPORTANT_KINDS = new Set([
  "event_logged",
  "task_logged",
  "guest_logged",
  "task_completed",
  "event_cancelled",
  "decision_logged",
  "announcement",
  "doc_logged",
]);

let botMe = null;
const recentMessages = new Map();
/** In-flight voice transcriptions keyed by `${chatId}:${messageId}`. */
const pendingVoiceTranscriptions = new Map();

// ── Small utilities ───────────────────────────────────────────────────────
function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

const networkState = { retryMs: 3000, lastErrorLog: 0 };

function isTransientNetworkError(err) {
  const msg = String(err?.message || "").toLowerCase();
  const code = String(err?.code || err?.cause?.code || "").toLowerCase();
  return (
    msg.includes("fetch failed") ||
    msg.includes("network") ||
    msg.includes("timeout") ||
    msg.includes("aborted") ||
    msg.includes("econnreset") ||
    msg.includes("econnrefused") ||
    msg.includes("enotfound") ||
    msg.includes("etimedout") ||
    code === "econnreset" ||
    code === "econnrefused" ||
    code === "enotfound" ||
    code === "etimedout" ||
    code === "und_err_connect_timeout"
  );
}

function logTransientError(label, err, retryMs) {
  const now = Date.now();
  if (now - networkState.lastErrorLog < 60000) return;
  networkState.lastErrorLog = now;
  const detail = err?.cause?.message || err?.cause?.code || "";
  log(
    `${label}: ${err.message}` +
      (detail ? ` (${detail})` : "") +
      ` — retrying in ${Math.round(retryMs / 1000)}s`
  );
}

async function fetchWithRetry(url, init, { label = "fetch", attempts = 6 } = {}) {
  let delay = networkState.retryMs;
  for (let attempt = 1; attempt <= attempts; attempt++) {
    try {
      const res = await fetch(url, init);
      networkState.retryMs = 3000;
      return res;
    } catch (err) {
      if (!isTransientNetworkError(err) || attempt === attempts) throw err;
      networkState.retryMs = Math.min(delay, 120000);
      logTransientError(label, err, networkState.retryMs);
      await sleep(networkState.retryMs);
      delay = Math.min(delay * 2, 120000);
    }
  }
  throw new Error(`${label}: fetch failed after ${attempts} attempts`);
}

async function readJsonResponse(res, label) {
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch {
    throw new Error(
      `${label} returned non-JSON (HTTP ${res.status}): ${text.slice(0, 120)}`
    );
  }
}

function log(...args) {
  console.log(new Date().toISOString(), ...args);
}

function escapeHtml(text) {
  return String(text ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

function isDoneName(name) {
  const lower = String(name || "").toLowerCase();
  return DONE_KEYWORDS.some((kw) => lower.includes(kw));
}

function chatIdsMatch(a, b) {
  const sa = String(a);
  const sb = String(b);
  if (sa === sb) return true;
  return sa.replace(/^-/, "") === sb.replace(/^-/, "");
}

/** Task intake only runs in the Jobs group (+ private DMs). Archive groups are ignored. */
function shouldHandleIntake(message) {
  const chat = message.chat;
  if (!chat) return false;
  if (chat.type === "private") return ALLOW_DM_INTAKE;
  if (chat.type === "group" || chat.type === "supergroup") {
    return chatIdsMatch(chat.id, TASK_GROUP_CHAT_ID);
  }
  return false;
}

/** Daily recap highlights use task-group chat only — not private DMs. */
function isTaskGroupChatId(chatId) {
  return chatIdsMatch(chatId, TASK_GROUP_CHAT_ID);
}

function isTaskGroupMessage(message) {
  const chat = message.chat;
  if (!chat) return false;
  return (
    (chat.type === "group" || chat.type === "supergroup") &&
    chatIdsMatch(chat.id, TASK_GROUP_CHAT_ID)
  );
}

function todayISO(tz = RECAP_TZ) {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: tz,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}

function yesterdayISO(tz = RECAP_TZ) {
  const d = new Date(Date.now() - 86400000);
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: tz,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(d);
}

function formatFriendlyDate(iso, tz = RECAP_TZ) {
  return new Intl.DateTimeFormat("en-GB", {
    timeZone: tz,
    weekday: "long",
    day: "numeric",
    month: "long",
  }).format(new Date(iso + "T12:00:00"));
}

function currentHHMM(tz = RECAP_TZ) {
  return new Intl.DateTimeFormat("en-GB", {
    timeZone: tz,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date());
}

function dayKeyFromISO(iso, tz = RECAP_TZ) {
  const short = new Intl.DateTimeFormat("en-US", {
    timeZone: tz,
    weekday: "short",
  }).format(new Date(iso + "T12:00:00"));
  const map = { Sun: "Sun", Mon: "Mon", Tue: "Tue", Wed: "Wed", Thu: "Thu", Fri: "Fri", Sat: "Sat" };
  return map[short] || "Mon";
}

function bufferMessage(message) {
  const chatId = message.chat?.id;
  if (!chatId) return;
  const text = (message.text || message.caption || "").trim();
  if (!text) return;
  const buf = recentMessages.get(chatId) || [];
  buf.push({
    messageId: message.message_id,
    text,
    from: message.from,
    ts: Date.now(),
  });
  while (buf.length > 20) buf.shift();
  recentMessages.set(chatId, buf);
}

function bufferTranscribedText(message, text) {
  const chatId = message.chat?.id;
  if (!chatId || !text) return;
  const buf = recentMessages.get(chatId) || [];
  buf.push({
    messageId: message.message_id,
    text,
    from: message.from,
    ts: Date.now(),
    isVoice: true,
  });
  while (buf.length > 20) buf.shift();
  recentMessages.set(chatId, buf);
}

function isMentioned(message, bot) {
  if (!bot?.username) return false;
  const entities = message.entities || message.caption_entities || [];
  const text = message.text || message.caption || "";
  const uname = bot.username.toLowerCase();
  for (const ent of entities) {
    if (ent.type === "mention") {
      const mention = text.slice(ent.offset, ent.offset + ent.length).toLowerCase();
      if (mention === `@${uname}`) return true;
    }
    if (ent.type === "text_mention" && ent.user?.id === bot.id) return true;
  }
  return text.toLowerCase().includes(`@${uname}`);
}

function stripBotMention(text, bot) {
  if (!bot?.username) return text.trim();
  return text.replace(new RegExp(`@${bot.username}\\b`, "gi"), "").trim();
}

const VOICE_LEADING_FILLER = /^(?:(?:hey|hi|hello|ok|okay|um|uh)[,.\s]*)*/i;

/** "Vava" anywhere — includes common voice-transcription variants. */
const VAVA_WAKE_ANYWHERE = /\b(?:vava|vah[\s-]?vah|va[\s-]?va)\b/i;

/** Wake phrases at the start of a voice transcription (after optional hey/hi). */
const VOICE_WAKE_AT_START = [
  /^vila akoya bot\b/i,
  /^personal assist(?:ant|ance)\b/i,
  /^robot\b/i,
  /^bot\b/i,
];

function stripVavaWakeWords(text) {
  return String(text)
    .replace(/\b(?:vava|vah[\s-]?vah|va[\s-]?va)\b/gi, "")
    .replace(/\s+/g, " ")
    .trim();
}

function parseVoiceAddressing(text) {
  if (!text) return { addressed: false, content: "" };
  const rest = String(text).trim();

  if (VAVA_WAKE_ANYWHERE.test(rest)) {
    return { addressed: true, content: stripVavaWakeWords(rest) };
  }

  const afterFiller = rest.replace(VOICE_LEADING_FILLER, "").trim();
  for (const pattern of VOICE_WAKE_AT_START) {
    const match = afterFiller.match(pattern);
    if (match) {
      const prefixLen = rest.length - afterFiller.length + match[0].length;
      const content = rest.slice(prefixLen).replace(/^[,.\s]+/, "").trim();
      return { addressed: true, content };
    }
  }

  return { addressed: false, content: rest };
}

/** Spoken wake words in a voice transcription count as addressing the bot in groups. */
function textAddressesBot(text) {
  return parseVoiceAddressing(text).addressed;
}

function stripBotAddressing(text, bot) {
  if (!text) return "";
  let cleaned = parseVoiceAddressing(text).content;
  if (bot?.username) {
    cleaned = cleaned.replace(new RegExp(`@${bot.username}\\b`, "gi"), "");
  }
  return cleaned.replace(/\s+/g, " ").trim();
}

function isReplyToBot(message, bot) {
  const reply = message.reply_to_message;
  if (!reply || !bot) return false;
  return reply.from?.id === bot.id || reply.from?.is_bot;
}

function isAddressedToBot(message, bot) {
  if (!bot) return false;
  if (message.chat?.type === "private") return true;
  if (isReplyToBot(message, bot)) return true;
  if (isMentioned(message, bot)) return true;
  return false;
}

async function waitForPendingVoiceTranscriptions(chatId) {
  const prefix = `${chatId}:`;
  const pending = [...pendingVoiceTranscriptions.entries()]
    .filter(([key]) => key.startsWith(prefix))
    .map(([, promise]) => promise);
  await Promise.allSettled(pending);
}

function recentBufferedVoice(message) {
  const chatId = message.chat?.id;
  const userId = message.from?.id;
  if (!chatId) return null;
  const cutoff = Date.now() - ADDRESS_WINDOW_MS;
  const buf = recentMessages.get(chatId) || [];
  return (
    buf
      .filter(
        (m) =>
          m.isVoice &&
          m.messageId !== message.message_id &&
          m.ts >= cutoff &&
          (!userId || m.from?.id === userId)
      )
      .slice(-1)[0] || null
  );
}

function shouldAttachRecentVoice(stripped, lastVoice) {
  if (!lastVoice) return false;
  if (!stripped) return true;
  if (Date.now() - lastVoice.ts <= 30000) return true;
  const lower = stripped.toLowerCase();
  return /\b(voice|audio|note|listen|hear|above|this|that|vava|vah[\s-]?vah|va[\s-]?va|personal assist)/i.test(
    lower
  );
}

async function resolveAddressedText(message, bot) {
  const chatId = message.chat?.id;
  if (!chatId) return "";

  await waitForPendingVoiceTranscriptions(chatId);

  const raw = (message.text || message.caption || "").trim();
  const stripped = stripBotAddressing(raw, bot);
  const userId = message.from?.id;
  const reply = message.reply_to_message;
  const cutoff = Date.now() - ADDRESS_WINDOW_MS;
  const buf = recentMessages.get(chatId) || [];
  const recent = buf.filter((m) => m.messageId !== message.message_id && m.ts >= cutoff);

  if (reply) {
    const bufferedReply = recent.find((m) => m.messageId === reply.message_id);
    if (bufferedReply?.text) {
      return stripped ? `${stripped}\n\n${bufferedReply.text}` : bufferedReply.text;
    }
    const replyText = (reply.text || reply.caption || "").trim();
    if (replyText) return stripped ? `${stripped}\n\n${replyText}` : replyText;
    if (getVoiceFileId(reply)) {
      const voiceText = await transcribeMessageVoice(reply, { interactive: false });
      if (voiceText) return stripped ? `${stripped}\n\n${voiceText}` : voiceText;
    }
  }

  const lastVoice = recentBufferedVoice(message);
  if (lastVoice && (!stripped || shouldAttachRecentVoice(stripped, lastVoice))) {
    return stripped ? `${stripped}\n\n${lastVoice.text}` : lastVoice.text;
  }

  const prev = recent
    .filter((m) => !userId || m.from?.id === userId)
    .slice(-1)[0];
  return stripped || prev?.text || "";
}

function ambientDedupeKey(chatId, type, title) {
  return `${chatId}:${type}:${String(title).toLowerCase().slice(0, 80)}`;
}

function wasRecentlyCaptured(key) {
  if (!state.ambientKeys) state.ambientKeys = {};
  const ts = state.ambientKeys[key];
  if (!ts) return false;
  if (Date.now() - ts > 24 * 60 * 60 * 1000) {
    delete state.ambientKeys[key];
    return false;
  }
  return true;
}

function markCaptured(key) {
  if (!state.ambientKeys) state.ambientKeys = {};
  state.ambientKeys[key] = Date.now();
  const keys = Object.keys(state.ambientKeys);
  if (keys.length > 500) {
    for (const k of keys.slice(0, keys.length - 400)) delete state.ambientKeys[k];
  }
  saveState();
}

function recordGroupMessage(message, textOverride) {
  if (!isTaskGroupMessage(message)) return;
  const from = message.from;
  if (from?.is_bot) return;
  const text = String(textOverride ?? (message.text || message.caption || "")).trim();
  if (!text || text.startsWith("/")) return;

  if (!state.chatLog) state.chatLog = [];
  state.chatLog.push({
    dayISO: todayISO(),
    chatId: String(message.chat.id),
    proposer: senderDisplayName(from),
    text: text.slice(0, 500),
    ts: Date.now(),
  });

  const cutoff = Date.now() - 14 * 24 * 60 * 60 * 1000;
  state.chatLog = state.chatLog.filter((m) => m.ts >= cutoff);
  if (state.chatLog.length > 400) {
    state.chatLog = state.chatLog.slice(-300);
  }
}

function recordChatActivity({ kind, from, chatId, title, eventDate, text }) {
  if (!isTaskGroupChatId(chatId)) return;
  if (!state.chatActivity) state.chatActivity = [];
  const dayISO = todayISO();
  const entry = {
    kind,
    dayISO,
    chatId: String(chatId),
    proposer: senderDisplayName(from),
    title: String(title || "").slice(0, 80),
    eventDate: eventDate || null,
    text: String(text || "").slice(0, 120),
    ts: Date.now(),
  };
  const dupe = state.chatActivity.find(
    (a) =>
      a.dayISO === dayISO &&
      a.kind === kind &&
      a.proposer === entry.proposer &&
      a.title.toLowerCase() === entry.title.toLowerCase()
  );
  if (dupe) return;
  state.chatActivity.push(entry);
  const cutoff = Date.now() - 30 * 24 * 60 * 60 * 1000;
  state.chatActivity = state.chatActivity.filter((a) => a.ts >= cutoff);
  if (state.chatActivity.length > 200) {
    state.chatActivity = state.chatActivity.slice(-150);
  }
  saveState();
  log(`Chat activity recorded (${kind}): ${entry.proposer} — ${entry.title}`);
}

function inferEventDate(text, explicitDate) {
  if (explicitDate && /^\d{4}-\d{2}-\d{2}$/.test(explicitDate)) return explicitDate;
  // Fallback only when the classifier did not supply a date.
  return addDaysISO(todayISO(), 7);
}

function chatActivityForDay(dayISO) {
  return (state.chatActivity || []).filter(
    (a) => a.dayISO === dayISO && isTaskGroupChatId(a.chatId)
  );
}

function formatChatActivityHighlight(item) {
  const who = item.proposer;
  if (item.kind === "announcement") {
    return `${who} announced: ${item.title}`;
  }
  if (item.kind === "task_completed") {
    return `${who} marked done: ${item.title}`;
  }
  if (item.kind === "event_cancelled") {
    return `${who} cancelled: ${item.title}`;
  }
  if (item.kind === "event_logged") {
    const when = item.eventDate ? formatFriendlyDate(item.eventDate) : null;
    return when
      ? `${who} logged event: ${item.title} (${when})`
      : `${who} logged event: ${item.title}`;
  }
  if (item.kind === "guest_logged") {
    return `${who} reported guest/campsite issue: ${item.title}`;
  }
  if (item.kind === "decision_logged") {
    return `${who} raised for discussion: ${item.title}`;
  }
  if (item.kind === "task_logged") {
    return `${who} logged task: ${item.title}`;
  }
  if (item.kind === "doc_logged") {
    return `${who} drafted documentation: ${item.title}`;
  }
  return null;
}

function importantActivityHighlights(dayISO) {
  return chatActivityForDay(dayISO)
    .filter((item) => RECAP_IMPORTANT_KINDS.has(item.kind))
    .map((item) => formatChatActivityHighlight(item))
    .filter(Boolean);
}

function chatLogForDay(dayISO) {
  return (state.chatLog || []).filter(
    (m) => m.dayISO === dayISO && isTaskGroupChatId(m.chatId)
  );
}

async function buildYesterdayHighlights(dayISO) {
  const logMessages = chatLogForDay(dayISO);

  if (openaiLib.isEnabled() && logMessages.length) {
    const highlights = await openaiLib.summarizeImportantChat(logMessages, dayISO);
    if (highlights !== null) return highlights;
  }

  return importantActivityHighlights(dayISO);
}

// ── Persisted state ─────────────────────────────────────────────────────────
function loadState() {
  try {
    const s = JSON.parse(fs.readFileSync(STATE_FILE, "utf8"));
    if (!s.ambientKeys) s.ambientKeys = {};
    if (!s.chatActivity) s.chatActivity = [];
    if (!s.chatLog) s.chatLog = [];
    if (!s.items) s.items = {};
    if (!s.pendingClarify) s.pendingClarify = {};
    if (s.lastPersonalRecapDate === undefined) s.lastPersonalRecapDate = null;
    if (s.seededNotion === undefined) {
      if (s.seededMonday !== undefined) s.items = {};
      s.seededNotion = false;
    }
    return s;
  } catch {
    return {
      telegramOffset: 0,
      seededNotion: false,
      lastRecapDate: null,
      lastPersonalRecapDate: null,
      ambientKeys: {},
      chatActivity: [],
      chatLog: [],
      items: {},
      pendingClarify: {},
    };
  }
}

let state = loadState();

function saveState() {
  try {
    fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2));
  } catch (err) {
    log("Could not persist state:", err.message);
  }
}

// ── Notion helpers ─────────────────────────────────────────────────────────
/** Human-readable "captured from Telegram" note appended to Notes. */
function telegramNote({ from, chatId, extra = "", sourceText = "" }) {
  const parts = [
    `From Telegram: ${senderDisplayName(from)} (${senderHandle(from)})`,
    `Chat: ${chatId}`,
  ];
  if (extra) parts.push(extra);
  if (sourceText) parts.push(`\nOriginal message:\n${sourceText}`);
  return parts.join("\n");
}

/** Does this Notion page count as completed for announcement / listing? */
function pageIsDone(page) {
  const stage = notion.pagePropText(page, "Stage");
  const status = notion.pagePropText(page, "Status");
  return notion.isDoneStage(stage) || isDoneName(status);
}

/** Snapshot of a page used by the change poller. */
function pageSnapshot(page) {
  const stage = notion.pagePropText(page, "Stage");
  const status = notion.pagePropText(page, "Status");
  const doer =
    notion.pagePropText(page, "Doer name") ||
    notion.pagePropText(page, "Assignee") ||
    notion.pagePropText(page, "Helpers / doers") ||
    "";
  return { stage, status, doer, done: pageIsDone(page) };
}

/** Pre-mark a freshly created page as seen so the poller won't re-announce it. */
function markPageSeen(pageId, snapshot) {
  state.items[pageId] =
    snapshot || { stage: "", status: "", doer: "", done: false };
  saveState();
}

// ── Telegram client ─────────────────────────────────────────────────────────
async function telegram(method, payload, { clientTimeoutMs } = {}) {
  const init = {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload || {}),
  };
  if (clientTimeoutMs) {
    init.signal = AbortSignal.timeout(clientTimeoutMs);
  }
  const res = await fetchWithRetry(`${TELEGRAM_API}/${method}`, init, {
    label: `Telegram ${method}`,
    attempts: 4,
  });
  const json = await readJsonResponse(res, `Telegram ${method}`);
  if (!json.ok) {
    throw new Error(`Telegram ${method} failed: ${JSON.stringify(json)}`);
  }
  return json.result;
}

/** When replying inside a forum topic, Telegram needs message_thread_id. */
let replyThreadExtra = {};

function withReplyThread(message, fn) {
  const prev = replyThreadExtra;
  replyThreadExtra =
    message?.is_topic_message && message.message_thread_id
      ? { message_thread_id: message.message_thread_id }
      : {};
  return Promise.resolve(fn()).finally(() => {
    replyThreadExtra = prev;
  });
}

async function sendMessage(chatId, text, extra = {}) {
  if (!chatId) return;
  try {
    await telegram("sendMessage", {
      chat_id: chatId,
      text,
      parse_mode: "HTML",
      disable_web_page_preview: true,
      ...replyThreadExtra,
      ...extra,
    });
  } catch (err) {
    log("sendMessage error:", err.message);
  }
}

function senderHandle(from) {
  if (!from) return "unknown";
  if (from.username) return "@" + from.username;
  return [from.first_name, from.last_name].filter(Boolean).join(" ") || String(from.id);
}

function senderDisplayName(from) {
  if (!from) return "Unknown";
  return [from.first_name, from.last_name].filter(Boolean).join(" ") ||
    (from.username ? "@" + from.username : String(from.id));
}

// ── File download (photos, voice notes) ──────────────────────────────────────
function getVoiceFileId(message) {
  if (message.voice?.file_id) return message.voice.file_id;
  if (message.audio?.file_id) return message.audio.file_id;
  return null;
}

async function downloadTelegramFile(fileId) {
  const file = await telegram("getFile", { file_id: fileId });
  const res = await fetch(`${TELEGRAM_FILE_API}/${file.file_path}`);
  const buf = Buffer.from(await res.arrayBuffer());
  const filename = path.basename(file.file_path) || `file-${fileId}`;
  return { buf, filename };
}

async function downloadTelegramPhoto(fileId) {
  const { buf, filename } = await downloadTelegramFile(fileId);
  return {
    buf,
    filename: /\.[a-z0-9]+$/i.test(filename) ? filename : `${filename}.jpg`,
  };
}

async function transcribeMessageVoice(message, { interactive = false } = {}) {
  const voiceFileId = getVoiceFileId(message);
  if (!voiceFileId) return null;

  const chatId = message.chat.id;
  const pendingKey = `${chatId}:${message.message_id}`;
  const buffered = (recentMessages.get(chatId) || []).find(
    (m) => m.messageId === message.message_id && m.isVoice && m.text
  );
  if (buffered?.text) return buffered.text;
  if (pendingVoiceTranscriptions.has(pendingKey)) {
    return pendingVoiceTranscriptions.get(pendingKey);
  }

  const work = transcribeMessageVoiceInner(message, { interactive });
  pendingVoiceTranscriptions.set(pendingKey, work);
  try {
    return await work;
  } finally {
    pendingVoiceTranscriptions.delete(pendingKey);
  }
}

async function transcribeMessageVoiceInner(message, { interactive = false } = {}) {
  const voiceFileId = getVoiceFileId(message);
  if (!voiceFileId) return null;

  const chatId = message.chat.id;

  if (!openaiLib.isEnabled()) {
    if (interactive) {
      await sendMessage(
        chatId,
        "Voice notes need OPENAI_API_KEY configured. You can type your message instead."
      );
    }
    return "";
  }

  if (interactive) {
    await telegram("sendChatAction", { chat_id: chatId, action: "typing" }).catch(() => {});
  }

  try {
    const text = await transcribeVoiceFile(voiceFileId);
    if (!text) {
      if (interactive) {
        await sendMessage(chatId, "I couldn't make out any speech in that voice note.");
      }
      return "";
    }
    log(`Voice transcribed for ${senderHandle(message.from)}: ${text.slice(0, 120)}`);
    bufferTranscribedText(message, text);
    if (isTaskGroupMessage(message)) {
      recordGroupMessage(message, text);
    }
    return text;
  } catch (err) {
    log("transcribeVoiceFile error:", err.message);
    if (interactive) {
      await sendMessage(chatId, "Sorry, I couldn't transcribe that voice note. Try typing instead.");
    }
    return "";
  }
}

async function transcribeVoiceFile(fileId) {
  const { buf, filename } = await downloadTelegramFile(fileId);
  return openaiLib.transcribeAudio(buf, filename);
}

// ── Notion documentation ─────────────────────────────────────────────────────
async function createDocumentation({ rawText, from, chatId, sourceText }) {
  const structured = await openaiLib.structureDocumentation(rawText, {
    proposer: senderDisplayName(from),
    todayISO: todayISO(),
  });
  if (!structured?.markdown) {
    throw new Error("Could not structure documentation from that message");
  }

  const notes =
    `${structured.summary}\n\n` +
    `Captured from Telegram: ${senderDisplayName(from)} (${senderHandle(from)})\n` +
    `Chat: ${chatId}\n\n` +
    `Original notes:\n${String(sourceText || rawText).slice(0, 800)}`;

  const children = notion.markdownToBlocks(structured.markdown);
  const page = await notion.createPage({
    databaseName: DOCS_DB_NAME,
    title: structured.title,
    properties: {
      "Doc status": "Draft",
      Area: structured.area,
      "Telegram user": senderHandle(from),
      Summary: structured.summary || "",
      Notes: notes,
    },
    children,
  });

  const indexText = [structured.title, structured.summary, structured.markdown]
    .filter(Boolean)
    .join("\n\n");
  await indexDocumentationInSearch({
    id: page.id,
    name: structured.title,
    url: page.url,
    text: indexText,
  });

  return { doc: { url: page.url, id: page.id }, structured };
}

// ── Telegram -> Notion: create a task ─────────────────────────────────────────
async function createInboxTask({ text, from, chatId, photoFileId, classification }) {
  const cleanText = String(text || "").trim() || "(no text)";

  const rawName = classification?.title || cleanText;
  const name = rawName.length > 200 ? rawName.slice(0, 197) + "..." : rawName;

  const summary = classification?.summary ? `Summary: ${classification.summary}\n` : "";
  const notes = telegramNote({
    from,
    chatId,
    extra: summary.trim(),
    sourceText: cleanText,
  });

  const properties = {
    Status: "Not started",
    "Created by": senderDisplayName(from),
    Notes: notes,
  };
  if (classification?.type) properties.Type = classification.type;
  if (classification?.priority) properties.Priority = classification.priority;
  if (photoFileId) properties.Notes = `${notes}\n\n[Photo attached in Telegram]`;

  const page = await notion.createPage({
    databaseName: TASKS_DB_NAME,
    title: name,
    properties,
  });

  markPageSeen(page.id, {
    stage: "",
    status: "Not started",
    doer: "",
    done: false,
  });
  return { itemId: page.id, url: page.url };
}

/** Generic create in any Notion DB, mirroring the old createBoardItem shape. */
async function createDbPage({
  databaseName,
  stage,
  itemName,
  properties = {},
  from,
  chatId,
  sourceText,
}) {
  const props = { ...properties };
  if (stage && props.Stage === undefined) props.Stage = stage;
  if (props.Source === undefined) props.Source = "Telegram";
  if (from && props["Telegram user"] === undefined) {
    props["Telegram user"] = senderHandle(from);
  }
  if (sourceText && props.Notes === undefined) {
    props.Notes = telegramNote({ from, chatId, sourceText });
  }

  const page = await notion.createPage({
    databaseName,
    title: String(itemName || "Item").slice(0, 200),
    properties: props,
  });

  markPageSeen(page.id, {
    stage: stage || "",
    status: "",
    doer: "",
    done: notion.isDoneStage(stage),
  });
  return { itemId: page.id, url: page.url, databaseName, boardName: databaseName };
}

async function findProjectByTitle(title) {
  const q = String(title || "").trim();
  if (!q) return null;
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(PROJECTS_DB_NAME));
  } catch {
    return null;
  }
  const exact = pages.find(
    (p) => notion.pageTitle(p).toLowerCase() === q.toLowerCase()
  );
  if (exact) return exact;
  const soft = pages.find((p) => titleMatches(q, notion.pageTitle(p)));
  return soft || null;
}

async function linkTaskToProject(taskPageId, projectTitle) {
  if (!taskPageId || !projectTitle) return null;
  const project = await findProjectByTitle(projectTitle);
  if (!project) return null;
  const tasksDb = await notion.resolveDatabase(TASKS_DB_NAME);
  if (!tasksDb.properties?.Project) return null;
  await notion.updatePage(taskPageId, {
    properties: {
      Project: { relation: [{ id: project.id }] },
    },
  });
  return { id: project.id, name: notion.pageTitle(project), url: project.url };
}

async function createTaskFromClassification({ classified, text, from, chatId, photoFileId }) {
  const task = classified.task;
  const properties = {
    Status: "Not started",
    "Created by": senderDisplayName(from),
  };
  if (task?.type) properties.Type = task.type;
  if (task?.priority) properties.Priority = task.priority;
  if (task?.assignee) properties.Assignee = task.assignee;
  let sourceText = text;
  if (photoFileId) sourceText = `${text || ""}\n\n[Photo attached in Telegram]`.trim();

  const created = await createDbPage({
    databaseName: TASKS_DB_NAME,
    itemName: task?.title || text,
    properties,
    from,
    chatId,
    sourceText,
  });

  let project = null;
  if (task?.project) {
    try {
      project = await linkTaskToProject(created.itemId, task.project);
    } catch (err) {
      log("linkTaskToProject error:", err.message);
    }
  }

  return { ...created, project };
}

async function createDecision({ decision, from, chatId, sourceText }) {
  // Decisions DB removed per meeting notes — log as a management task for discussion.
  const bits = [];
  if (decision.category) bits.push(`Category: ${decision.category}`);
  if (decision.summary) bits.push(decision.summary);
  bits.push("[decision / proposal]");
  return createDbPage({
    databaseName: TASKS_DB_NAME,
    itemName: decision.title,
    properties: {
      Type: "Management",
      Status: "Not started",
      Notes: bits.join("\n"),
      "Created by": senderDisplayName(from),
    },
    from,
    chatId,
    sourceText,
  });
}

function isInternalEvent({ eventType, scope }) {
  if (scope === "internal") return true;
  if (scope === "external") return false;
  const internalTypes = ["Work party", "Workshop", "Shared meal"];
  return internalTypes.includes(eventType);
}

async function createEvent({ title, date, eventType, recurrence, scope, from, chatId, sourceText }) {
  const recurrenceInfo = normalizeRecurrenceField(recurrence || "");
  const stage = recurrenceInfo.rule ? EVENT_RECURRING_GROUP : EVENT_CAPTURE_GROUP;

  let eventDate = date;
  if (recurrenceInfo.rule) {
    eventDate =
      (date && occursOn(recurrenceInfo.rule, date, RECAP_TZ) && date) ||
      nextOccurrence(recurrenceInfo.rule, date && date >= todayISO() ? date : todayISO(), RECAP_TZ) ||
      date;
  }

  const recurNote = recurrenceInfo.label
    ? `Recurrence: ${recurrenceInfo.canonical} (${recurrenceInfo.label})`
    : "";
  const notes = telegramNote({
    from,
    chatId,
    extra: recurNote,
    sourceText: sourceText || title,
  });

  const databaseName = isInternalEvent({ eventType, scope })
    ? INTERNAL_EVENTS_DB_NAME
    : EXTERNAL_EVENTS_DB_NAME;

  const properties = {
    Stage: stage,
    "Announce on Telegram?": true,
    Source: "Telegram",
    Notes: notes,
  };
  if (eventDate) properties.Date = eventDate;
  if (eventType) properties["Event type"] = eventType;
  if (recurrenceInfo.canonical) properties.Recurrence = recurrenceInfo.canonical;

  const { eventBodyBlocks } = require("./lib/event-hub");
  const pageTitle = String(title || "Event").slice(0, 200);
  const page = await notion.createPage({
    databaseName,
    title: pageTitle,
    properties,
    children: eventBodyBlocks({
      title: pageTitle,
      isSeries: Boolean(recurrenceInfo.rule),
      recurrenceLabel: recurrenceInfo.label || null,
    }),
  });

  // Materialise calendar dates for recurring series (Notion calendar needs one page per date).
  if (recurrenceInfo.rule) {
    try {
      const totals = await expandRecurringEvents({
        databases: [databaseName],
        log: (msg) => log(msg),
      });
      log(
        `Recurring expand (${databaseName}): ${totals.created} created, ${totals.updated} updated`
      );
    } catch (err) {
      log("Recurring expand failed:", err.message);
    }
  }

  markPageSeen(page.id, {
    stage,
    status: "",
    doer: "",
    done: notion.isDoneStage(stage),
  });
  return {
    itemId: page.id,
    url: page.url,
    date: eventDate,
    databaseName,
    recurrence: recurrenceInfo.canonical || null,
    recurrenceLabel: recurrenceInfo.label || null,
  };
}

function looksLikeMyDayQuestion(text) {
  const t = String(text || "").toLowerCase();
  return (
    /what should i do/.test(t) ||
    /what do i (need to |have to )?do/.test(t) ||
    /what('?s| is) (on )?my (list|plate|agenda)/.test(t) ||
    /my (tasks?|duties|chores|jobs) (for )?today/.test(t) ||
    /\bmy day\b/.test(t) ||
    /o que (eu )?tenho (de |que )?fazer/.test(t) ||
    /minhas tarefas/.test(t)
  );
}

function looksLikeCompleteTask(text) {
  const t = String(text || "").toLowerCase();
  return (
    /\bmark\b.*\b(done|complete|completed|finished)\b/.test(t) ||
    /\b(please|can you|could you)\b.*\bmark\b/.test(t) ||
    /\b(?:i'?ve|i have)\s+done\b/.test(t) ||
    /\b(?:it|that)('?s| is)\s+done\b/.test(t) ||
    (/\b(?:done|finished|completed|fixed)\b/.test(t) &&
      /\b(wifi|wi-?fi|task|job|tap|toilet|shower|leak|light|valve)\b/.test(t))
  );
}

function extractCompleteTaskTarget(text) {
  if (!text) return null;
  const patterns = [
    /\bmark(?:\s+it)?\s+(?:the\s+)?(.+?)\s+(?:as\s+|to\s+)?(?:done|complete|completed|finished)\b/i,
    /\b(?:please\s+)?(?:mark|log)\s+(?:the\s+)?(.+?)\s+(?:as\s+)?(?:done|complete)\b/i,
    /\b(?:changing|change|fixing|fix)\s+(?:the\s+)?(.+?)\s+(?:is\s+)?(?:done|finished|completed|fixed)\b/i,
    /\b(?:the\s+)?(.+?)\s+is\s+(?:now\s+)?(?:done|finished|completed|fixed)\b/i,
  ];
  for (const pattern of patterns) {
    const match = String(text).match(pattern);
    if (!match?.[1]) continue;
    const title = match[1].trim();
    if (title.length > 2 && !/^(it|that|this)$/i.test(title)) {
      return title.replace(/\s+/g, " ");
    }
  }
  return null;
}

function findCompleteTaskTargetInRecent(chatId, excludeText) {
  const buf = recentMessages.get(chatId) || [];
  for (const entry of buf.slice(-10).reverse()) {
    if (!entry.text || entry.text === excludeText) continue;
    const title = extractCompleteTaskTarget(entry.text);
    if (title) return title;
    if (/\bwi-?fi\b/i.test(entry.text)) {
      const wifi = entry.text.match(/\b(?:changing|change|fix(?:ing)?)\s+(?:the\s+)?wi-?fi\b/i);
      if (wifi) return "wifi";
      return "wifi";
    }
  }
  return null;
}

function inferCompletionIntent(text, chatId) {
  if (!looksLikeCompleteTask(text)) return null;
  let title = extractCompleteTaskTarget(text);
  if (!title) title = findCompleteTaskTargetInRecent(chatId, text);
  if (!title) return null;
  return {
    action: "complete_task",
    actionable: true,
    confidence: 0.88,
    is_question: false,
    is_task: false,
    is_event: false,
    is_complete: true,
    is_cancel_event: false,
    target: { title },
  };
}

function mergeCompletionIntent(classified, text, chatId) {
  const completion = inferCompletionIntent(text, chatId);
  if (!completion) return classified;
  if (classified?.is_complete && classified?.target?.title) return classified;
  if (!classified || classified.action === "chat" || !classified.is_complete) {
    log(`Completion intent: mark done → "${completion.target.title}"`);
    return { ...(classified || {}), ...completion };
  }
  return classified;
}

async function resolvePersonIdentity(from) {
  const handle = senderHandle(from);
  const { page: member } = await findMemberByTelegram(handle);
  const displayName = member
    ? notion.pagePropText(member, "Display name") || notion.pageTitle(member)
    : senderDisplayName(from);
  return { handle, displayName, member };
}

/**
 * Personal briefing: job duties due today + open tasks assigned to you.
 */
async function buildMyDayBriefing(from, dateISO = todayISO()) {
  const { handle, displayName, member } = await resolvePersonIdentity(from);
  const registered = Boolean(member);

  const duties = [];
  const tasks = [];

  let jobs = [];
  let dutyPages = [];
  try {
    ({ pages: jobs } = await notion.queryDatabase(JOBS_DB_NAME));
    ({ pages: dutyPages } = await notion.queryDatabase(RECURRING_DB_NAME));
  } catch (err) {
    log("buildMyDayBriefing jobs/duties:", err.message);
  }

  const jobById = new Map(jobs.map((j) => [j.id, j]));
  for (const duty of dutyPages) {
    const jobRel = duty.properties?.Job?.relation || [];
    const job = jobRel[0] ? jobById.get(jobRel[0].id) : null;
    const assignee =
      (job && notion.pagePropText(job, "Assignee")) ||
      notion.pagePropText(duty, "Assignee") ||
      "";
    if (!nameMatchesPerson(assignee, displayName, handle)) continue;

    const due = isDutyDueOn(dateISO, {
      frequency: notion.pagePropText(duty, "Frequency"),
      lastCompleted: notion.pageDateISO(duty, "Last completed"),
      nextDue: notion.pageDateISO(duty, "Next due date"),
      status: notion.pagePropText(duty, "Status"),
    });
    if (!due) continue;

    duties.push({
      id: duty.id,
      name: notion.pageTitle(duty),
      job: job ? notion.pageTitle(job) : "",
      frequency: notion.pagePropText(duty, "Frequency") || "Daily",
      nextDue: notion.pageDateISO(duty, "Next due date") || dateISO,
    });
  }

  let taskPages = [];
  try {
    ({ pages: taskPages } = await notion.queryDatabase(TASKS_DB_NAME));
  } catch (err) {
    log("buildMyDayBriefing tasks:", err.message);
  }
  for (const page of taskPages) {
    if (pageIsDone(page)) continue;
    const assignee = notion.pagePropText(page, "Assignee");
    if (!nameMatchesPerson(assignee, displayName, handle)) continue;
    tasks.push({
      id: page.id,
      name: notion.pageTitle(page),
      status: notion.pagePropText(page, "Status") || "Not started",
      priority: notion.pagePropText(page, "Priority") || "",
      type: notion.pagePropText(page, "Type") || "",
    });
  }

  return { displayName, handle, registered, dateISO, duties, tasks };
}

function formatMyDayBriefing(brief) {
  const lines = [
    `<b>Your day — ${escapeHtml(brief.dateISO)}</b>`,
    `For <b>${escapeHtml(brief.displayName)}</b>`,
  ];
  if (!brief.registered) {
    lines.push(
      `<i>Tip: /register Your Name so I match Jobs Assignee + Tasks Assignee reliably.</i>`
    );
  }

  lines.push("", "<b>Duties due</b> (from your Jobs)");
  if (brief.duties.length) {
    for (const d of brief.duties) {
      const job = d.job ? ` · ${escapeHtml(d.job)}` : "";
      lines.push(
        `• ${escapeHtml(d.name)}${job} <i>(${escapeHtml(d.frequency)})</i>` +
          `\n   done: <code>/done ${d.id}</code>`
      );
    }
  } else {
    lines.push("• None due today (or no job assigned to you yet)");
  }

  lines.push("", "<b>Open tasks</b> assigned to you");
  if (brief.tasks.length) {
    for (const t of brief.tasks) {
      const meta = [t.status, t.priority, t.type].filter(Boolean).join(" · ");
      lines.push(
        `• ${escapeHtml(t.name)}${meta ? ` <i>[${escapeHtml(meta)}]</i>` : ""}` +
          `\n   done: <code>/done ${t.id}</code>`
      );
    }
  } else {
    lines.push("• None");
  }

  return lines.join("\n");
}

async function sendMyDayBriefing(from, replyChatId, dateISO = todayISO()) {
  const brief = await buildMyDayBriefing(from, dateISO);
  const privateChatId = from?.id;
  if (!privateChatId) return brief;

  await sendMessage(privateChatId, formatMyDayBriefing(brief));

  const inGroup =
    replyChatId &&
    String(replyChatId) !== String(privateChatId) &&
    Number(replyChatId) < 0;
  if (inGroup) {
    await sendMessage(
      replyChatId,
      "📬 Sent your personal to-do list by DM — check your chat with @vila_akoya_bot."
    );
  }
  return brief;
}

async function fetchRotaForDay(dateISO) {
  // Jobs (roles) + their Recurring Duties. Assignee lives on the Job (text name).
  let jobs;
  let duties;
  try {
    ({ pages: jobs } = await notion.queryDatabase(JOBS_DB_NAME));
    ({ pages: duties } = await notion.queryDatabase(RECURRING_DB_NAME));
  } catch (err) {
    log("Rota fetch failed:", err.message);
    return [];
  }

  const dayKey = dayKeyFromISO(dateISO); // Mon…Sun
  const jobById = new Map(jobs.map((j) => [j.id, j]));

  const chores = [];
  for (const duty of duties) {
    if (notion.pagePropText(duty, "Status") === "Paused") continue;
    if (notion.isDoneStage(notion.pagePropText(duty, "Status"))) continue;

    const freq = (notion.pagePropText(duty, "Frequency") || "").toLowerCase();
    // Daily always; weekly/monthly/seasonal still listed (who's responsible today = job assignee)
    if (freq === "weekly" && dayKey === "Sun") {
      // still show weekly duties every day for "who's on this job" — filter lightly:
      // show daily always; weekly/monthly show every day as reminder who owns the job
    }

    const jobRel = duty.properties?.Job?.relation || [];
    const job = jobRel[0] ? jobById.get(jobRel[0].id) : null;
    if (job && notion.pagePropText(job, "Status") === "Archived") continue;

    const assignee =
      (job && notion.pagePropText(job, "Assignee")) ||
      notion.pagePropText(duty, "Assignee") ||
      "(unassigned)";
    const jobName = job ? notion.pageTitle(job) : "";
    const chore = jobName
      ? `${notion.pageTitle(duty)} [${jobName}]`
      : notion.pageTitle(duty);

    // Prefer showing daily duties for “today”; still include others labelled by frequency
    if (freq && freq !== "daily" && freq !== "weekly" && freq !== "monthly" && freq !== "seasonal") {
      // unknown — include
    }
    if (freq === "daily" || !freq) {
      chores.push({ chore, assignee, frequency: freq || "daily" });
    } else {
      chores.push({
        chore: `${chore} (${freq})`,
        assignee,
        frequency: freq,
      });
    }
  }

  // Also list jobs with assignee even if no duties (role ownership)
  for (const job of jobs) {
    if (notion.pagePropText(job, "Status") === "Archived") continue;
    const assignee = notion.pagePropText(job, "Assignee");
    if (!assignee) continue;
    const hasDuty = chores.some((c) => c.chore.includes(`[${notion.pageTitle(job)}]`));
    if (!hasDuty) {
      chores.push({
        chore: `Job: ${notion.pageTitle(job)}`,
        assignee,
        frequency: "role",
      });
    }
  }

  return chores;
}

function eventMatchesDate(itemDate, recurrenceText, dateISO) {
  const rule = parseRecurrence(recurrenceText || "");
  if (rule) {
    if (itemDate && dateISO < itemDate.slice(0, 10)) return false;
    return occursOn(rule, dateISO, RECAP_TZ);
  }
  return itemDate && itemDate.slice(0, 10) === dateISO;
}

/** Read recurrence from the Recurrence prop, or "Recurrence: …" / canonical rule in Notes. */
function recurrenceTextFromPage(page) {
  const direct = notion.pagePropText(page, "Recurrence").trim();
  if (direct && parseRecurrence(direct)) return direct;

  const notes = notion.pagePropText(page, "Notes") || "";
  const marked = notes.match(/Recurrence:\s*([^\n(]+)/i);
  if (marked) {
    const raw = marked[1].trim();
    if (parseRecurrence(raw)) return raw;
  }
  const canonical = notes.match(/\b((?:daily|weekly|monthly):[A-Za-z0-9,_-]+)\b/i);
  if (canonical && parseRecurrence(canonical[1])) return canonical[1];
  return direct;
}

function collectEventOccurrences(itemDate, recurrenceText, startISO, endISO) {
  const rule = parseRecurrence(recurrenceText || "");
  if (rule) {
    const seriesStart = itemDate ? itemDate.slice(0, 10) : startISO;
    const from = seriesStart > startISO ? seriesStart : startISO;
    if (from > endISO) return [];
    return expandBetween(rule, from, endISO, RECAP_TZ);
  }
  const d = itemDate ? itemDate.slice(0, 10) : "";
  if (d && d >= startISO && d <= endISO) return [d];
  return [];
}

async function fetchEventsForDate(dateISO) {
  const allPages = [];
  for (const dbName of [INTERNAL_EVENTS_DB_NAME, EXTERNAL_EVENTS_DB_NAME]) {
    try {
      const { pages } = await notion.queryDatabase(dbName);
      allPages.push(...pages);
    } catch (err) {
      log(`Events fetch failed (${dbName}):`, err.message);
    }
  }

  const events = [];
  for (const page of allPages) {
    if (notion.isDoneStage(notion.pagePropText(page, "Stage"))) continue;
    const itemDate = notion.pageDateISO(page, "Date");
    const recurrenceText = recurrenceTextFromPage(page);
    if (!eventMatchesDate(itemDate, recurrenceText, dateISO)) continue;
    const rule = parseRecurrence(recurrenceText);
    events.push({
      title: notion.pageTitle(page),
      type: notion.pagePropText(page, "Event type"),
      recurrence: rule ? describeRecurrence(rule) : "",
    });
  }
  return events;
}

async function fetchEventsBetween(startISO, endISO) {
  const allPages = [];
  for (const dbName of [INTERNAL_EVENTS_DB_NAME, EXTERNAL_EVENTS_DB_NAME]) {
    try {
      const { pages } = await notion.queryDatabase(dbName);
      allPages.push(...pages);
    } catch (err) {
      log(`Events fetch failed (${dbName}):`, err.message);
    }
  }

  const events = [];
  for (const page of allPages) {
    if (notion.isDoneStage(notion.pagePropText(page, "Stage"))) continue;
    const itemDate = notion.pageDateISO(page, "Date");
    const recurrenceText = recurrenceTextFromPage(page);
    const dates = collectEventOccurrences(itemDate, recurrenceText, startISO, endISO);
    if (!dates.length) continue;
    const type = notion.pagePropText(page, "Event type");
    const rule = parseRecurrence(recurrenceText);
    const recurrenceLabel = rule ? describeRecurrence(rule) : "";
    const title = notion.pageTitle(page);
    for (const d of dates) {
      events.push({ title, date: d, type, recurrence: recurrenceLabel });
    }
  }
  return events.sort((a, b) => a.date.localeCompare(b.date) || a.title.localeCompare(b.title));
}

function addDaysISO(iso, days, tz = RECAP_TZ) {
  const [y, m, d] = iso.split("-").map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d + days, 12));
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: tz,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(dt);
}

function resolveScheduleRangeForToday(range) {
  return resolveScheduleRange(range, todayISO(), RECAP_TZ);
}

/** Match a Notion campsite/meals page against a calendar day (by Day ID or Date). */
function pageMatchesDay(page, dateISO) {
  const dayId = notion.pagePropText(page, "Day ID").trim();
  const date = notion.pageDateISO(page, "Date");
  return date === dateISO || dayId === dateISO;
}

/**
 * Campsite occupancy for a calendar day from Notion.
 * Rows mirror meals: Checking in / Checking out / Staying text lists.
 */
async function fetchCampsiteActivityForDay(dateISO) {
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(CAMPSITE_BOOKINGS_DB_NAME));
  } catch (err) {
    log("Campsite bookings database unavailable:", err.message);
    return { arrivals: [], departures: [], staying: [] };
  }

  const linesFromDetail = (text) =>
    String(text || "")
      .split("\n")
      .map((s) => s.trim())
      .filter(Boolean)
      .map((line) => ({ line, name: line.split(" · ")[0] || line }));

  for (const page of pages) {
    if (!pageMatchesDay(page, dateISO)) continue;
    const arrivals = linesFromDetail(notion.pagePropText(page, "Checking in"));
    const departures = linesFromDetail(notion.pagePropText(page, "Checking out"));
    const staying = linesFromDetail(notion.pagePropText(page, "Staying"));
    return { arrivals, departures, staying };
  }

  return { arrivals: [], departures: [], staying: [] };
}

async function fetchCampsiteMealsForDay(dateISO) {
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(CAMPSITE_MEALS_DB_NAME));
  } catch (err) {
    log("Campsite meals database unavailable:", err.message);
    return null;
  }

  for (const page of pages) {
    if (!pageMatchesDay(page, dateISO)) continue;

    const breakfastCook = Number(notion.pagePropText(page, "Breakfast cooks") || 0) || 0;
    const dinnerCook = Number(notion.pagePropText(page, "Dinner cooks") || 0) || 0;
    const breakfastDetail = notion.pagePropText(page, "Breakfast detail");
    const dinnerDetail = notion.pagePropText(page, "Dinner detail");
    const weekdayRaw = notion.pagePropText(page, "Weekday dinner?").toLowerCase();
    const isWeekday =
      weekdayRaw === "true"
        ? true
        : weekdayRaw === "false"
          ? false
          : null;

    return {
      breakfastCook,
      dinnerCook,
      breakfastDetail,
      dinnerDetail,
      isWeekday,
      name: notion.pageTitle(page),
    };
  }
  return {
    breakfastCook: 0,
    dinnerCook: 0,
    breakfastDetail: "",
    dinnerDetail: "",
    isWeekday: null,
    name: "",
  };
}

function formatCampsiteSnapshot(dateISO, activity, meals) {
  const lines = ["Campsite bookings (Notion):"];
  if (activity.arrivals.length) {
    lines.push(`Checking in (${activity.arrivals.length}):`);
    for (const b of activity.arrivals) lines.push(`- ${b.line}`);
  } else {
    lines.push("Checking in: none");
  }
  if (activity.departures.length) {
    lines.push(`Checking out (${activity.departures.length}):`);
    for (const b of activity.departures) lines.push(`- ${b.line}`);
  } else {
    lines.push("Checking out: none");
  }
  lines.push(
    `On site tonight: ${activity.staying.length} pitch${activity.staying.length === 1 ? "" : "es"}`
  );
  if (activity.staying.length) {
    for (const b of activity.staying) lines.push(`- ${b.line}`);
  }

  lines.push("", "Meals (Notion):");
  if (!meals) {
    lines.push("- (meals database not available)");
  } else {
    lines.push(`Breakfast cooks: ${meals.breakfastCook}`);
    if (meals.breakfastDetail) {
      for (const line of meals.breakfastDetail.split("\n").filter(Boolean)) {
        lines.push(`  - ${line}`);
      }
    }
    if (meals.isWeekday === false) {
      lines.push("Dinner: weekend — no communal dinner");
    } else {
      lines.push(`Dinner cooks: ${meals.dinnerCook}`);
      if (meals.dinnerDetail) {
        for (const line of meals.dinnerDetail.split("\n").filter(Boolean)) {
          lines.push(`  - ${line}`);
        }
      }
    }
  }
  return lines.join("\n");
}

async function buildScheduleSnapshot(question, { scheduleRange } = {}) {
  const { start, end, label } = resolveScheduleRangeForToday(scheduleRange || "two_weeks");

  const lines = [label, ""];

  if (start === end) {
    lines.push(await buildDataSnapshot(start).then((s) => s.split("\n").slice(2).join("\n")));
  } else {
    lines.push("Events (on Notion):");
    const events = await fetchEventsBetween(start, end);
    if (events.length) {
      for (const e of events) {
        const recur = e.recurrence ? ` — ${e.recurrence}` : "";
        lines.push(`- ${e.date}: ${e.title}${e.type ? ` (${e.type})` : ""}${recur}`);
      }
    } else {
      lines.push("- (none in this period)");
    }
    lines.push("", "Chores:");
    let day = start;
    let anyChores = false;
    while (day <= end) {
      const chores = await fetchRotaForDay(day);
      if (chores.length) {
        anyChores = true;
        lines.push(`${day}:`);
        for (const c of chores) lines.push(`  - ${c.chore}: ${c.assignee}`);
      }
      day = addDaysISO(day, 1);
    }
    if (!anyChores) lines.push("- (none on rota in this period)");

    lines.push("", "Campsite check-ins / meals:");
    day = start;
    let anyCampsite = false;
    while (day <= end) {
      const activity = await fetchCampsiteActivityForDay(day);
      const meals = await fetchCampsiteMealsForDay(day);
      const hasActivity =
        activity.arrivals.length ||
        activity.departures.length ||
        activity.staying.length ||
        (meals && (meals.breakfastCook || meals.dinnerCook));
      if (hasActivity) {
        anyCampsite = true;
        lines.push(`${day}:`);
        if (activity.arrivals.length) {
          lines.push(`  check-in: ${activity.arrivals.map((b) => b.line).join("; ")}`);
        }
        if (activity.departures.length) {
          lines.push(`  check-out: ${activity.departures.map((b) => b.line).join("; ")}`);
        }
        if (activity.staying.length) {
          lines.push(`  on site: ${activity.staying.length}`);
        }
        if (meals) {
          lines.push(
            `  meals: breakfast ${meals.breakfastCook}` +
              (meals.isWeekday === false ? ", dinner (weekend off)" : `, dinner ${meals.dinnerCook}`)
          );
        }
      }
      day = addDaysISO(day, 1);
    }
    if (!anyCampsite) lines.push("- (no campsite bookings/meals in this period)");
  }
  return lines.join("\n");
}

async function buildDataSnapshot(dateISO) {
  const chores = await fetchRotaForDay(dateISO);
  const events = await fetchEventsForDate(dateISO);
  const activity = await fetchCampsiteActivityForDay(dateISO);
  const meals = await fetchCampsiteMealsForDay(dateISO);
  const dayName = new Intl.DateTimeFormat("en-GB", {
    timeZone: RECAP_TZ,
    weekday: "long",
    day: "numeric",
    month: "long",
  }).format(new Date(dateISO + "T12:00:00"));

  const lines = [`Date: ${dateISO} (${dayName})`, "", "Today's chores:"];
  if (chores.length) {
    for (const c of chores) lines.push(`- ${c.chore}: ${c.assignee}`);
  } else {
    lines.push("- (none assigned)");
  }
  lines.push("", "Events on this date:");
  if (events.length) {
    for (const e of events) {
      const recur = e.recurrence ? ` — ${e.recurrence}` : "";
      lines.push(`- ${e.title}${e.type ? ` (${e.type})` : ""}${recur}`);
    }
  } else {
    lines.push("- (none)");
  }
  lines.push("", formatCampsiteSnapshot(dateISO, activity, meals));
  return lines.join("\n");
}

async function answerFromNotionData(question) {
  const snapshot = await buildScheduleSnapshot(question);
  return openaiLib.answerFromData(question, snapshot);
}

async function buildDailyRecap(dateISO) {
  const events = await fetchEventsForDate(dateISO);
  const yesterday = yesterdayISO();
  const yesterdayHighlights = await buildYesterdayHighlights(yesterday);
  let activity = { arrivals: [], departures: [], staying: [] };
  let meals = null;
  try {
    activity = await fetchCampsiteActivityForDay(dateISO);
    meals = await fetchCampsiteMealsForDay(dateISO);
  } catch (err) {
    log("Campsite Notion fetch for recap failed:", err.message);
  }
  const dayName = new Intl.DateTimeFormat("en-GB", {
    timeZone: RECAP_TZ,
    weekday: "long",
    day: "numeric",
    month: "long",
  }).format(new Date(dateISO + "T12:00:00"));

  const lines = [`Good morning! Here's ${dayName}:`, ""];
  lines.push("<b>Events today</b>");
  if (events.length) {
    for (const e of events) {
      const recur = e.recurrence ? ` — ${escapeHtml(e.recurrence)}` : "";
      lines.push(`• ${escapeHtml(e.title)}${e.type ? ` (${escapeHtml(e.type)})` : ""}${recur}`);
    }
  } else {
    lines.push("• (nothing scheduled)");
  }

  lines.push("", "<b>Campsite today</b>");
  if (activity.arrivals.length) {
    lines.push(`<i>Checking in (${activity.arrivals.length})</i>`);
    for (const b of activity.arrivals) {
      lines.push(`• ${escapeHtml(b.line)}`);
    }
  } else {
    lines.push("• No check-ins today");
  }
  if (activity.departures.length) {
    lines.push(`<i>Checking out (${activity.departures.length})</i>`);
    for (const b of activity.departures) {
      lines.push(`• ${escapeHtml(b.line)}`);
    }
  } else {
    lines.push("• No check-outs today");
  }
  lines.push(
    `• On site tonight: ${escapeHtml(String(activity.staying.length))} pitch${
      activity.staying.length === 1 ? "" : "es"
    }`
  );

  if (meals) {
    lines.push("", "<b>Meals today</b>");
    lines.push(`• Breakfast: ${escapeHtml(String(meals.breakfastCook))} to cook`);
    if (meals.breakfastDetail) {
      for (const line of meals.breakfastDetail.split("\n").filter(Boolean).slice(0, 6)) {
        lines.push(`  ${escapeHtml(line)}`);
      }
    }
    if (meals.isWeekday === false) {
      lines.push("• Dinner: (weekend — no communal dinner)");
    } else {
      lines.push(`• Dinner: ${escapeHtml(String(meals.dinnerCook))} to cook`);
      if (meals.dinnerDetail) {
        for (const line of meals.dinnerDetail.split("\n").filter(Boolean).slice(0, 6)) {
          lines.push(`  ${escapeHtml(line)}`);
        }
      }
    }
  }

  if (yesterdayHighlights.length) {
    lines.push("", "<b>Yesterday's highlights</b>");
    for (const highlight of yesterdayHighlights) {
      lines.push(`• ${escapeHtml(highlight)}`);
    }
  }
  const template = lines.join("\n");
  try {
    const plain = template.replace(/<[^>]+>/g, "");
    const friendly = await openaiLib.composeRecap(
      plain +
        (yesterdayHighlights.length
          ? "\n\nRewrite the 'Yesterday's highlights' bullets naturally, e.g. " +
            "'Yesterday Sam said the shower light is broken.' Keep all facts."
          : "")
    );
    return friendly.split("\n").map(escapeHtml).join("\n");
  } catch {
    return template;
  }
}

async function maybeSendDailyRecap() {
  if (!DAILY_RECAP || !TELEGRAM_CHAT_ID) return;
  const today = todayISO();
  if (state.lastRecapDate === today) return;
  if (currentHHMM() < RECAP_TIME) return;

  try {
    const recap = await buildDailyRecap(today);
    await sendMessage(TELEGRAM_CHAT_ID, recap);
    state.lastRecapDate = today;
    saveState();
    log(`Daily recap sent for ${today}`);
    await maybeSendPersonalMorningBriefings(today);
  } catch (err) {
    log("Daily recap error:", err.message);
  }
}

// Log a documentation gap when /ask finds nothing.
async function createDocGapTask({ question, from, chatId }) {
  const name = `Document: ${question}`.slice(0, 200);
  const notes =
    `Auto-logged documentation gap.\n` +
    `Asked by ${senderDisplayName(from)} (${senderHandle(from)}) via Telegram.\n` +
    `Chat: ${chatId}\n\n` +
    `Question: ${question}`;

  const page = await notion.createPage({
    databaseName: TASKS_DB_NAME,
    title: name,
    properties: {
      Status: "Not started",
      Type: "Villager",
      "Created by": senderDisplayName(from),
      Notes: notes,
    },
  });

  markPageSeen(page.id, {
    stage: "",
    status: "Not started",
    doer: "",
    done: false,
  });
  return page.id;
}

// ── /ask: retrieval-augmented answers over the Notion docs ──────────────────
let docsIndexCache = { index: null, mtime: 0 };

function getDocsIndex() {
  const mtime = docsIndex.fileMtime();
  if (!docsIndexCache.index || mtime !== docsIndexCache.mtime) {
    docsIndexCache = { index: docsIndex.loadIndex(), mtime };
  }
  return docsIndexCache.index;
}

function setDocsIndex(index) {
  docsIndex.saveIndex(index);
  docsIndexCache = { index, mtime: docsIndex.fileMtime() };
}

async function indexDocumentationInSearch({ id, name, url, text }) {
  if (!openaiLib.isEnabled() || !text?.trim()) return;
  try {
    const texts = docsIndex.chunkText(text.trim());
    if (!texts.length) return;
    const vectors = await openaiLib.embed(texts);
    const pieces = texts.map((t, i) => ({ text: t, embedding: vectors[i] }));
    const current = getDocsIndex();
    const updated = docsIndex.upsertDocChunks(current, { id, name, url, pieces });
    updated.model = openaiLib.EMBED_MODEL;
    setDocsIndex(updated);
    log(`Docs index updated: ${name} (${pieces.length} chunk(s), ${updated.chunks.length} total)`);
  } catch (err) {
    log("indexDocumentationInSearch error:", err.message);
  }
}

const ASK_MIN_SCORE = Number(process.env.ASK_MIN_SCORE || 0.28);
const ASK_MIN_KEYWORD_SCORE = Number(process.env.ASK_MIN_KEYWORD_SCORE || 0.34);

async function answerQuestion(question) {
  if (!openaiLib.isEnabled()) {
    return { available: false };
  }
  const index = getDocsIndex();
  if (!index.chunks.length) {
    return { available: true, indexed: false, answered: false };
  }

  const queryEmbedding = await openaiLib.embedOne(question);
  let hits = docsIndex.search(index, queryEmbedding, 4);
  let top = hits[0];
  let usedKeyword = false;

  if (!top || top.score < ASK_MIN_SCORE) {
    const kwHits = docsIndex.keywordSearch(index, question, 4);
    if (kwHits[0]?.score >= ASK_MIN_KEYWORD_SCORE) {
      hits = kwHits;
      top = kwHits[0];
      usedKeyword = true;
    }
  }

  const minScore = usedKeyword ? ASK_MIN_KEYWORD_SCORE : ASK_MIN_SCORE;
  if (!top || top.score < minScore) {
    return { available: true, indexed: true, answered: false };
  }

  const result = await openaiLib.answerFromDocs(
    question,
    hits.map((h) => ({ docName: h.docName, url: h.url, text: h.text }))
  );

  return {
    available: true,
    indexed: true,
    answered: result.answered,
    answer: result.answer,
    sources: hits
      .filter((h) => result.sources?.includes(h.docName) || result.sources?.length === 0)
      .map((h) => ({ name: h.docName, url: h.url })),
    topSource: { name: top.docName, url: top.url },
  };
}

// ── Resolve which Notion DB a page id belongs to ─────────────────────────────
async function resolveDbNameById(databaseId) {
  if (!databaseId) return null;
  const candidates = [
    ...new Set([
      ...WATCH_DB_NAMES,
      INTERNAL_EVENTS_DB_NAME,
      EXTERNAL_EVENTS_DB_NAME,
      REMINDERS_DB_NAME,
      PARTNERSHIPS_DB_NAME,
      TASKS_DB_NAME,
      RECURRING_DB_NAME,
      JOBS_DB_NAME,
    ]),
  ];
  for (const name of candidates) {
    try {
      const db = await notion.resolveDatabase(name);
      if (db.id === databaseId) return name;
    } catch {
      // ignore missing DBs
    }
  }
  return null;
}

/** Stage label that marks a page complete for a given database. */
function doneStageFor(dbName) {
  if (dbName === INTERNAL_EVENTS_DB_NAME || dbName === EXTERNAL_EVENTS_DB_NAME) return "Done / past";
  if (dbName === RECURRING_DB_NAME) return "Done";
  return "Done";
}

// ── Telegram -> Notion: register a member ────────────────────────────────────
async function findMemberByTelegram(handle) {
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(MEMBERS_DB_NAME));
  } catch (err) {
    log("Members fetch failed:", err.message);
    return { page: null };
  }
  const lower = String(handle || "").toLowerCase();
  for (const page of pages) {
    const uname = notion.pagePropText(page, "Telegram username").toLowerCase();
    if (uname && uname === lower) return { page };
  }
  return { page: null };
}

async function fetchMembersForMorningDm() {
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(MEMBERS_DB_NAME));
  } catch (err) {
    log("Members fetch for morning DM failed:", err.message);
    return [];
  }

  return pages
    .map((page) => {
      const stage = notion.pagePropText(page, "Stage");
      const status = notion.pagePropText(page, "Member status");
      if (stage === "Left / inactive" || status === "Inactive") return null;
      const userId = notion.pagePropText(page, "Telegram user ID").trim();
      if (!userId || !/^\d+$/.test(userId)) return null;
      const username = notion.pagePropText(page, "Telegram username");
      const displayName =
        notion.pagePropText(page, "Display name") || notion.pageTitle(page);
      return { userId, username, displayName };
    })
    .filter(Boolean);
}

function memberAsTelegramUser(member) {
  return {
    id: Number(member.userId),
    username: String(member.username || "").replace(/^@/, "") || undefined,
    first_name: member.displayName,
  };
}

async function maybeSendPersonalMorningBriefings(dateISO) {
  if (!DAILY_RECAP) return;
  if (state.lastPersonalRecapDate === dateISO) return;
  if (currentHHMM() < RECAP_TIME) return;

  const members = await fetchMembersForMorningDm();
  let sent = 0;
  for (const member of members) {
    try {
      const from = memberAsTelegramUser(member);
      const brief = await buildMyDayBriefing(from, dateISO);
      if (!brief.duties.length && !brief.tasks.length) continue;
      await sendMessage(member.userId, formatMyDayBriefing(brief));
      sent += 1;
    } catch (err) {
      log(`Personal morning briefing failed for ${member.displayName}:`, err.message);
    }
  }

  state.lastPersonalRecapDate = dateISO;
  saveState();
  if (sent) log(`Personal morning briefings sent: ${sent}`);
}

async function registerMember({ displayName, from, chatId }) {
  const handle = senderHandle(from);
  const { page } = await findMemberByTelegram(handle);

  const properties = {
    "Display name": displayName,
    "Telegram username": handle,
    "Telegram user ID": String(from.id),
    "Telegram chat ID": String(chatId),
    "Active?": true,
  };

  if (page) {
    await notion.updatePageProps(MEMBERS_DB_NAME, page.id, properties);
    return { updated: true };
  }

  await notion.createPage({
    databaseName: MEMBERS_DB_NAME,
    title: displayName,
    properties: { Stage: "Onboarding", ...properties },
  });
  return { updated: false };
}

// ── Telegram -> Notion: list my open tasks ───────────────────────────────────
async function listMyTasks(from) {
  const brief = await buildMyDayBriefing(from, todayISO());
  const matches = [
    ...brief.duties.map((d) => ({
      id: d.id,
      name: `${d.name}${d.job ? ` [${d.job}]` : ""}`,
      board: "Recurring Duties",
      status: `due · ${d.frequency}`,
    })),
    ...brief.tasks.map((t) => ({
      id: t.id,
      name: t.name,
      board: "Tasks",
      status: t.status,
    })),
  ];
  return { displayName: brief.displayName, matches, brief };
}

// ── Telegram -> Notion: mark done ─────────────────────────────────────────────
async function markItemDone(pageId) {
  let page;
  try {
    page = await notion.notion("GET", `/pages/${pageId}`);
  } catch {
    return { ok: false };
  }
  if (!page || page.object !== "page") return { ok: false };

  const dbName = await resolveDbNameById(page.parent?.database_id);
  if (!dbName) return { ok: false };

  const name = notion.pageTitle(page);

  // Recurring duties: done for this cycle → log day + schedule next due (stay Active)
  if (dbName === RECURRING_DB_NAME) {
    const completed = todayISO();
    const frequency = notion.pagePropText(page, "Frequency") || "Daily";
    const nextDue = nextDueAfterCompletion(frequency, completed);
    const logText = appendCompletionLog(
      notion.pagePropText(page, "Completion log"),
      completed,
      "marked done via VAVA"
    );
    await notion.updatePageProps(RECURRING_DB_NAME, pageId, {
      Status: "Active",
      "Last completed": completed,
      "Next due date": nextDue,
      "Completion log": logText,
    });
    state.items[pageId] = {
      stage: "",
      status: "Active",
      doer: "",
      done: false, // still an active duty; just not due until nextDue
    };
    saveState();
    return {
      ok: true,
      name,
      boardName: dbName,
      nextDue,
      frequency,
      dutyCompleted: true,
    };
  }

  const db = await notion.resolveDatabase(dbName);
  const map = {};
  if (notion.prop(db, "Stage")) map.Stage = doneStageFor(dbName);
  if (notion.prop(db, "Status")) {
    map.Status = dbName === TASKS_DB_NAME ? "Done" : "Done";
  }

  if (Object.keys(map).length) {
    await notion.updatePageProps(dbName, pageId, map);
  }
  state.items[pageId] = {
    stage: map.Stage || "",
    status: map.Status || "",
    doer: "",
    done: true,
  };
  saveState();
  return { ok: true, name, boardName: dbName };
}

function titleMatches(query, itemName) {
  const q = String(query || "").toLowerCase().trim();
  const n = String(itemName || "").toLowerCase();
  if (!q || !n) return false;
  if (n.includes(q) || q.includes(n)) return true;
  const qWords = q.split(/\s+/).filter((w) => w.length > 2);
  if (!qWords.length) return false;
  const hits = qWords.filter((w) => n.includes(w));
  return hits.length >= Math.min(2, qWords.length);
}

async function findItemsByTarget({ dbNames, target }) {
  if (target?.item_id) {
    let page;
    try {
      page = await notion.notion("GET", `/pages/${target.item_id}`);
    } catch {
      return [];
    }
    if (!page || page.object !== "page") return [];
    if (pageIsDone(page)) return [];
    const dbName = await resolveDbNameById(page.parent?.database_id);
    return [{ id: page.id, name: notion.pageTitle(page), boardName: dbName || "" }];
  }

  const title = target?.title;
  if (!title) return [];

  const matches = [];
  for (const dbName of dbNames) {
    let pages;
    try {
      ({ pages } = await notion.queryDatabase(dbName));
    } catch {
      continue;
    }
    for (const page of pages) {
      if (dbName === RECURRING_DB_NAME) {
        const last = notion.pageDateISO(page, "Last completed");
        if (last && last === todayISO()) continue; // already logged done today
        if (notion.pagePropText(page, "Status") === "Paused") continue;
      } else if (pageIsDone(page)) {
        continue;
      }
      const name = notion.pageTitle(page);
      if (titleMatches(title, name)) {
        matches.push({ id: page.id, name, boardName: dbName });
      }
    }
  }
  return matches;
}

async function completeTaskFromChat({ target, text, from, chatId }) {
  const key = ambientDedupeKey(chatId, "complete", target.title || target.item_id);
  if (wasRecentlyCaptured(key)) return { ok: true, duplicate: true };

  const matches = await findItemsByTarget({
    dbNames: [...new Set([...WATCH_DB_NAMES, TASKS_DB_NAME, RECURRING_DB_NAME])],
    target,
  });
  if (!matches.length) return { ok: false, reason: "not_found" };
  if (matches.length > 1) return { ok: false, reason: "ambiguous", matches };

  const result = await markItemDone(matches[0].id);
  if (!result.ok) return { ok: false, reason: "not_found" };

  markCaptured(key);
  recordChatActivity({
    kind: "task_completed",
    from,
    chatId,
    title: result.name,
    text,
  });
  return {
    ok: true,
    name: result.name,
    boardName: matches[0].boardName,
    nextDue: result.nextDue,
    dutyCompleted: result.dutyCompleted,
    frequency: result.frequency,
  };
}

async function cancelEventFromChat({ target, text, from, chatId }) {
  const key = ambientDedupeKey(chatId, "cancel", target.title || target.item_id);
  if (wasRecentlyCaptured(key)) return { ok: true, duplicate: true };

  const matches = await findItemsByTarget({
    dbNames: [INTERNAL_EVENTS_DB_NAME, EXTERNAL_EVENTS_DB_NAME],
    target,
  });
  if (!matches.length) return { ok: false, reason: "not_found" };
  if (matches.length > 1) return { ok: false, reason: "ambiguous", matches };

  const result = await markItemDone(matches[0].id);
  if (!result.ok) return { ok: false, reason: "not_found" };

  markCaptured(key);
  recordChatActivity({
    kind: "event_cancelled",
    from,
    chatId,
    title: result.name,
    text,
  });
  return { ok: true, name: result.name };
}

// ── RSVP to an event ──────────────────────────────────────────────────────────
async function rsvpEvent({ title, displayName, from, chatId }) {
  const dbNames = [INTERNAL_EVENTS_DB_NAME, EXTERNAL_EVENTS_DB_NAME];
  for (const dbName of dbNames) {
    let pages;
    try {
      ({ pages } = await notion.queryDatabase(dbName));
    } catch { continue; }
    for (const page of pages) {
      if (notion.isDoneStage(notion.pagePropText(page, "Stage"))) continue;
      if (!titleMatches(title, notion.pageTitle(page))) continue;
      const existing = notion.pagePropText(page, "Guest list");
      if (existing.toLowerCase().includes(displayName.toLowerCase())) {
        return { ok: true, already: true, name: notion.pageTitle(page) };
      }
      const updated = existing ? `${existing}\n${displayName}` : displayName;
      await notion.updatePageProps(dbName, page.id, { "Guest list": updated });
      return { ok: true, already: false, name: notion.pageTitle(page) };
    }
  }
  return { ok: false };
}

// ── Create a reminder ────────────────────────────────────────────────────────
async function createReminder({ title, dueDate, notes, from, chatId }) {
  const props = {
    "Created by": senderDisplayName(from),
    "Telegram user": senderHandle(from),
    Source: "Telegram",
  };
  if (dueDate) props["Due date"] = dueDate;
  if (notes) props.Notes = notes;

  const page = await notion.createPage({
    databaseName: REMINDERS_DB_NAME,
    title: String(title || "Reminder").slice(0, 200),
    properties: props,
  });
  markPageSeen(page.id, { stage: "", status: "", doer: "", done: false });
  return { itemId: page.id, url: page.url };
}

// ── Add note to existing task/project ─────────────────────────────────────────
async function addNoteToPage({ targetTitle, noteText, from, chatId }) {
  const dbNames = [TASKS_DB_NAME];
  try { await notion.resolveDatabase("Projects"); dbNames.push("Projects"); } catch {}

  for (const dbName of dbNames) {
    let pages;
    try {
      ({ pages } = await notion.queryDatabase(dbName));
    } catch { continue; }
    for (const page of pages) {
      if (!titleMatches(targetTitle, notion.pageTitle(page))) continue;
      const existing = notion.pagePropText(page, "Notes");
      const stamp = `\n\n[${senderDisplayName(from)}, ${todayISO()}]: ${noteText}`;
      const updated = existing ? `${existing}${stamp}` : stamp.trim();
      await notion.updatePageProps(dbName, page.id, { Notes: updated });
      return { ok: true, name: notion.pageTitle(page), dbName };
    }
  }
  return { ok: false };
}

// ── Partnerships DB light query ───────────────────────────────────────────────
async function answerFromPartnerships(question) {
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(PARTNERSHIPS_DB_NAME));
  } catch { return null; }
  if (!pages.length) return null;
  const lines = pages.map((p) => {
    const name = notion.pageTitle(p);
    const notes = notion.pagePropText(p, "Notes");
    const discount = notion.pagePropText(p, "Discount");
    return `- ${name}${discount ? ` (${discount})` : ""}${notes ? `: ${notes}` : ""}`;
  });
  const snapshot = `Partnerships:\n${lines.join("\n")}`;
  return openaiLib.answerFromData(question, snapshot);
}

// ── Pending clarification for incomplete tasks ─────────────────────────────────
const CLARIFY_TIMEOUT_MS = 10 * 60 * 1000;

async function handlePendingClarification(message) {
  const chatId = message.chat.id;
  const fromId = message.from?.id;
  const key = `${chatId}:${fromId}`;
  const pending = state.pendingClarify?.[key];
  if (!pending) return false;
  if (Date.now() - pending.ts > CLARIFY_TIMEOUT_MS) {
    delete state.pendingClarify[key];
    saveState();
    return false;
  }

  const text = (message.text || "").trim().toLowerCase();
  if (!text) return false;

  const partial = pending.partial;
  if (pending.kind === "task_type_priority") {
    const isVillager = /villager|village/i.test(text);
    const isMgmt = /management|professional|arranging/i.test(text);
    if (isVillager) partial.type = "Villager";
    else if (isMgmt) partial.type = "Management";
    if (/\blow\b/i.test(text)) partial.priority = "Low";
    else if (/\baverage\b|\bmedium\b|\bnormal\b/i.test(text)) partial.priority = "Average";
    else if (/\bhigh\b|\burgent\b/i.test(text)) partial.priority = "High";
  }

  try {
    const result = await createTaskFromClassification({
      classified: {
        action: "create_task",
        task: partial,
      },
      text: partial.sourceText || partial.title,
      from: message.from,
      chatId,
    });
    let reply = `📋 Logged: <b>${escapeHtml(partial.title)}</b>`;
    if (partial.type) reply += ` (${escapeHtml(partial.type)})`;
    if (result.url) reply += ` — <a href="${result.url}">Notion</a>`;
    await sendMessage(chatId, reply);
  } catch (err) {
    log("handlePendingClarification error:", err.message);
    await sendMessage(chatId, "Sorry, I could not save that task.");
  }

  delete state.pendingClarify[key];
  saveState();
  return true;
}

// ── Telegram command + message handling ───────────────────────────────────────
const HELP_TEXT = [
  "<b>Vila Akoya bot (VAVA)</b>",
  "",
  "<b>Group</b>: tag me (@bot), reply to me, or say <b>Vava</b> (or start a voice note with <b>robot</b>, <b>bot</b>, <b>vila akoya bot</b>, or <b>personal assistant</b>).",
  "<b>DMs</b>: always on, no tag needed.",
  "",
  "I can log tasks (villager work or management jobs), events, reminders, RSVP to events, add notes to projects, answer questions from docs and schedule, and more.",
  "Voice notes are transcribed — explain how something works to create a structured doc.",
  "",
  "<b>Task types</b>",
  "• <i>Villager</i> — village work anyone can pitch in on",
  "• <i>Management</i> — needs a professional or arranging",
  "",
  "<b>Commands</b>",
  "/task &lt;text&gt; — add a task",
  "/ask &lt;question&gt; — search our documentation",
  "/register &lt;your name&gt; — link your Telegram to Members (needed for “what should I do today”)",
  "/mytasks — duties due today + open tasks assigned to you",
  "/done &lt;item id&gt; — mark a task done, or log a duty done (schedules next due)",
  "/whoami — show your Telegram details",
  "/help — this message",
].join("\n");

async function classifyUserMessage(text, chatId, { interactive = false } = {}) {
  if (!text || !openaiLib.isEnabled()) return null;
  try {
    const contextCount = interactive ? 10 : 6;
    const buf = (recentMessages.get(chatId) || []).slice(-contextCount);
    const contextLines = buf
      .map((m) => m.text)
      .filter((t) => t && t !== text);
    const cleanText = stripBotMention(text, botMe) || text;
    const payload =
      contextLines.length > 0
        ? `Recent messages in the group:\n${contextLines.join("\n")}\n\nLatest message to classify:\n${cleanText}`
        : cleanText;
    const classified = await openaiLib.classifyGroupMessage(payload, todayISO());
    log(
      `AI classify: action=${classified.action} event=${classified.is_event} ` +
        `task=${classified.is_task} question=${classified.is_question} ` +
        `schedule_range=${classified.schedule_range || "-"} conf=${classified.confidence}`
    );
    return mergeCompletionIntent(classified, cleanText, chatId);
  } catch (err) {
    log("classifyGroupMessage error:", err.message);
    return null;
  }
}

async function handleClassifiedQuestion({ text, questionKind, from, chatId, interactive, scheduleRange }) {
  const logDocGaps = interactive && LOG_DOC_GAPS;

  if (questionKind === "my_day" || looksLikeMyDayQuestion(text)) {
    try {
      await sendMyDayBriefing(from, chatId, todayISO());
      return true;
    } catch (err) {
      log("my day briefing error:", err.message);
      if (interactive) {
        await sendMessage(chatId, "Sorry, I could not build your day list right now.");
      }
      return interactive;
    }
  }

  if (questionKind === "schedule") {
    const rangeOpts = { scheduleRange: scheduleRange || "two_weeks" };
    const calendar = buildWeekCalendarContext(todayISO(), RECAP_TZ);
    try {
      const snapshot = await buildScheduleSnapshot(text, rangeOpts);
      const dataResult = await openaiLib.answerFromData(
        text,
        `${calendar}\n\n${snapshot}`
      );
      if (dataResult.answered && dataResult.answer) {
        await sendMessage(chatId, escapeHtml(dataResult.answer));
        log(`Schedule answer (AI): ${text.slice(0, 60)}`);
        return true;
      }
    } catch (err) {
      log("answerFromNotionData error:", err.message);
    }
    try {
      const snapshot = await buildScheduleSnapshot(text, rangeOpts);
      await sendMessage(chatId, `<pre>${escapeHtml(snapshot)}</pre>`);
      log(`Schedule answer (snapshot): ${text.slice(0, 60)}`);
      return true;
    } catch (err) {
      log("schedule answer error:", err.message);
    }
    return false;
  }

  if (questionKind === "documentation") {
    const docResult = await handleAsk({ question: text, from, chatId, logGap: logDocGaps });
    if (docResult.answered) return true;
    if (/partner|discount|affiliate/i.test(text)) {
      try {
        const pResult = await answerFromPartnerships(text);
        if (pResult?.answered && pResult.answer) {
          await sendMessage(chatId, escapeHtml(pResult.answer));
          return true;
        }
      } catch (err) {
        log("answerFromPartnerships error:", err.message);
      }
    }
    return false;
  }

  const docResult = await handleAsk({ question: text, from, chatId, logGap: logDocGaps });
  if (docResult.answered) return true;
  if (/partner|discount|affiliate/i.test(text)) {
    try {
      const pResult = await answerFromPartnerships(text);
      if (pResult?.answered && pResult.answer) {
        await sendMessage(chatId, escapeHtml(pResult.answer));
        return true;
      }
    } catch (err) {
      log("answerFromPartnerships error:", err.message);
    }
  }
  const dataResult = await answerFromNotionData(text);
  if (dataResult.answered && dataResult.answer) {
    await sendMessage(chatId, escapeHtml(dataResult.answer));
    return true;
  }
  return false;
}

async function captureAmbientDocumentation({ text, from, chatId, interactive }) {
  const key = ambientDedupeKey(chatId, "doc", text.slice(0, 80));
  if (wasRecentlyCaptured(key)) return true;
  try {
    await telegram("sendChatAction", { chat_id: chatId, action: "typing" }).catch(() => {});
    const { doc, structured } = await createDocumentation({
      rawText: text,
      from,
      chatId,
      sourceText: text,
    });
    markCaptured(key);
    recordChatActivity({
      kind: "doc_logged",
      from,
      chatId,
      title: structured.title,
      text,
    });
    let reply = `📝 Documentation draft: <b>${escapeHtml(structured.title)}</b>`;
    if (doc.url) reply += `\n<a href="${doc.url}">Open doc in Notion</a>`;
    reply += `\n<i>Draft — ready for steward review.</i>`;
    await sendMessage(chatId, reply);
    log(`Documentation created: ${structured.title}`);
    return true;
  } catch (err) {
    log("captureAmbientDocumentation error:", err.message);
    if (interactive) {
      await sendMessage(chatId, "Sorry, I couldn't create that documentation draft.");
    }
    return false;
  }
}

async function captureAmbientTask({ classified, text, from, chatId, photoFileId }) {
  const task = classified?.task;
  const title = task?.title || text || "(photo)";
  const key = ambientDedupeKey(chatId, "task", `${classified.action}:${title}`);
  if (wasRecentlyCaptured(key)) return true;
  try {
    const { url, boardName, project } = await createTaskFromClassification({
      classified,
      text,
      from,
      chatId,
      photoFileId,
    });
    markCaptured(key);
    const activityKind =
      classified.action === "guest_issue" ? "guest_logged" : "task_logged";
    recordChatActivity({
      kind: activityKind,
      from,
      chatId,
      title,
      text,
    });
    const icon = classified.action === "guest_issue" ? "🏕️" : "📋";
    let reply = `${icon} Logged: <b>${escapeHtml(title)}</b>`;
    if (project?.name) reply += ` → project <b>${escapeHtml(project.name)}</b>`;
    else if (boardName) reply += ` (${escapeHtml(boardName)})`;
    if (url) reply += ` — <a href="${url}">Notion</a>`;
    await sendMessage(chatId, reply);
    log(`Task captured (${classified.action}): ${title}`);
    return true;
  } catch (err) {
    log("createTaskFromClassification error:", err.message);
    return false;
  }
}

/** Single AI-driven router for both @bot messages and ambient group messages. */
async function routeByAiClassification({ classified, text, from, chatId, photoFileId, interactive }) {
  if (!classified && !photoFileId) {
    if (interactive) {
      await sendMessage(
        chatId,
        "I need OPENAI_API_KEY to understand natural language. Use /task, /ask, or /help."
      );
    }
    return;
  }

  const c = classified || {
    action: "create_task",
    actionable: true,
    confidence: 1,
    is_task: true,
    task: { title: text },
  };
  const conf = c.confidence || 0;

  // Cheap path: personal agenda without waiting on classifier nuance
  if (interactive && text && looksLikeMyDayQuestion(text)) {
    await sendMyDayBriefing(from, chatId, todayISO());
    return;
  }

  if (!interactive && !c.actionable && conf < 0.4 && !photoFileId) {
    log(`Skip (chit-chat): ${text.slice(0, 80)}`);
    return;
  }

  // Questions
  if (c.is_question && text) {
    const answered = await handleClassifiedQuestion({
      text,
      questionKind: c.question_kind || "general",
      from,
      chatId,
      interactive,
      scheduleRange: c.schedule_range,
    });
    if (interactive && !answered) {
      await sendMessage(
        chatId,
        "I couldn't find that in our documentation or schedule. Try rephrasing, or use /task to log a job."
      );
    }
    return;
  }

  // Community coordination — silent in chat, surfaced in daily recap
  const communityActions = ["offer_help", "help_wanted", "availability", "announcement"];
  if (communityActions.includes(c.action) && conf >= EVENT_MIN_CONFIDENCE) {
    const title = c.community?.title || text.slice(0, 80);
    const kind = CHAT_ACTIVITY_KIND[c.action] || c.action;
    recordChatActivity({
      kind,
      from,
      chatId,
      title,
      eventDate: c.community?.date || null,
      text,
    });
    if (interactive) {
      const inRecap = RECAP_IMPORTANT_KINDS.has(kind);
      await sendMessage(
        chatId,
        inRecap
          ? "Noted — I'll include that in tomorrow's morning roundup."
          : "Noted."
      );
    }
    return;
  }

  // Documentation drafts (voice brain dumps → structured workdoc)
  if (c.is_documentation && text && conf >= AMBIENT_MIN_CONFIDENCE) {
    const ok = await captureAmbientDocumentation({ text, from, chatId, interactive });
    if (!ok && interactive) {
      await sendMessage(chatId, "Sorry, I could not create that documentation.");
    }
    return;
  }

  // Mark task complete
  if (c.is_complete && c.target && conf >= AMBIENT_MIN_CONFIDENCE) {
    try {
      const result = await completeTaskFromChat({ target: c.target, text, from, chatId });
      if (result.ok && !result.duplicate) {
        let reply = `✅ Marked done: <b>${escapeHtml(result.name)}</b>`;
        if (result.dutyCompleted && result.nextDue) {
          reply += `\nNext due <b>${escapeHtml(result.nextDue)}</b>`;
          if (result.frequency) reply += ` (${escapeHtml(result.frequency)})`;
        } else if (result.boardName) {
          reply += ` (${escapeHtml(result.boardName)})`;
        }
        await sendMessage(chatId, reply);
      } else if (result.reason === "ambiguous" && interactive) {
        const names = result.matches.map((m) => m.name).join(", ");
        await sendMessage(chatId, `Which one? I found: ${escapeHtml(names)}. Be more specific or use /done &lt;id&gt;.`);
      } else if (result.reason === "not_found" && interactive) {
        await sendMessage(chatId, "I couldn't find a matching open task in Notion.");
      }
    } catch (err) {
      log("completeTaskFromChat error:", err.message);
      if (interactive) await sendMessage(chatId, "Sorry, I could not mark that task done.");
    }
    return;
  }

  // Cancel event
  if (c.is_cancel_event && c.target && conf >= AMBIENT_MIN_CONFIDENCE) {
    try {
      const result = await cancelEventFromChat({ target: c.target, text, from, chatId });
      if (result.ok && !result.duplicate) {
        await sendMessage(chatId, `🚫 Cancelled: <b>${escapeHtml(result.name)}</b>`);
      } else if (result.reason === "ambiguous" && interactive) {
        const names = result.matches.map((m) => m.name).join(", ");
        await sendMessage(chatId, `Which event? I found: ${escapeHtml(names)}.`);
      } else if (result.reason === "not_found" && interactive) {
        await sendMessage(chatId, "I couldn't find a matching upcoming event in Notion.");
      }
    } catch (err) {
      log("cancelEventFromChat error:", err.message);
      if (interactive) await sendMessage(chatId, "Sorry, I could not cancel that event.");
    }
    return;
  }

  // Governance
  if (c.action === "create_decision" && c.decision && conf >= AMBIENT_MIN_CONFIDENCE) {
    const key = ambientDedupeKey(chatId, "decision", c.decision.title);
    if (!wasRecentlyCaptured(key)) {
      try {
        const { url } = await createDecision({
          decision: c.decision,
          from,
          chatId,
          sourceText: text,
        });
        markCaptured(key);
        recordChatActivity({ kind: "decision_logged", from, chatId, title: c.decision.title, text });
        let reply = `🗳️ Logged as a management task for discussion: <b>${escapeHtml(c.decision.title)}</b>`;
        if (url) reply += ` — <a href="${url}">Notion</a>`;
        await sendMessage(chatId, reply);
      } catch (err) {
        log("createDecision error:", err.message);
        if (interactive) await sendMessage(chatId, "Sorry, I could not log that proposal.");
      }
    }
    return;
  }

  // RSVP
  if (c.is_rsvp && c.rsvp?.title && conf >= AMBIENT_MIN_CONFIDENCE) {
    try {
      const displayName = senderDisplayName(from);
      const result = await rsvpEvent({ title: c.rsvp.title, displayName, from, chatId });
      if (result.ok) {
        await sendMessage(
          chatId,
          result.already
            ? `You're already on the guest list for <b>${escapeHtml(result.name)}</b>!`
            : `✅ Added you to the guest list for <b>${escapeHtml(result.name)}</b>.`
        );
      } else if (interactive) {
        await sendMessage(chatId, `I couldn't find an upcoming event matching "${escapeHtml(c.rsvp.title)}".`);
      }
    } catch (err) {
      log("rsvpEvent error:", err.message);
      if (interactive) await sendMessage(chatId, "Sorry, I could not add you to that event.");
    }
    return;
  }

  // Reminders
  if (c.is_reminder && c.reminder?.title && conf >= AMBIENT_MIN_CONFIDENCE) {
    try {
      const { url } = await createReminder({
        title: c.reminder.title,
        dueDate: c.reminder.due_date,
        notes: c.reminder.notes || text,
        from,
        chatId,
      });
      let reply = `⏰ Reminder set: <b>${escapeHtml(c.reminder.title)}</b>`;
      if (c.reminder.due_date) reply += ` (due ${escapeHtml(c.reminder.due_date)})`;
      if (url) reply += ` — <a href="${url}">Notion</a>`;
      await sendMessage(chatId, reply);
      recordChatActivity({ kind: "reminder_logged", from, chatId, title: c.reminder.title, text });
    } catch (err) {
      log("createReminder error:", err.message);
      if (interactive) await sendMessage(chatId, "Sorry, I could not create that reminder.");
    }
    return;
  }

  // Add note to project/task
  if (c.is_add_note && c.note?.target_title && conf >= AMBIENT_MIN_CONFIDENCE) {
    try {
      const result = await addNoteToPage({
        targetTitle: c.note.target_title,
        noteText: c.note.text || text,
        from,
        chatId,
      });
      if (result.ok) {
        await sendMessage(chatId, `📝 Added note to <b>${escapeHtml(result.name)}</b>.`);
      } else if (interactive) {
        await sendMessage(chatId, `I couldn't find a task or project matching "${escapeHtml(c.note.target_title)}".`);
      }
    } catch (err) {
      log("addNoteToPage error:", err.message);
      if (interactive) await sendMessage(chatId, "Sorry, I could not add that note.");
    }
    return;
  }

  // Events & deliveries
  if (c.is_event && c.event && conf >= EVENT_MIN_CONFIDENCE) {
    await captureAmbientEvent({ classified: c, text, from, chatId });
    return;
  }

  // Tasks & guest issues
  if (
    (c.is_task && c.task && conf >= AMBIENT_MIN_CONFIDENCE) ||
    (c.action === "guest_issue" && c.task && conf >= AMBIENT_MIN_CONFIDENCE) ||
    photoFileId
  ) {
    if (interactive && c.task && (!c.task.type || !c.task.priority)) {
      const key = `${chatId}:${from.id}`;
      state.pendingClarify[key] = {
        kind: "task_type_priority",
        partial: { ...c.task, sourceText: text },
        fromId: from.id,
        ts: Date.now(),
      };
      saveState();
      await sendMessage(
        chatId,
        `Got it — <b>${escapeHtml(c.task.title)}</b>.\nIs this a <i>villager</i> task or <i>management</i> (needs a professional/arranging)? And priority — low / average / high?`
      );
      return;
    }
    const ok = await captureAmbientTask({ classified: c, text, from, chatId, photoFileId });
    if (!ok && interactive) {
      await sendMessage(chatId, "Sorry, I could not save that task.");
    }
    return;
  }

  if (interactive && text) {
    const docResult = await handleAsk({ question: text, from, chatId, logGap: false });
    if (docResult.answered) return;

    await sendMessage(
      chatId,
      "I'm not sure what you need — try asking a question, describing a job, or mentioning an event with a date."
    );
  }
}

async function routeAddressedMessage(message) {
  const chatId = message.chat.id;
  const from = message.from;
  const photoFileId =
    message.photo && message.photo.length
      ? message.photo[message.photo.length - 1].file_id
      : null;

  let text = stripBotAddressing((message.text || message.caption || "").trim(), botMe);

  if (!text) {
    if (getVoiceFileId(message)) {
      const voiceText = await transcribeMessageVoice(message, { interactive: true });
      if (voiceText) text = stripBotAddressing(voiceText, botMe);
    }
    if (!text) text = stripBotAddressing(await resolveAddressedText(message, botMe), botMe);
  } else {
    await waitForPendingVoiceTranscriptions(chatId);
    const lastVoice = recentBufferedVoice(message);
    if (lastVoice && shouldAttachRecentVoice(text, lastVoice)) {
      text = `${text}\n\n${lastVoice.text}`;
    }
  }

  if (!text && !photoFileId) {
    await sendMessage(chatId, "What can I help with? Ask me anything, or use /help.");
    return;
  }

  // Personal agenda — no classifier needed (saves tokens)
  if (text && looksLikeMyDayQuestion(text)) {
    try {
      await sendMyDayBriefing(from, chatId, todayISO());
    } catch (err) {
      log("my day briefing error:", err.message);
      await sendMessage(chatId, "Sorry, I could not build your day list right now.");
    }
    return;
  }

  const classified = text ? await classifyUserMessage(text, chatId, { interactive: true }) : null;
  await routeByAiClassification({ classified, text, from, chatId, photoFileId, interactive: true });
}

async function handleMessage(message) {
  return withReplyThread(message, () => handleMessageInner(message));
}

async function handleMessageInner(message) {
  const chatId = message.chat.id;
  const from = message.from;
  const text = (message.text || message.caption || "").trim();

  const photoFileId =
    message.photo && message.photo.length
      ? message.photo[message.photo.length - 1].file_id
      : null;
  const voiceFileId = getVoiceFileId(message);

  log(
    `Message from ${senderHandle(from)} in chat ${chatId}` +
      (message.message_thread_id ? ` topic ${message.message_thread_id}` : "") +
      `: ${text || (voiceFileId ? "(voice)" : photoFileId ? "(photo)" : "(empty)")}`
  );
  bufferMessage(message);
  recordGroupMessage(message);

  if (!shouldHandleIntake(message)) {
    if (
      (message.chat?.type === "group" || message.chat?.type === "supergroup") &&
      isAddressedToBot(message, botMe)
    ) {
      log(
        `Ignoring @mention — chat ${chatId} is not TASK_GROUP_CHAT_ID (${TASK_GROUP_CHAT_ID}). ` +
          `Restart with TELEGRAM_CHAT_ID="${chatId}"`
      );
    }
    return;
  }

  if (text && !text.startsWith("/") && await handlePendingClarification(message)) {
    return;
  }

  const isCommand = text.startsWith("/");
  const [rawCmd, ...rest] = text.split(/\s+/);
  const cmd = rawCmd.split("@")[0].toLowerCase();
  const args = rest.join(" ").trim();

  if (isCommand && (cmd === "/start" || cmd === "/help")) {
    await sendMessage(chatId, HELP_TEXT);
    return;
  }

  if (isCommand && cmd === "/whoami") {
    await sendMessage(
      chatId,
      `Telegram handle: <b>${escapeHtml(senderHandle(from))}</b>\n` +
        `Name: ${escapeHtml(senderDisplayName(from))}\n` +
        `Your user id: <code>${from.id}</code>\n` +
        `This chat id: <code>${chatId}</code>`
    );
    return;
  }

  if (isCommand && cmd === "/register") {
    if (!args) {
      await sendMessage(chatId, "Usage: /register Your Full Name");
      return;
    }
    try {
      const { updated } = await registerMember({ displayName: args, from, chatId });
      await sendMessage(
        chatId,
        updated
          ? `Updated your member profile as <b>${escapeHtml(args)}</b>.`
          : `Registered you as <b>${escapeHtml(args)}</b> on the members board.`
      );
    } catch (err) {
      log("register error:", err.message);
      await sendMessage(chatId, "Sorry, I could not register you right now.");
    }
    return;
  }

  if (isCommand && cmd === "/mytasks") {
    try {
      await sendMyDayBriefing(from, chatId, todayISO());
    } catch (err) {
      log("mytasks error:", err.message);
      await sendMessage(chatId, "Sorry, I could not fetch your tasks right now.");
    }
    return;
  }

  if (isCommand && cmd === "/ask") {
    if (!args) {
      await sendMessage(chatId, "Usage: /ask What does the red valve do?");
      return;
    }
    await handleAsk({ question: args, from, chatId, logGap: true });
    return;
  }

  if (isCommand && cmd === "/done") {
    // Notion page IDs are UUIDs (32 hex chars, optionally dashed). Accept either form.
    const raw = args.trim().split(/\s+/)[0] || "";
    const hex = raw.replace(/-/g, "");
    const id = /^[0-9a-f]{32}$/i.test(hex)
      ? hex.replace(
          /^([0-9a-f]{8})([0-9a-f]{4})([0-9a-f]{4})([0-9a-f]{4})([0-9a-f]{12})$/i,
          "$1-$2-$3-$4-$5"
        )
      : "";
    if (!id) {
      await sendMessage(chatId, "Usage: /done <page id> (see ids in /mytasks)");
      return;
    }
    try {
      const result = await markItemDone(id);
      if (!result.ok) {
        await sendMessage(chatId, `Could not find item ${escapeHtml(id)}.`);
      } else if (result.dutyCompleted) {
        await sendMessage(
          chatId,
          `✅ Duty logged done: <b>${escapeHtml(result.name)}</b>\n` +
            `Next due <b>${escapeHtml(result.nextDue)}</b>` +
            (result.frequency ? ` (${escapeHtml(result.frequency)})` : "")
        );
      } else {
        await sendMessage(
          chatId,
          `Marked <b>${escapeHtml(result.name)}</b> as done. ✅`
        );
      }
    } catch (err) {
      log("done error:", err.message);
      await sendMessage(chatId, "Sorry, I could not update that task.");
    }
    return;
  }

  if (isCommand && cmd === "/task") {
    if (!args && !photoFileId) {
      await sendMessage(chatId, "Usage: /task <description>");
      return;
    }
    try {
      const classification = args ? await openaiLib.classifyTask(args).catch(() => null) : null;
      const { url } = await createInboxTask({
        text: args || "(photo)",
        from,
        chatId,
        photoFileId,
        classification,
      });
      let reply = `📋 Logged task: <b>${escapeHtml(classification?.title || args || "(photo)")}</b>`;
      if (url) reply += `\n<a href="${url}">Open in Notion</a>`;
      await sendMessage(chatId, reply);
    } catch (err) {
      log("createInboxTask error:", err.message);
      await sendMessage(chatId, "Sorry, I could not save that task.");
    }
    return;
  }

  if (isCommand) {
    await sendMessage(chatId, "Unknown command. Try /help.");
    return;
  }

  let voiceText = null;
  if (voiceFileId) {
    voiceText = await transcribeMessageVoice(message, {
      interactive: isAddressedToBot(message, botMe),
    });
  }

  const addressedToBot =
    isAddressedToBot(message, botMe) || (voiceText && textAddressesBot(voiceText));

  if (addressedToBot) {
    await routeAddressedMessage(message);
    return;
  }

  await handleAmbientMessage(message);
}

async function handleAsk({ question, from, chatId, logGap = LOG_DOC_GAPS }) {
  await telegram("sendChatAction", { chat_id: chatId, action: "typing" }).catch(() => {});
  let result;
  try {
    result = await answerQuestion(question);
  } catch (err) {
    log("answerQuestion error:", err.message);
    await sendMessage(chatId, "Sorry, I hit an error searching the documentation.");
    return { answered: false };
  }

  if (!result.available) {
    await sendMessage(
      chatId,
      "Documentation search is not configured yet (needs OPENAI_API_KEY)."
    );
    return { answered: false };
  }

  if (result.answered && result.answer) {
    const sources = (result.sources || [])
      .filter((s, i, arr) => arr.findIndex((x) => x.name === s.name) === i)
      .slice(0, 3)
      .map((s) => (s.url ? `<a href="${s.url}">${escapeHtml(s.name)}</a>` : escapeHtml(s.name)))
      .join(", ");
    await sendMessage(
      chatId,
      `${escapeHtml(result.answer)}` + (sources ? `\n\n📄 Source: ${sources}` : "")
    );
    return { answered: true };
  }

  if (!logGap) return { answered: false };

  try {
    await createDocGapTask({ question, from, chatId });
    await sendMessage(
      chatId,
      "I couldn't find that in our documentation yet. I've logged it as a " +
        "<b>Documentation gap</b> so a steward can write it up. 📝"
    );
  } catch (err) {
    log("createDocGapTask error:", err.message);
    await sendMessage(
      chatId,
      "I couldn't find that in our documentation, and failed to log it. Please tell a steward."
    );
  }
  return { answered: false };
}

async function captureAmbientEvent({ classified, text, from, chatId }) {
  if (!classified.is_event || !classified.event?.title) return false;
  // Prefer AI recurrence; fall back when the message itself clearly states a rule.
  const fromText = normalizeRecurrenceField(text || "");
  const looksRecurring =
    /\b(every|cada|todos?\s+os?|1st|2nd|3rd|4th|first|second|third|fourth|last|primeiro|primeira|segundo|terceiro|mensal|monthly|weekly|of the month|do m[eê]s)\b/i.test(
      text || ""
    );
  const recurrence =
    classified.event.recurrence ||
    (fromText.rule && looksRecurring ? fromText.canonical : null);
  const date = classified.event.date || (recurrence ? null : inferEventDate(text, null));
  const eventType = classified.event.event_type || "Other";
  const key = ambientDedupeKey(
    chatId,
    "event",
    `${classified.event.title}:${recurrence || date || ""}`
  );
  if (wasRecentlyCaptured(key)) return true;
  try {
    const created = await createEvent({
      title: classified.event.title,
      date,
      eventType,
      recurrence,
      scope: classified.event.scope || null,
      from,
      chatId,
      sourceText: text,
    });
    markCaptured(key);
    recordChatActivity({
      kind: "event_logged",
      from,
      chatId,
      title: classified.event.title,
      eventDate: created.date || date,
      text,
    });
    const when = created.recurrenceLabel
      ? created.recurrenceLabel
      : created.date || date || "date TBD";
    let reply = `📅 Logged: <b>${escapeHtml(classified.event.title)}</b> (${escapeHtml(when)})`;
    if (created.url) reply += ` — <a href="${created.url}">Notion</a>`;
    await sendMessage(chatId, reply);
    log(
      `Event captured: ${classified.event.title}` +
        (created.recurrence ? ` [${created.recurrence}]` : ` on ${created.date || date}`)
    );
    return true;
  } catch (err) {
    log("createEvent error:", err.message);
    return false;
  }
}

async function handleAmbientMessage(message) {
  if (!AMBIENT_CAPTURE) return;

  const chatId = message.chat.id;
  const from = message.from;
  if (from?.is_bot) return;

  const voiceFileId = getVoiceFileId(message);
  let text = (message.text || message.caption || "").trim();
  const photoFileId =
    message.photo && message.photo.length
      ? message.photo[message.photo.length - 1].file_id
      : null;

  if (!text && voiceFileId) {
    const buf = recentMessages.get(chatId) || [];
    const entry = buf.find((m) => m.messageId === message.message_id && m.isVoice);
    text = entry?.text || (await transcribeMessageVoice(message, { interactive: false })) || "";
    if (!text) return;
  }

  if (!text && !photoFileId) return;
  if (text.length < AMBIENT_MIN_TEXT_LEN && !photoFileId && !voiceFileId) return;

  const classified = text ? await classifyUserMessage(text, chatId) : null;
  await routeByAiClassification({
    classified,
    text,
    from,
    chatId,
    photoFileId,
    interactive: false,
  });
}

async function pollTelegramOnce() {
  let updates;
  try {
    updates = await telegram(
      "getUpdates",
      {
        offset: state.telegramOffset,
        timeout: 30,
        allowed_updates: ["message"],
      },
      { clientTimeoutMs: 45000 }
    );
  } catch (err) {
    if (isTransientNetworkError(err)) {
      logTransientError("getUpdates", err, networkState.retryMs);
      await sleep(networkState.retryMs);
    } else {
      log("getUpdates error:", err.message);
      await sleep(3000);
    }
    return;
  }

  for (const update of updates) {
    state.telegramOffset = update.update_id + 1;
    if (update.message) {
      try {
        await handleMessage(update.message);
      } catch (err) {
        log("handleMessage error:", err.message);
      }
    }
  }
  if (updates.length) saveState();
}

// ── Notion -> Telegram: announce changes ──────────────────────────────────────
async function scanBoardsForChanges({ announce }) {
  for (const dbName of WATCH_DB_NAMES) {
    let pages;
    try {
      ({ pages } = await notion.queryDatabase(dbName));
    } catch (err) {
      log(`Watch database missing (${dbName}):`, err.message);
      continue;
    }

    for (const page of pages) {
      const snap = pageSnapshot(page);
      const { stage, status, doer, done } = snap;
      const name = notion.pageTitle(page);

      const prev = state.items[page.id];
      const url = page.url;
      const link = url ? ` <a href="${url}">open</a>` : "";

      if (!prev) {
        if (announce && ANNOUNCE_NEW_ITEMS && !done) {
          await sendMessage(
            TELEGRAM_CHAT_ID,
            `🆕 New item on <b>${escapeHtml(dbName)}</b>: ` +
              `${escapeHtml(name)}${link}`
          );
        }
      } else if (announce) {
        if (!prev.done && done) {
          await sendMessage(
            TELEGRAM_CHAT_ID,
            `✅ Completed: <b>${escapeHtml(name)}</b> (${escapeHtml(dbName)})${link}`
          );
        } else if (doer && doer !== prev.doer) {
          await sendMessage(
            TELEGRAM_CHAT_ID,
            `👤 <b>${escapeHtml(name)}</b> assigned to ${escapeHtml(doer)} (${escapeHtml(dbName)})${link}`
          );
        } else if (
          !done &&
          ((status && status !== prev.status) ||
            (stage && stage !== prev.stage))
        ) {
          const label = status || stage;
          await sendMessage(
            TELEGRAM_CHAT_ID,
            `🔧 <b>${escapeHtml(name)}</b> → ${escapeHtml(label)} (${escapeHtml(dbName)})${link}`
          );
        }
      }

      state.items[page.id] = snap;
    }
  }
  saveState();
}

// ── Main loop ─────────────────────────────────────────────────────────────
async function main() {
  if (!TELEGRAM_BOT_TOKEN) {
    throw new Error("Missing TELEGRAM_BOT_TOKEN env variable.");
  }
  notion.assertKey();

  const me = await telegram("getMe");
  botMe = me;
  log(`Telegram bot online: @${me.username}`);
  log(`Watching Notion databases: ${WATCH_DB_NAMES.join(", ")}`);
  log(
    `Task intake: group ${TASK_GROUP_CHAT_ID}${ALLOW_DM_INTAKE ? " + private DMs" : ""}`
  );
  log(`Group mode: tag-to-activate (ambient capture ${AMBIENT_CAPTURE ? "ON" : "OFF"}); DMs always on`);
  log(`Events: Internal → ${INTERNAL_EVENTS_DB_NAME}, External → ${EXTERNAL_EVENTS_DB_NAME}`);
  log(`Daily recap: ${DAILY_RECAP ? `ON at ${RECAP_TIME} ${RECAP_TZ}` : "OFF"}`);
  log(`Notion announcements → chat ${TELEGRAM_CHAT_ID || "(off)"}`);

  if (openaiLib.isEnabled()) {
    const idx = getDocsIndex();
    log(
      `OpenAI enabled: ${openaiLib.CHAT_MODEL}, smart intake ON, /ask ON (${idx.chunks.length} doc chunks indexed).`
    );
    if (!idx.chunks.length) {
      log("  Tip: run `node scripts/index-vila-akoya-docs.js` to index Notion docs.");
    }
  } else {
    log("OPENAI_API_KEY not set — smart intake and /ask are OFF (tasks still work).");
  }

  if (!TELEGRAM_CHAT_ID) {
    log(
      "No TELEGRAM_CHAT_ID set — Notion->Telegram announcements are OFF. " +
        "Add the bot to your group, send a message, and copy the chat id logged above."
    );
  }

  // Seed the page cache on first run so we don't spam old items.
  if (!state.seededNotion) {
    log("First run: indexing existing Notion pages (no announcements)...");
    try {
      await scanBoardsForChanges({ announce: false });
    } catch (err) {
      log("Initial Notion scan failed:", err.message);
    }
    state.seededNotion = true;
    saveState();
    log("Indexing done.");
  }

  let lastNotionPoll = 0;

  // eslint-disable-next-line no-constant-condition
  while (true) {
    await pollTelegramOnce();
    await maybeSendDailyRecap();

    const now = Date.now();
    if (TELEGRAM_CHAT_ID && now - lastNotionPoll >= NOTION_POLL_SECONDS * 1000) {
      lastNotionPoll = now;
      try {
        await scanBoardsForChanges({ announce: true });
      } catch (err) {
        if (isTransientNetworkError(err)) {
          logTransientError("Notion poll", err, networkState.retryMs);
        } else {
          log("Notion poll error:", err.message);
        }
      }
    }
  }
}

main().catch((err) => {
  console.error("Bot crashed:", err);
  process.exit(1);
});
