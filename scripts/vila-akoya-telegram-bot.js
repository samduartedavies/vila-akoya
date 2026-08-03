/**
 * Vila Akoya — Telegram <-> Notion bridge
 *
 * Two-way community bot with ZERO npm dependencies (Node 18+ only).
 * It uses Telegram long-polling, so it needs NO public URL / webhook and can
 * run for free on a laptop, Raspberry Pi, home server or any always-on box.
 *
 * ── What it does ──────────────────────────────────────────────────────────
 * Telegram -> Notion
 *   - Listens to all messages in the Jobs group (+ DMs).
 *   - Voice notes in the group are transcribed; documentation dumps become structured Notion pages.
 *   - Tagged @bot, reply-to-bot, or private DM: text, photos, and voice notes.
 *   - Every group message is classified by AI → Notion actions (events, tasks, completions, cancellations) or questions.
 *   - Tagged @bot or reply-to-bot: always get a response.
 *   - Daily recap (~08:00 Europe/Lisbon): today's chores + events + campsite
 *     check-ins/outs + meal headcounts (all from Notion) + yesterday's chat highlights.
 *   - /ask, /task, /register, /mytasks, /done, /help as before.
 *   - Schedule questions ("anyone checking in today?", "how many for dinner?")
 *     are answered from Notion Campsite Bookings / Campsite Meals databases.
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
 *   AMBIENT_CAPTURE         "false" to disable passive task/event capture
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
  MAINTENANCE_GROUP_BY_AREA,
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

// ── Config ──────────────────────────────────────────────────────────────────
const TELEGRAM_BOT_TOKEN = process.env.TELEGRAM_BOT_TOKEN || "8991030236:AAEbLuvsa5HRn2rMwCDu8468kAKERo9DhxY";
const TELEGRAM_CHAT_ID = process.env.TELEGRAM_CHAT_ID || "-5438367592";

const TASK_GROUP_CHAT_ID =
  process.env.TASK_GROUP_CHAT_ID || TELEGRAM_CHAT_ID;
const ALLOW_DM_INTAKE = process.env.ALLOW_DM_INTAKE !== "false";

const TELEGRAM_API = `https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}`;
const TELEGRAM_FILE_API = `https://api.telegram.org/file/bot${TELEGRAM_BOT_TOKEN}`;

// ── Notion database names (env overrides, defaulting to notion.DB.*) ──────────
const TASKS_DB_NAME = process.env.TASKS_DB_NAME || notion.DB.TASKS;
const MEMBERS_DB_NAME = process.env.MEMBERS_DB_NAME || notion.DB.MEMBERS;
const EVENTS_DB_NAME = process.env.EVENTS_DB_NAME || notion.DB.EVENTS;
const RECURRING_DB_NAME = process.env.RECURRING_DB_NAME || notion.DB.RECURRING;
const GUEST_DB_NAME = process.env.GUEST_DB_NAME || notion.DB.GUEST_ISSUES;
const MAINTENANCE_DB_NAME = process.env.MAINTENANCE_DB_NAME || notion.DB.MAINTENANCE;
const DECISIONS_DB_NAME = process.env.DECISIONS_DB_NAME || notion.DB.DECISIONS;
const DOCS_DB_NAME = process.env.DOCS_DB_NAME || notion.DB.DOCS;
const CAMPSITE_BOOKINGS_DB_NAME =
  process.env.CAMPSITE_BOOKINGS_DB || notion.DB.BOOKINGS;
const CAMPSITE_MEALS_DB_NAME = process.env.CAMPSITE_MEALS_DB || notion.DB.MEALS;

const AMBIENT_CAPTURE = process.env.AMBIENT_CAPTURE !== "false";
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

function addressedText(message, bot) {
  const raw = (message.text || message.caption || "").trim();
  const stripped = stripBotMention(raw, bot);
  if (stripped) return stripped;

  const reply = message.reply_to_message;
  if (reply) {
    return (reply.text || reply.caption || "").trim();
  }

  const buf = recentMessages.get(message.chat.id) || [];
  const cutoff = Date.now() - ADDRESS_WINDOW_MS;
  const prev = buf
    .filter((m) => m.messageId !== message.message_id && m.ts >= cutoff)
    .slice(-1)[0];
  return prev?.text || "";
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
    // Migrate from the old monday version once. Its item cache keys are numeric
    // monday IDs (incompatible with Notion page UUIDs), so drop them and force a
    // silent Notion re-seed to avoid announcing every existing page as "new".
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
      ambientKeys: {},
      chatActivity: [],
      chatLog: [],
      items: {},
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

async function sendMessage(chatId, text, extra = {}) {
  if (!chatId) return;
  try {
    await telegram("sendMessage", {
      chat_id: chatId,
      text,
      parse_mode: "HTML",
      disable_web_page_preview: true,
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
    if (isTaskGroupMessage(message)) {
      recordGroupMessage(message, text);
      bufferTranscribedText(message, text);
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

  return { doc: { url: page.url, id: page.id }, structured };
}

// ── Telegram -> Notion: create a task ─────────────────────────────────────────
async function createInboxTask({ text, from, chatId, photoFileId, classification }) {
  const cleanText = String(text || "").trim() || "(no text)";

  // If the classifier ran, use its title and route to the review stage.
  const stage = classification ? "Needs review" : "New brain dumps";
  const rawName = classification?.title || cleanText;
  const name = rawName.length > 200 ? rawName.slice(0, 197) + "..." : rawName;

  const suggested = classification?.suggested_board
    ? `Suggested board: ${classification.suggested_board}\n`
    : "";
  const summary = classification?.summary ? `Summary: ${classification.summary}\n` : "";
  const notes = telegramNote({
    from,
    chatId,
    extra: `${suggested}${summary}`.trim(),
    sourceText: cleanText,
  });

  const properties = {
    Stage: stage,
    Source: "Telegram",
    "Telegram user": senderHandle(from),
    "Telegram chat ID": String(chatId),
    Notes: notes,
  };
  if (classification) {
    if (classification.area) properties.Area = classification.area;
    if (classification.priority) properties.Priority = classification.priority;
    if (classification.safety_critical) properties["Safety critical?"] = true;
    if (classification.needs_materials) properties["Needs materials?"] = true;
    if (classification.needs_money) properties["Needs money?"] = true;
  }
  if (photoFileId) properties.Notes = `${notes}\n\n[Photo attached in Telegram]`;

  const page = await notion.createPage({
    databaseName: TASKS_DB_NAME,
    title: name,
    properties,
  });

  markPageSeen(page.id, { stage, status: "", doer: "", done: notion.isDoneStage(stage) });
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

async function createTaskFromClassification({ classified, text, from, chatId, photoFileId }) {
  const task = classified.task;
  const isGuest = classified.action === "guest_issue";
  const databaseName = isGuest
    ? GUEST_DB_NAME
    : task?.route_maintenance
      ? MAINTENANCE_DB_NAME
      : TASKS_DB_NAME;
  const stage = isGuest
    ? "New guest issues"
    : task?.route_maintenance
      ? MAINTENANCE_GROUP_BY_AREA[task.area] || "Water"
      : "Needs review";

  const properties = {};
  if (task?.area && !task?.route_maintenance) properties.Area = task.area;
  if (task?.priority) properties.Priority = task.priority;
  if (task?.safety_critical) properties["Safety critical?"] = true;
  let sourceText = text;
  if (photoFileId) sourceText = `${text || ""}\n\n[Photo attached in Telegram]`.trim();

  return createDbPage({
    databaseName,
    stage,
    itemName: task?.title || text,
    properties,
    from,
    chatId,
    sourceText,
  });
}

async function createDecision({ decision, from, chatId, sourceText }) {
  const properties = {};
  if (decision.category) properties.Category = decision.category;
  properties["Proposed by"] = senderDisplayName(from);
  if (decision.summary) properties.Notes = decision.summary;
  return createDbPage({
    databaseName: DECISIONS_DB_NAME,
    stage: "Proposed",
    itemName: decision.title,
    properties,
    from,
    chatId,
    sourceText,
  });
}

async function createEvent({ title, date, eventType, recurrence, from, chatId, sourceText }) {
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

  const properties = {
    Stage: stage,
    "Announce on Telegram?": true,
    Source: "Telegram",
    Notes: notes,
  };
  if (eventDate) properties.Date = eventDate;
  if (eventType) properties["Event type"] = eventType;
  if (recurrenceInfo.canonical) properties.Recurrence = recurrenceInfo.canonical;

  const page = await notion.createPage({
    databaseName: EVENTS_DB_NAME,
    title: String(title || "Event").slice(0, 200),
    properties,
  });

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
    recurrence: recurrenceInfo.canonical || null,
    recurrenceLabel: recurrenceInfo.label || null,
  };
}

async function fetchRotaForDay(dateISO) {
  const dayKey = dayKeyFromISO(dateISO);
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(RECURRING_DB_NAME, {
      filter: { property: "Stage", select: { equals: "Community chore rota" } },
    }));
  } catch (err) {
    log("Rota fetch failed:", err.message);
    return [];
  }

  const chores = [];
  for (const page of pages) {
    const assignee = notion.pagePropText(page, dayKey).trim();
    if (assignee) chores.push({ chore: notion.pageTitle(page), assignee });
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
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(EVENTS_DB_NAME));
  } catch (err) {
    log("Events fetch failed:", err.message);
    return [];
  }

  const events = [];
  for (const page of pages) {
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
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(EVENTS_DB_NAME));
  } catch (err) {
    log("Events fetch failed:", err.message);
    return [];
  }

  const events = [];
  for (const page of pages) {
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
  const chores = await fetchRotaForDay(dateISO);
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
  lines.push("<b>Chores today</b>");
  if (chores.length) {
    for (const c of chores) lines.push(`• ${escapeHtml(c.chore)} — ${escapeHtml(c.assignee)}`);
  } else {
    lines.push("• (nothing on the rota)");
  }
  lines.push("", "<b>Events today</b>");
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
      Stage: "Documentation gap",
      Source: "Telegram",
      "Telegram user": senderHandle(from),
      "Telegram chat ID": String(chatId),
      Notes: notes,
    },
  });

  markPageSeen(page.id, {
    stage: "Documentation gap",
    status: "",
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

const ASK_MIN_SCORE = Number(process.env.ASK_MIN_SCORE || 0.28);

async function answerQuestion(question) {
  if (!openaiLib.isEnabled()) {
    return { available: false };
  }
  const index = getDocsIndex();
  if (!index.chunks.length) {
    return { available: true, indexed: false, answered: false };
  }

  const queryEmbedding = await openaiLib.embedOne(question);
  const hits = docsIndex.search(index, queryEmbedding, 4);
  const top = hits[0];

  if (!top || top.score < ASK_MIN_SCORE) {
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
      EVENTS_DB_NAME,
      DECISIONS_DB_NAME,
      MAINTENANCE_DB_NAME,
      GUEST_DB_NAME,
      TASKS_DB_NAME,
      RECURRING_DB_NAME,
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
  if (dbName === EVENTS_DB_NAME) return "Done / past";
  if (dbName === RECURRING_DB_NAME) return "Done / logged";
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

async function registerMember({ displayName, from, chatId }) {
  const handle = senderHandle(from);
  const { page } = await findMemberByTelegram(handle);

  const properties = {
    "Display name": displayName,
    "Telegram username": handle,
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
  const handle = senderHandle(from);
  const { page: member } = await findMemberByTelegram(handle);
  const displayName = member
    ? notion.pagePropText(member, "Display name") || notion.pageTitle(member)
    : senderDisplayName(from);

  const matches = [];
  for (const dbName of WATCH_DB_NAMES) {
    let pages;
    try {
      ({ pages } = await notion.queryDatabase(dbName));
    } catch {
      continue;
    }
    for (const page of pages) {
      if (notion.isDoneStage(notion.pagePropText(page, "Stage"))) continue;
      const doer =
        notion.pagePropText(page, "Doer name") +
        " " +
        notion.pagePropText(page, "Helpers / doers");
      const matchesName =
        displayName && doer.toLowerCase().includes(displayName.toLowerCase());
      const matchesHandle = doer.toLowerCase().includes(handle.toLowerCase());
      if (matchesName || matchesHandle) {
        matches.push({
          id: page.id,
          name: notion.pageTitle(page),
          board: dbName,
          status: notion.pagePropText(page, "Status"),
        });
      }
    }
  }
  return { displayName, matches };
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
  const db = await notion.resolveDatabase(dbName);
  const map = {};
  if (notion.prop(db, "Stage")) map.Stage = doneStageFor(dbName);
  if (notion.prop(db, "Status")) map.Status = "Done";

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
      if (pageIsDone(page)) continue;
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
    dbNames: WATCH_DB_NAMES,
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
  return { ok: true, name: result.name, boardName: matches[0].boardName };
}

async function cancelEventFromChat({ target, text, from, chatId }) {
  const key = ambientDedupeKey(chatId, "cancel", target.title || target.item_id);
  if (wasRecentlyCaptured(key)) return { ok: true, duplicate: true };

  const matches = await findItemsByTarget({
    dbNames: [EVENTS_DB_NAME],
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

// ── Telegram command + message handling ───────────────────────────────────────
const HELP_TEXT = [
  "<b>Vila Akoya bot</b>",
  "",
  "Tag me (@bot), reply to me, or DM me for questions, schedule info, tasks and events.",
  "Voice notes in the group are picked up automatically — explain how something works to create a structured doc.",
  "In the group I also watch text chat and log events, tasks, docs, completions and cancellations in Notion — I'll confirm when I act.",
  "",
  "<b>Commands</b>",
  "/task &lt;text&gt; — add a task",
  "/ask &lt;question&gt; — search our documentation",
  "/register &lt;your name&gt; — link your Telegram to the members board",
  "/mytasks — show your open tasks",
  "/done &lt;item id&gt; — mark a task as done",
  "/whoami — show your Telegram details",
  "/help — this message",
].join("\n");

async function classifyUserMessage(text, chatId) {
  if (!text || !openaiLib.isEnabled()) return null;
  try {
    const buf = (recentMessages.get(chatId) || []).slice(-6);
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
    return classified;
  } catch (err) {
    log("classifyGroupMessage error:", err.message);
    return null;
  }
}

async function handleClassifiedQuestion({ text, questionKind, from, chatId, interactive, scheduleRange }) {
  const logDocGaps = interactive && LOG_DOC_GAPS;

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
    return docResult.answered;
  }

  const docResult = await handleAsk({ question: text, from, chatId, logGap: logDocGaps });
  if (docResult.answered) return true;
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
    const { url, boardName } = await createTaskFromClassification({
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
    if (boardName) reply += ` (${escapeHtml(boardName)})`;
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
        if (result.boardName) reply += ` (${escapeHtml(result.boardName)})`;
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
        let reply = `🗳️ Logged for discussion: <b>${escapeHtml(c.decision.title)}</b>`;
        if (url) reply += ` — <a href="${url}">Notion</a>`;
        await sendMessage(chatId, reply);
      } catch (err) {
        log("createDecision error:", err.message);
        if (interactive) await sendMessage(chatId, "Sorry, I could not log that proposal.");
      }
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

  let text = stripBotMention((message.text || message.caption || "").trim(), botMe);

  if (!text) {
    const voiceText = await transcribeMessageVoice(message, { interactive: true });
    if (voiceText !== null) text = voiceText;
    else text = addressedText(message, botMe);
  }

  if (!text && !photoFileId) {
    await sendMessage(chatId, "What can I help with? Ask me anything, or use /help.");
    return;
  }

  const classified = text ? await classifyUserMessage(text, chatId) : null;
  await routeByAiClassification({ classified, text, from, chatId, photoFileId, interactive: true });
}

async function handleMessage(message) {
  const chatId = message.chat.id;
  const from = message.from;
  const text = (message.text || message.caption || "").trim();

  const photoFileId =
    message.photo && message.photo.length
      ? message.photo[message.photo.length - 1].file_id
      : null;
  const voiceFileId = getVoiceFileId(message);

  log(
    `Message from ${senderHandle(from)} in chat ${chatId}: ` +
      `${text || (voiceFileId ? "(voice)" : photoFileId ? "(photo)" : "(empty)")}`
  );
  bufferMessage(message);
  recordGroupMessage(message);

  if (!shouldHandleIntake(message)) {
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
      const { displayName, matches } = await listMyTasks(from);
      if (!matches.length) {
        await sendMessage(
          chatId,
          `No open tasks found for <b>${escapeHtml(displayName)}</b>.\n` +
            `Tip: use /register &lt;your name&gt; so I know who you are, and make sure the "Doer name" matches.`
        );
        return;
      }
      const lines = matches
        .slice(0, 25)
        .map(
          (m) =>
            `• <b>${escapeHtml(m.name)}</b> — ${escapeHtml(m.board)}` +
            (m.status ? ` [${escapeHtml(m.status)}]` : "") +
            `\n   done: <code>/done ${m.id}</code>`
        );
      await sendMessage(
        chatId,
        `Open tasks for <b>${escapeHtml(displayName)}</b>:\n\n${lines.join("\n")}`
      );
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
      await sendMessage(
        chatId,
        result.ok
          ? `Marked <b>${escapeHtml(result.name)}</b> as done. ✅`
          : `Could not find item ${escapeHtml(id)}.`
      );
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

  if (isAddressedToBot(message, botMe)) {
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
    text = (await transcribeMessageVoice(message, { interactive: false })) || "";
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
  log(`Ambient capture: ${AMBIENT_CAPTURE ? "ON" : "OFF"} (AI-only routing, task≥${AMBIENT_MIN_CONFIDENCE}, event≥${EVENT_MIN_CONFIDENCE})`);
  log(`Daily recap: ${DAILY_RECAP ? `ON at ${RECAP_TIME} ${RECAP_TZ}` : "OFF"}`);
  log(`Notion announcements → chat ${TELEGRAM_CHAT_ID || "(off)"}`);

  if (openaiLib.isEnabled()) {
    const idx = getDocsIndex();
    log(
      `OpenAI enabled: smart intake ON, /ask ON (${idx.chunks.length} doc chunks indexed).`
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
