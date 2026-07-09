/**
 * Vila Akoya — Telegram <-> monday.com bridge
 *
 * Two-way community bot with ZERO npm dependencies (Node 18+ only).
 * It uses Telegram long-polling, so it needs NO public URL / webhook and can
 * run for free on a laptop, Raspberry Pi, home server or any always-on box.
 *
 * ── What it does ──────────────────────────────────────────────────────────
 * Telegram -> monday.com
 *   - Listens to all messages in the Jobs group (+ DMs).
 *   - Tagged @bot, reply-to-bot, or private DM: text, photos, and voice notes.
 *   - Every group message is classified by AI → monday.com actions (events, tasks, completions, cancellations) or questions.
 *   - Tagged @bot or reply-to-bot: always get a response.
 *   - Daily recap (~08:00 Europe/Lisbon): today's chores + events + yesterday's important group-chat highlights (private DMs excluded).
 *   - /ask, /task, /register, /mytasks, /done, /help as before.
 *
 * Requires (for smart features): OPENAI_API_KEY, and a docs index built
 * with `node scripts/index-vila-akoya-docs.js`.
 * Voice notes (tagged/DM only) use OPENAI_TRANSCRIBE_MODEL (default gpt-4o-mini-transcribe).
 *
 * monday.com -> Telegram (posted to one group chat)
 *   - New tasks, completions, assignments on watched boards.
 *
 * ── Setup ─────────────────────────────────────────────────────────────────
 * 1. Run setup-vila-akoya-monday.js first so the boards/columns exist.
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
 *   EVENT_CAPTURE_GROUP     monday group for new events (default "Upcoming")
 */

"use strict";

const fs = require("fs");
const path = require("path");
const openaiLib = require("./lib/openai");
const docsIndex = require("./lib/docs-index");
const {
  CHAT_ACTIVITY_KIND,
  MAINTENANCE_GROUP_BY_AREA,
  buildWeekCalendarContext,
  resolveScheduleRange,
} = require("./lib/community-actions");

// ── Config ──────────────────────────────────────────────────────────────────
const MONDAY_API_TOKEN =
  process.env.MONDAY_API_TOKEN ||
  // Falls back to the same token used by setup-vila-akoya-monday.js.
  "eyJhbGciOiJIUzI1NiJ9.eyJ0aWQiOjY3NzQ0MDk2NywiYWFpIjoxMSwidWlkIjoxMDcyNzM0NDIsImlhZCI6IjIwMjYtMDctMDFUMDk6NDU6NTYuMDAwWiIsInBlciI6Im1lOndyaXRlIiwiYWN0aWQiOjM1ODIxNzEyLCJyZ24iOiJldWMxIn0.fUMN4Zpvsm7hOx8xyo4Qb76Ovi_tDs-7nhhdu8ZIyEg";

const TELEGRAM_BOT_TOKEN = process.env.TELEGRAM_BOT_TOKEN || "8991030236:AAEbLuvsa5HRn2rMwCDu8468kAKERo9DhxY";
const TELEGRAM_CHAT_ID = process.env.TELEGRAM_CHAT_ID || "-5438367592";

const TASK_GROUP_CHAT_ID =
  process.env.TASK_GROUP_CHAT_ID || TELEGRAM_CHAT_ID;
const ALLOW_DM_INTAKE = process.env.ALLOW_DM_INTAKE !== "false";

const MONDAY_API_VERSION = process.env.MONDAY_API_VERSION || "2026-10";
const MONDAY_API_URL = "https://api.monday.com/v2";
const MONDAY_FILE_URL = "https://api.monday.com/v2/file";
const TELEGRAM_API = `https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}`;
const TELEGRAM_FILE_API = `https://api.telegram.org/file/bot${TELEGRAM_BOT_TOKEN}`;

const TASK_INBOX_BOARD_NAME =
  process.env.TASK_INBOX_BOARD_NAME || "00 — Task Inbox";
const MEMBERS_BOARD_NAME =
  process.env.MEMBERS_BOARD_NAME || "70 — Community Members";
const EVENTS_BOARD_NAME =
  process.env.EVENTS_BOARD_NAME || "80 — Events & Gatherings";
const RECURRING_BOARD_NAME =
  process.env.RECURRING_BOARD_NAME || "10 — Recurring Duties";
const GUEST_BOARD_NAME =
  process.env.GUEST_BOARD_NAME || "30 — Campsite & Guest Issues";
const MAINTENANCE_BOARD_NAME =
  process.env.MAINTENANCE_BOARD_NAME || "20 — Maintenance & Infrastructure";
const DECISIONS_BOARD_NAME =
  process.env.DECISIONS_BOARD_NAME || "90 — Decisions & Proposals";

const AMBIENT_CAPTURE = process.env.AMBIENT_CAPTURE !== "false";
const AMBIENT_MIN_CONFIDENCE = Number(process.env.AMBIENT_MIN_CONFIDENCE || 0.7);
const AMBIENT_MIN_TEXT_LEN = Number(process.env.AMBIENT_MIN_TEXT_LEN || 4);
const EVENT_MIN_CONFIDENCE = Number(process.env.EVENT_MIN_CONFIDENCE || 0.5);
const LOG_DOC_GAPS = process.env.LOG_DOC_GAPS === "true";
const DAILY_RECAP = process.env.DAILY_RECAP !== "false";
const RECAP_TIME = process.env.RECAP_TIME || "08:00";
const RECAP_TZ = process.env.RECAP_TZ || "Europe/Lisbon";
const EVENT_CAPTURE_GROUP = process.env.EVENT_CAPTURE_GROUP || "Upcoming";
const ADDRESS_WINDOW_MS = Number(process.env.ADDRESS_WINDOW_MS || 120000);

const DEFAULT_WATCH_BOARDS = [
  "00 — Task Inbox",
  "20 — Maintenance & Infrastructure",
  "30 — Campsite & Guest Issues",
  "40 — Village Projects",
  "80 — Events & Gatherings",
];
const WATCH_BOARD_NAMES = (process.env.WATCH_BOARD_NAMES
  ? process.env.WATCH_BOARD_NAMES.split(",").map((s) => s.trim())
  : DEFAULT_WATCH_BOARDS
).filter(Boolean);

const MONDAY_POLL_SECONDS = Number(process.env.MONDAY_POLL_SECONDS || 60);
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

function recordGroupMessage(message) {
  if (!isTaskGroupMessage(message)) return;
  const from = message.from;
  if (from?.is_bot) return;
  const text = (message.text || message.caption || "").trim();
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
    return s;
  } catch {
    return {
      telegramOffset: 0,
      seededMonday: false,
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

// ── monday.com client ─────────────────────────────────────────────────────
async function monday(query, variables = {}, attempt = 1) {
  const res = await fetchWithRetry(
    MONDAY_API_URL,
    {
      method: "POST",
      headers: {
        Authorization: MONDAY_API_TOKEN,
        "Content-Type": "application/json",
        "API-Version": MONDAY_API_VERSION,
      },
      body: JSON.stringify({ query, variables }),
    },
    { label: "monday API", attempts: 4 }
  );

  const json = await readJsonResponse(res, "monday API");

  const complexityError = json.errors?.find(
    (err) => err.extensions?.code === "COMPLEXITY_BUDGET_EXHAUSTED"
  );

  if (complexityError && attempt <= 8) {
    const retrySeconds =
      Number(complexityError.extensions?.retry_in_seconds || 0) ||
      Math.min(60, attempt * 5);
    log(`monday complexity budget exhausted, waiting ${retrySeconds + 2}s...`);
    await sleep((retrySeconds + 2) * 1000);
    return monday(query, variables, attempt + 1);
  }

  if (json.errors) {
    throw new Error("monday API error: " + JSON.stringify(json.errors));
  }
  if (!res.ok) {
    throw new Error(`monday HTTP ${res.status}`);
  }
  return json.data;
}

// Board cache: name -> { id, name, columns, groups }
const boardCache = new Map();
let accountSlug = null;

async function getAccountSlug() {
  if (accountSlug) return accountSlug;
  try {
    const data = await monday(`query { me { account { slug } } }`);
    accountSlug = data?.me?.account?.slug || null;
  } catch (err) {
    log("Could not read account slug:", err.message);
  }
  return accountSlug;
}

async function resolveBoard(boardName) {
  if (boardCache.has(boardName)) return boardCache.get(boardName);

  const data = await monday(`
    query {
      boards(limit: 500) {
        id
        name
        state
        columns { id title type }
        groups { id title }
      }
    }
  `);

  for (const board of data.boards || []) {
    if (board.state === "archived") continue;
    if (!boardCache.has(board.name)) boardCache.set(board.name, board);
  }

  const board = boardCache.get(boardName);
  if (!board) throw new Error(`Board not found: ${boardName}`);
  return board;
}

function columnId(board, title, type) {
  const col = board.columns.find(
    (c) => c.title === title && (!type || c.type === type)
  );
  return col ? col.id : null;
}

function itemUrl(boardId, itemId) {
  if (!accountSlug) return null;
  return `https://${accountSlug}.monday.com/boards/${boardId}/pulses/${itemId}`;
}

async function changeColumnValues(boardId, itemId, values) {
  await monday(
    `
      mutation ($boardId: ID!, $itemId: ID!, $vals: JSON!) {
        change_multiple_column_values(
          board_id: $boardId,
          item_id: $itemId,
          column_values: $vals,
          create_labels_if_missing: true
        ) { id }
      }
    `,
    { boardId: String(boardId), itemId: String(itemId), vals: JSON.stringify(values) }
  );
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

async function transcribeVoiceFile(fileId) {
  const { buf, filename } = await downloadTelegramFile(fileId);
  const safeName = /\.[a-z0-9]+$/i.test(filename) ? filename : `${filename}.ogg`;
  return openaiLib.transcribeAudio(buf, safeName);
}

async function uploadFileToColumn(boardId, itemId, columnId, buf, filename) {
  // GraphQL multipart request spec — wrapped in try/catch by caller.
  const form = new FormData();
  form.append(
    "query",
    `mutation ($file: File!) { add_file_to_column(item_id: ${itemId}, column_id: "${columnId}", file: $file) { id } }`
  );
  form.append("map", JSON.stringify({ image: "variables.file" }));
  form.append("image", new Blob([buf]), filename);

  const res = await fetch(MONDAY_FILE_URL, {
    method: "POST",
    headers: {
      Authorization: MONDAY_API_TOKEN,
      "API-Version": MONDAY_API_VERSION,
    },
    body: form,
  });
  const json = await res.json();
  if (json.errors) throw new Error(JSON.stringify(json.errors));
}

// ── Telegram -> monday.com: create a task ─────────────────────────────────────
async function createInboxTask({ text, from, chatId, photoFileId, classification }) {
  const board = await resolveBoard(TASK_INBOX_BOARD_NAME);

  const cleanText = String(text || "").trim() || "(no text)";

  // If the classifier ran, use its title and route to the review group.
  const reviewGroup = board.groups.find((g) => g.title === "Needs review");
  const group = classification && reviewGroup ? reviewGroup : board.groups[0];

  const rawName = classification?.title || cleanText;
  const name = rawName.length > 200 ? rawName.slice(0, 197) + "..." : rawName;

  const created = await monday(
    `
      mutation ($boardId: ID!, $groupId: String!, $name: String!) {
        create_item(board_id: $boardId, group_id: $groupId, item_name: $name) { id }
      }
    `,
    { boardId: String(board.id), groupId: group.id, name }
  );
  const itemId = created.create_item.id;

  const values = {};
  const sourceCol = columnId(board, "Source", "status");
  if (sourceCol) values[sourceCol] = { label: "Telegram" };

  const tgUserCol = columnId(board, "Telegram user", "text");
  if (tgUserCol) values[tgUserCol] = senderHandle(from);

  const tgChatCol = columnId(board, "Telegram chat ID", "text");
  if (tgChatCol) values[tgChatCol] = String(chatId);

  if (classification) {
    const areaCol = columnId(board, "Area", "status");
    if (areaCol && classification.area) values[areaCol] = { label: classification.area };

    const priorityCol = columnId(board, "Priority", "status");
    if (priorityCol && classification.priority)
      values[priorityCol] = { label: classification.priority };

    const safetyCol = columnId(board, "Safety critical?", "checkbox");
    if (safetyCol && classification.safety_critical) values[safetyCol] = { checked: "true" };

    const materialsCol = columnId(board, "Needs materials?", "checkbox");
    if (materialsCol && classification.needs_materials)
      values[materialsCol] = { checked: "true" };

    const moneyCol = columnId(board, "Needs money?", "checkbox");
    if (moneyCol && classification.needs_money) values[moneyCol] = { checked: "true" };
  }

  const notesCol = columnId(board, "Notes", "long_text");
  if (notesCol) {
    const suggested = classification?.suggested_board
      ? `Suggested board: ${classification.suggested_board}\n`
      : "";
    const summary = classification?.summary ? `Summary: ${classification.summary}\n` : "";
    values[notesCol] = {
      text:
        `From Telegram: ${senderDisplayName(from)} (${senderHandle(from)})\n` +
        `Chat: ${chatId}\n${suggested}${summary}\nOriginal message:\n${cleanText}`,
    };
  }

  if (Object.keys(values).length) {
    try {
      await changeColumnValues(board.id, itemId, values);
    } catch (err) {
      log("Could not set inbox columns:", err.message);
    }
  }

  if (photoFileId) {
    const fileCol = columnId(board, "Photo / file", "file");
    if (fileCol) {
      try {
        const { buf, filename } = await downloadTelegramPhoto(photoFileId);
        await uploadFileToColumn(board.id, itemId, fileCol, buf, filename);
      } catch (err) {
        log("Photo upload skipped:", err.message);
      }
    }
  }

  // Pre-mark as seen so the poller doesn't double-announce our own item.
  state.items[itemId] = { status: "", assignees: "", done: false };
  saveState();

  return { itemId, boardId: board.id, url: itemUrl(board.id, itemId) };
}

async function createBoardItem({
  boardName,
  groupTitle,
  itemName,
  columnValues = {},
  from,
  chatId,
  sourceText,
}) {
  const board = await resolveBoard(boardName);
  const group =
    board.groups.find((g) => g.title === groupTitle) || board.groups[0];
  const name = String(itemName || "Item").slice(0, 200);

  const created = await monday(
    `
      mutation ($boardId: ID!, $groupId: String!, $name: String!) {
        create_item(board_id: $boardId, group_id: $groupId, item_name: $name) { id }
      }
    `,
    { boardId: String(board.id), groupId: group.id, name }
  );
  const itemId = created.create_item.id;

  const values = { ...columnValues };
  const sourceCol = columnId(board, "Source", "status");
  if (sourceCol && !values[sourceCol]) values[sourceCol] = { label: "Telegram" };
  const tgUserCol = columnId(board, "Telegram user", "text");
  if (tgUserCol) values[tgUserCol] = senderHandle(from);
  const notesCol = columnId(board, "Notes", "long_text");
  if (notesCol && sourceText && !values[notesCol]) {
    values[notesCol] = {
      text:
        `From Telegram: ${senderDisplayName(from)} (${senderHandle(from)})\n` +
        `Chat: ${chatId}\n\nOriginal message:\n${sourceText}`,
    };
  }

  if (Object.keys(values).length) {
    try {
      await changeColumnValues(board.id, itemId, values);
    } catch (err) {
      log(`Could not set columns on ${boardName}:`, err.message);
    }
  }

  state.items[itemId] = { status: "", assignees: "", done: false };
  saveState();
  return { itemId, boardId: board.id, url: itemUrl(board.id, itemId), boardName };
}

async function createTaskFromClassification({ classified, text, from, chatId, photoFileId }) {
  const task = classified.task;
  const isGuest = classified.action === "guest_issue";
  const boardName = isGuest
    ? GUEST_BOARD_NAME
    : task?.route_maintenance
      ? MAINTENANCE_BOARD_NAME
      : TASK_INBOX_BOARD_NAME;
  const groupTitle = isGuest
    ? "New guest issues"
    : task?.route_maintenance
      ? MAINTENANCE_GROUP_BY_AREA[task.area] || "Water"
      : "Needs review";

  const board = await resolveBoard(boardName);
  const values = {};
  const areaCol = columnId(board, "Area", "status");
  if (areaCol && task?.area) values[areaCol] = { label: task.area };
  const priorityCol = columnId(board, "Priority", "status");
  if (priorityCol && task?.priority) values[priorityCol] = { label: task.priority };
  const safetyCol = columnId(board, "Safety critical?", "checkbox");
  if (safetyCol && task?.safety_critical) values[safetyCol] = { checked: "true" };

  const result = await createBoardItem({
    boardName,
    groupTitle,
    itemName: task?.title || text,
    columnValues: values,
    from,
    chatId,
    sourceText: text,
  });

  if (photoFileId) {
    const fileCol = columnId(board, "Photo / file", "file");
    if (fileCol) {
      try {
        const { buf, filename } = await downloadTelegramPhoto(photoFileId);
        await uploadFileToColumn(board.id, result.itemId, fileCol, buf, filename);
      } catch (err) {
        log("Photo upload skipped:", err.message);
      }
    }
  }
  return result;
}

async function createDecision({ decision, from, chatId, sourceText }) {
  const board = await resolveBoard(DECISIONS_BOARD_NAME);
  const values = {};
  const catCol = columnId(board, "Category", "status");
  if (catCol && decision.category) values[catCol] = { label: decision.category };
  const proposedCol = columnId(board, "Proposed by", "text");
  if (proposedCol) values[proposedCol] = senderDisplayName(from);
  if (decision.summary) {
    const notesCol = columnId(board, "Notes", "long_text");
    if (notesCol) values[notesCol] = { text: decision.summary };
  }
  return createBoardItem({
    boardName: DECISIONS_BOARD_NAME,
    groupTitle: "Proposed",
    itemName: decision.title,
    columnValues: values,
    from,
    chatId,
    sourceText,
  });
}

async function createEvent({ title, date, eventType, from, chatId, sourceText }) {
  const board = await resolveBoard(EVENTS_BOARD_NAME);
  const group =
    board.groups.find((g) => g.title === EVENT_CAPTURE_GROUP) || board.groups[0];

  const name = String(title || "Event").slice(0, 200);
  const created = await monday(
    `
      mutation ($boardId: ID!, $groupId: String!, $name: String!) {
        create_item(board_id: $boardId, group_id: $groupId, item_name: $name) { id }
      }
    `,
    { boardId: String(board.id), groupId: group.id, name }
  );
  const itemId = created.create_item.id;

  const values = {};
  const dateCol = columnId(board, "Date", "date");
  if (dateCol && date) values[dateCol] = { date };

  const typeCol = columnId(board, "Event type", "status");
  if (typeCol && eventType) values[typeCol] = { label: eventType };

  const announceCol = columnId(board, "Announce?", "checkbox");
  if (announceCol) values[announceCol] = { checked: "true" };

  const sourceCol = columnId(board, "Source", "status");
  if (sourceCol) values[sourceCol] = { label: "Telegram" };

  const notesCol = columnId(board, "Notes", "long_text");
  if (notesCol) {
    values[notesCol] = {
      text:
        `From Telegram: ${senderDisplayName(from)} (${senderHandle(from)})\n` +
        `Chat: ${chatId}\n\nOriginal message:\n${sourceText || name}`,
    };
  }

  if (Object.keys(values).length) {
    try {
      await changeColumnValues(board.id, itemId, values);
    } catch (err) {
      log("Could not set event columns:", err.message);
    }
  }

  state.items[itemId] = { status: "", assignees: "", done: false };
  saveState();
  return { itemId, boardId: board.id, url: itemUrl(board.id, itemId) };
}

async function fetchRotaForDay(dateISO) {
  const board = await resolveBoard(RECURRING_BOARD_NAME);
  const dayKey = dayKeyFromISO(dateISO);
  const dayCol = columnId(board, dayKey, "text");
  if (!dayCol) return [];

  const group = board.groups.find((g) => g.title === "Community chore rota");
  if (!group) return [];

  const data = await monday(
    `
      query ($boardId: ID!, $groupId: String!) {
        boards(ids: [$boardId]) {
          groups(ids: [$groupId]) {
            items_page(limit: 100) {
              items { id name column_values { id text } }
            }
          }
        }
      }
    `,
    { boardId: String(board.id), groupId: group.id }
  );

  const items = data.boards?.[0]?.groups?.[0]?.items_page?.items || [];
  const chores = [];
  for (const item of items) {
    const cv = Object.fromEntries(item.column_values.map((c) => [c.id, c.text || ""]));
    const assignee = (cv[dayCol] || "").trim();
    if (assignee) chores.push({ chore: item.name, assignee });
  }
  return chores;
}

async function fetchEventsForDate(dateISO) {
  const board = await resolveBoard(EVENTS_BOARD_NAME);
  const dateCol = columnId(board, "Date", "date");
  if (!dateCol) return [];

  const events = [];
  let cursor = null;
  do {
    const data = await monday(
      `
        query ($boardId: ID!, $cursor: String) {
          boards(ids: [$boardId]) {
            items_page(limit: 100, cursor: $cursor) {
              cursor
              items { id name group { title } column_values { id text } }
            }
          }
        }
      `,
      { boardId: String(board.id), cursor }
    );
    const page = data.boards?.[0]?.items_page;
    for (const item of page?.items || []) {
      if (isDoneName(item.group?.title)) continue;
      const cv = Object.fromEntries(item.column_values.map((c) => [c.id, c.text || ""]));
      const itemDate = (cv[dateCol] || "").slice(0, 10);
      if (itemDate === dateISO) {
        const typeCol = columnId(board, "Event type", "status");
        events.push({
          title: item.name,
          type: typeCol ? cv[typeCol] || "" : "",
        });
      }
    }
    cursor = page?.cursor || null;
  } while (cursor);
  return events;
}

async function fetchEventsBetween(startISO, endISO) {
  const board = await resolveBoard(EVENTS_BOARD_NAME);
  const dateCol = columnId(board, "Date", "date");
  if (!dateCol) return [];

  const events = [];
  let cursor = null;
  do {
    const data = await monday(
      `
        query ($boardId: ID!, $cursor: String) {
          boards(ids: [$boardId]) {
            items_page(limit: 100, cursor: $cursor) {
              cursor
              items { id name group { title } column_values { id text } }
            }
          }
        }
      `,
      { boardId: String(board.id), cursor }
    );
    const page = data.boards?.[0]?.items_page;
    for (const item of page?.items || []) {
      if (isDoneName(item.group?.title)) continue;
      const cv = Object.fromEntries(item.column_values.map((c) => [c.id, c.text || ""]));
      const itemDate = (cv[dateCol] || "").slice(0, 10);
      if (itemDate >= startISO && itemDate <= endISO) {
        const typeCol = columnId(board, "Event type", "status");
        events.push({
          title: item.name,
          date: itemDate,
          type: typeCol ? cv[typeCol] || "" : "",
        });
      }
    }
    cursor = page?.cursor || null;
  } while (cursor);
  return events.sort((a, b) => a.date.localeCompare(b.date));
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

async function buildScheduleSnapshot(question, { scheduleRange } = {}) {
  const { start, end, label } = resolveScheduleRangeForToday(scheduleRange || "two_weeks");

  const lines = [label, ""];

  if (start === end) {
    lines.push(await buildDataSnapshot(start).then((s) => s.split("\n").slice(2).join("\n")));
  } else {
    lines.push("Events (on monday.com):");
    const events = await fetchEventsBetween(start, end);
    if (events.length) {
      for (const e of events) {
        lines.push(`- ${e.date}: ${e.title}${e.type ? ` (${e.type})` : ""}`);
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
  }
  return lines.join("\n");
}

async function buildDataSnapshot(dateISO) {
  const chores = await fetchRotaForDay(dateISO);
  const events = await fetchEventsForDate(dateISO);
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
      lines.push(`- ${e.title}${e.type ? ` (${e.type})` : ""}`);
    }
  } else {
    lines.push("- (none)");
  }
  return lines.join("\n");
}

async function answerFromMondayData(question) {
  const snapshot = await buildScheduleSnapshot(question);
  return openaiLib.answerFromData(question, snapshot);
}

async function buildDailyRecap(dateISO) {
  const chores = await fetchRotaForDay(dateISO);
  const events = await fetchEventsForDate(dateISO);
  const yesterday = yesterdayISO();
  const yesterdayHighlights = await buildYesterdayHighlights(yesterday);
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
      lines.push(`• ${escapeHtml(e.title)}${e.type ? ` (${escapeHtml(e.type)})` : ""}`);
    }
  } else {
    lines.push("• (nothing scheduled)");
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
  const board = await resolveBoard(TASK_INBOX_BOARD_NAME);
  const group =
    board.groups.find((g) => g.title === "Documentation gap") || board.groups[0];
  const name = `Document: ${question}`.slice(0, 200);

  const created = await monday(
    `
      mutation ($boardId: ID!, $groupId: String!, $name: String!) {
        create_item(board_id: $boardId, group_id: $groupId, item_name: $name) { id }
      }
    `,
    { boardId: String(board.id), groupId: group.id, name }
  );
  const itemId = created.create_item.id;

  const values = {};
  const sourceCol = columnId(board, "Source", "status");
  if (sourceCol) values[sourceCol] = { label: "Telegram" };
  const notesCol = columnId(board, "Notes", "long_text");
  if (notesCol) {
    values[notesCol] = {
      text:
        `Auto-logged documentation gap.\n` +
        `Asked by ${senderDisplayName(from)} (${senderHandle(from)}) via Telegram.\n\n` +
        `Question: ${question}`,
    };
  }
  if (Object.keys(values).length) {
    try {
      await changeColumnValues(board.id, itemId, values);
    } catch (err) {
      log("Could not set doc-gap columns:", err.message);
    }
  }
  state.items[itemId] = { status: "", assignees: "", done: false };
  saveState();
  return itemId;
}

// ── /ask: retrieval-augmented answers over the monday docs ──────────────────
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

// ── Telegram -> monday.com: register a member ────────────────────────────────
async function findMemberByTelegram(handle) {
  const board = await resolveBoard(MEMBERS_BOARD_NAME);
  const tgCol = columnId(board, "Telegram username", "text");
  if (!tgCol) return { board, item: null };

  let cursor = null;
  do {
    const data = await monday(
      `
        query ($boardId: ID!, $cursor: String) {
          boards(ids: [$boardId]) {
            items_page(limit: 100, cursor: $cursor) {
              cursor
              items { id name column_values { id text } }
            }
          }
        }
      `,
      { boardId: String(board.id), cursor }
    );
    const page = data.boards?.[0]?.items_page;
    for (const item of page?.items || []) {
      const cv = item.column_values.find((c) => c.id === tgCol);
      if (cv && cv.text && cv.text.toLowerCase() === handle.toLowerCase()) {
        return { board, item };
      }
    }
    cursor = page?.cursor || null;
  } while (cursor);

  return { board, item: null };
}

async function registerMember({ displayName, from, chatId }) {
  const handle = senderHandle(from);
  const { board, item } = await findMemberByTelegram(handle);

  const values = {};
  const tgUserCol = columnId(board, "Telegram username", "text");
  if (tgUserCol) values[tgUserCol] = handle;
  const tgChatCol = columnId(board, "Telegram chat ID", "text");
  if (tgChatCol) values[tgChatCol] = String(chatId);
  const nameCol = columnId(board, "Display name", "text");
  if (nameCol) values[nameCol] = displayName;
  const activeCol = columnId(board, "Active?", "checkbox");
  if (activeCol) values[activeCol] = { checked: "true" };

  if (item) {
    await changeColumnValues(board.id, item.id, values);
    return { updated: true };
  }

  const group = board.groups.find((g) => g.title === "Onboarding") || board.groups[0];
  const created = await monday(
    `
      mutation ($boardId: ID!, $groupId: String!, $name: String!) {
        create_item(board_id: $boardId, group_id: $groupId, item_name: $name) { id }
      }
    `,
    { boardId: String(board.id), groupId: group.id, name: displayName }
  );
  await changeColumnValues(board.id, created.create_item.id, values);
  return { updated: false };
}

// ── Telegram -> monday.com: list my open tasks ───────────────────────────────
async function listMyTasks(from) {
  const handle = senderHandle(from);
  const { item: member } = await findMemberByTelegram(handle);
  const displayName = member ? member.name : senderDisplayName(from);

  const matches = [];
  for (const boardName of WATCH_BOARD_NAMES) {
    let board;
    try {
      board = await resolveBoard(boardName);
    } catch {
      continue;
    }
    const doerCol = columnId(board, "Doer name", "text");
    const helpersCol =
      columnId(board, "Helpers / doers", "text") ||
      columnId(board, "Doer name", "text");
    const statusCol = columnId(board, "Status", "status");

    let cursor = null;
    do {
      const data = await monday(
        `
          query ($boardId: ID!, $cursor: String) {
            boards(ids: [$boardId]) {
              items_page(limit: 100, cursor: $cursor) {
                cursor
                items { id name group { title } column_values { id text } }
              }
            }
          }
        `,
        { boardId: String(board.id), cursor }
      );
      const page = data.boards?.[0]?.items_page;
      for (const item of page?.items || []) {
        if (isDoneName(item.group?.title)) continue;
        const cvById = Object.fromEntries(
          item.column_values.map((c) => [c.id, c.text || ""])
        );
        const doer = (cvById[doerCol] || "") + " " + (cvById[helpersCol] || "");
        const matchesName =
          displayName &&
          doer.toLowerCase().includes(displayName.toLowerCase());
        const matchesHandle = doer.toLowerCase().includes(handle.toLowerCase());
        if (matchesName || matchesHandle) {
          const status = statusCol ? cvById[statusCol] : "";
          matches.push({
            id: item.id,
            name: item.name,
            board: boardName,
            status,
          });
        }
      }
      cursor = page?.cursor || null;
    } while (cursor);
  }
  return { displayName, matches };
}

// ── Telegram -> monday.com: mark done ─────────────────────────────────────────
async function markItemDone(itemId) {
  const data = await monday(
    `
      query ($itemId: [ID!]) {
        items(ids: $itemId) { id name board { id name } }
      }
    `,
    { itemId: [String(itemId)] }
  );
  const item = data.items?.[0];
  if (!item) return { ok: false };

  const board = await resolveBoard(item.board.name);
  const statusCol = columnId(board, "Status", "status");
  const doneGroup = board.groups.find((g) => isDoneName(g.title));

  if (statusCol) {
    await changeColumnValues(board.id, itemId, { [statusCol]: { label: "Done" } });
  }
  if (doneGroup) {
    try {
      await monday(
        `
          mutation ($itemId: ID!, $groupId: String!) {
            move_item_to_group(item_id: $itemId, group_id: $groupId) { id }
          }
        `,
        { itemId: String(itemId), groupId: doneGroup.id }
      );
    } catch (err) {
      log("Could not move item to done group:", err.message);
    }
  }
  return { ok: true, name: item.name, boardName: item.board.name };
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

async function findItemsByTarget({ boardNames, target }) {
  if (target?.item_id) {
    const data = await monday(
      `
        query ($itemId: [ID!]) {
          items(ids: $itemId) { id name board { name } group { title } }
        }
      `,
      { itemId: [String(target.item_id)] }
    );
    const item = data.items?.[0];
    if (!item) return [];
    if (isDoneName(item.group?.title)) return [];
    return [{ id: item.id, name: item.name, boardName: item.board.name }];
  }

  const title = target?.title;
  if (!title) return [];

  const matches = [];
  for (const boardName of boardNames) {
    let board;
    try {
      board = await resolveBoard(boardName);
    } catch {
      continue;
    }
    let cursor = null;
    do {
      const data = await monday(
        `
          query ($boardId: ID!, $cursor: String) {
            boards(ids: [$boardId]) {
              items_page(limit: 100, cursor: $cursor) {
                cursor
                items { id name group { title } }
              }
            }
          }
        `,
        { boardId: String(board.id), cursor }
      );
      const page = data.boards?.[0]?.items_page;
      for (const item of page?.items || []) {
        if (isDoneName(item.group?.title)) continue;
        if (titleMatches(title, item.name)) {
          matches.push({ id: item.id, name: item.name, boardName });
        }
      }
      cursor = page?.cursor || null;
    } while (cursor);
  }
  return matches;
}

async function completeTaskFromChat({ target, text, from, chatId }) {
  const key = ambientDedupeKey(chatId, "complete", target.title || target.item_id);
  if (wasRecentlyCaptured(key)) return { ok: true, duplicate: true };

  const matches = await findItemsByTarget({
    boardNames: WATCH_BOARD_NAMES,
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
    boardNames: [EVENTS_BOARD_NAME],
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
  "You can send a voice note when talking directly to me (not picked up from ambient group chat).",
  "In the group I also watch text chat and log events, tasks, completions and cancellations on monday — I'll confirm when I act.",
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
      log("answerFromMondayData error:", err.message);
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
  const dataResult = await answerFromMondayData(text);
  if (dataResult.answered && dataResult.answer) {
    await sendMessage(chatId, escapeHtml(dataResult.answer));
    return true;
  }
  return false;
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
    if (url) reply += ` — <a href="${url}">monday</a>`;
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
        await sendMessage(chatId, "I couldn't find a matching open task on monday.");
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
        await sendMessage(chatId, "I couldn't find a matching upcoming event on monday.");
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
        if (url) reply += ` — <a href="${url}">monday</a>`;
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
  const voiceFileId = getVoiceFileId(message);
  const photoFileId =
    message.photo && message.photo.length
      ? message.photo[message.photo.length - 1].file_id
      : null;

  let text = stripBotMention((message.text || message.caption || "").trim(), botMe);

  if (!text && voiceFileId) {
    if (!openaiLib.isEnabled()) {
      await sendMessage(
        chatId,
        "Voice notes need OPENAI_API_KEY configured. You can type your message instead."
      );
      return;
    }
    await telegram("sendChatAction", { chat_id: chatId, action: "typing" }).catch(() => {});
    try {
      text = await transcribeVoiceFile(voiceFileId);
      if (!text) {
        await sendMessage(chatId, "I couldn't make out any speech in that voice note.");
        return;
      }
      log(`Voice transcribed for ${senderHandle(from)}: ${text.slice(0, 120)}`);
    } catch (err) {
      log("transcribeVoiceFile error:", err.message);
      await sendMessage(chatId, "Sorry, I couldn't transcribe that voice note. Try typing instead.");
      return;
    }
  } else if (!text) {
    text = addressedText(message, botMe);
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
    const id = args.replace(/[^0-9]/g, "");
    if (!id) {
      await sendMessage(chatId, "Usage: /done <item id> (see ids in /mytasks)");
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
      if (url) reply += `\n<a href="${url}">Open in monday</a>`;
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
  const date = classified.event.date || inferEventDate(text, null);
  const eventType = classified.event.event_type || "Other";
  const key = ambientDedupeKey(chatId, "event", `${classified.event.title}:${date}`);
  if (wasRecentlyCaptured(key)) return true;
  try {
    const { url } = await createEvent({
      title: classified.event.title,
      date,
      eventType,
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
      eventDate: date,
      text,
    });
    let reply = `📅 Logged: <b>${escapeHtml(classified.event.title)}</b> (${escapeHtml(date)})`;
    if (url) reply += ` — <a href="${url}">monday</a>`;
    await sendMessage(chatId, reply);
    log(`Event captured: ${classified.event.title} on ${date}`);
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

  const text = (message.text || message.caption || "").trim();
  const photoFileId =
    message.photo && message.photo.length
      ? message.photo[message.photo.length - 1].file_id
      : null;

  if (!text && !photoFileId) return;
  if (text.length < AMBIENT_MIN_TEXT_LEN && !photoFileId) return;

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

// ── monday.com -> Telegram: announce changes ──────────────────────────────────
async function scanBoardsForChanges({ announce }) {
  for (const boardName of WATCH_BOARD_NAMES) {
    let board;
    try {
      board = await resolveBoard(boardName);
    } catch (err) {
      log(`Watch board missing (${boardName}):`, err.message);
      continue;
    }

    const statusCol = columnId(board, "Status", "status");
    const peopleCols = board.columns
      .filter((c) => c.type === "people")
      .map((c) => c.id);

    let cursor = null;
    do {
      const data = await monday(
        `
          query ($boardId: ID!, $cursor: String) {
            boards(ids: [$boardId]) {
              items_page(limit: 100, cursor: $cursor) {
                cursor
                items { id name group { title } column_values { id text type } }
              }
            }
          }
        `,
        { boardId: String(board.id), cursor }
      );
      const page = data.boards?.[0]?.items_page;

      for (const item of page?.items || []) {
        const cvById = Object.fromEntries(
          item.column_values.map((c) => [c.id, c.text || ""])
        );
        const status = statusCol ? cvById[statusCol] : "";
        const assignees = peopleCols
          .map((id) => cvById[id])
          .filter(Boolean)
          .join(", ");
        const done = isDoneName(item.group?.title) || isDoneName(status);

        const prev = state.items[item.id];
        const url = itemUrl(board.id, item.id);
        const link = url ? ` <a href="${url}">open</a>` : "";

        if (!prev) {
          if (announce && ANNOUNCE_NEW_ITEMS && !done) {
            await sendMessage(
              TELEGRAM_CHAT_ID,
              `🆕 New task on <b>${escapeHtml(boardName)}</b>: ` +
                `${escapeHtml(item.name)}${link}`
            );
          }
        } else if (announce) {
          if (!prev.done && done) {
            await sendMessage(
              TELEGRAM_CHAT_ID,
              `✅ Completed: <b>${escapeHtml(item.name)}</b> (${escapeHtml(boardName)})${link}`
            );
          } else if (assignees && assignees !== prev.assignees) {
            await sendMessage(
              TELEGRAM_CHAT_ID,
              `👤 <b>${escapeHtml(item.name)}</b> assigned to ${escapeHtml(assignees)} (${escapeHtml(boardName)})${link}`
            );
          } else if (status && status !== prev.status && !done) {
            await sendMessage(
              TELEGRAM_CHAT_ID,
              `🔧 <b>${escapeHtml(item.name)}</b> → ${escapeHtml(status)} (${escapeHtml(boardName)})${link}`
            );
          }
        }

        state.items[item.id] = { status, assignees, done };
      }
      cursor = page?.cursor || null;
    } while (cursor);
  }
  saveState();
}

// ── Main loop ─────────────────────────────────────────────────────────────
async function main() {
  if (!TELEGRAM_BOT_TOKEN) {
    throw new Error("Missing TELEGRAM_BOT_TOKEN env variable.");
  }
  if (!MONDAY_API_TOKEN) {
    throw new Error("Missing MONDAY_API_TOKEN env variable.");
  }

  const me = await telegram("getMe");
  botMe = me;
  log(`Telegram bot online: @${me.username}`);
  await getAccountSlug();
  log(`Watching boards: ${WATCH_BOARD_NAMES.join(", ")}`);
  log(
    `Task intake: group ${TASK_GROUP_CHAT_ID}${ALLOW_DM_INTAKE ? " + private DMs" : ""}`
  );
  log(`Ambient capture: ${AMBIENT_CAPTURE ? "ON" : "OFF"} (AI-only routing, task≥${AMBIENT_MIN_CONFIDENCE}, event≥${EVENT_MIN_CONFIDENCE})`);
  log(`Daily recap: ${DAILY_RECAP ? `ON at ${RECAP_TIME} ${RECAP_TZ}` : "OFF"}`);
  log(`Monday announcements → chat ${TELEGRAM_CHAT_ID || "(off)"}`);

  if (openaiLib.isEnabled()) {
    const idx = getDocsIndex();
    log(
      `OpenAI enabled: smart intake ON, /ask ON (${idx.chunks.length} doc chunks indexed).`
    );
    if (!idx.chunks.length) {
      log("  Tip: run `node scripts/index-vila-akoya-docs.js` to index monday docs.");
    }
  } else {
    log("OPENAI_API_KEY not set — smart intake and /ask are OFF (tasks still work).");
  }

  if (!TELEGRAM_CHAT_ID) {
    log(
      "No TELEGRAM_CHAT_ID set — monday->Telegram announcements are OFF. " +
        "Add the bot to your group, send a message, and copy the chat id logged above."
    );
  }

  // Seed the item cache on first run so we don't spam old items.
  if (!state.seededMonday) {
    log("First run: indexing existing monday items (no announcements)...");
    try {
      await scanBoardsForChanges({ announce: false });
    } catch (err) {
      log("Initial monday scan failed:", err.message);
    }
    state.seededMonday = true;
    saveState();
    log("Indexing done.");
  }

  let lastMondayPoll = 0;

  // eslint-disable-next-line no-constant-condition
  while (true) {
    await pollTelegramOnce();
    await maybeSendDailyRecap();

    const now = Date.now();
    if (TELEGRAM_CHAT_ID && now - lastMondayPoll >= MONDAY_POLL_SECONDS * 1000) {
      lastMondayPoll = now;
      try {
        await scanBoardsForChanges({ announce: true });
      } catch (err) {
        if (isTransientNetworkError(err)) {
          logTransientError("monday poll", err, networkState.retryMs);
        } else {
          log("monday poll error:", err.message);
        }
      }
    }
  }
}

main().catch((err) => {
  console.error("Bot crashed:", err);
  process.exit(1);
});
