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
 *   - In private DMs: always on, no tag needed. Photos/PDFs in DMs are treated as receipts unless GPT says otherwise.
 *   - Voice notes are transcribed; documentation dumps become structured Notion pages.
 *   - Receipt photos, PDFs, or pasted till text are analysed and stored on a private Expenses board.
 *   - AI classification routes to Notion actions: tasks, recurring duties, events (internal/external),
 *     reminders, RSVP, notes on projects, completions, cancellations, decisions, docs, FAQs,
 *     expenses, and where things are (keys, tools, and other portable items).
 *   - Addressed messages are read with the recent thread (including VAVA's replies). If a request
 *     is not one of those actions, VAVA can still answer in conversation or read/write Notion
 *     from the live database schema (scripts/lib/vava-agent.js).
 *   - Daily recap (~08:00 Europe/Lisbon): community recap in the group (morning
 *     meeting theme, events, meals, childcare, kids programme, highlights); personal
 *     duties + tasks go by DM to registered members; campsite check-ins/outs and pitch
 *     occupancy go by DM to management (Role / focus = Manager by default).
 *   - /ask, /task, /receipt, /receipts, /register, /questionnaire, /mytasks, /done, /help as before.
 *   - After /register, a short questionnaire (DM only): role + skillsets on
 *     the Members (Community) board; /questionnaire updates it later. Answers
 *     like "1" are accepted even days later; /start resumes a pending form.
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
 * 3. Run: npm run telegram:bot  (stops any previous local copy, then starts one)
 *
 * ── Optional env ──────────────────────────────────────────────────────────
 *   TASK_GROUP_CHAT_ID      group for intake (default: TELEGRAM_CHAT_ID)
 *   AMBIENT_CAPTURE         "true" to enable passive task/event capture (OFF by default)
 *   AMBIENT_MIN_CONFIDENCE  0–1 threshold for task capture (default 0.7)
 *   INTERACTIVE_MIN_CONFIDENCE  0–1 for @bot / DM (default 0.42; more willing to infer)
 *   LOG_DOC_GAPS            "true" to log documentation gaps on failed /ask
 *   DAILY_RECAP             "false" to disable morning recap
 *   RECAP_TIME              HH:MM in RECAP_TZ (default "08:00")
 *   RECAP_TZ                default "Europe/Lisbon"
 *   REMINDER_TIME           default send time for Notion broadcast reminders (default RECAP_TIME)
 *   TELEGRAM_CHAT_ALIASES   JSON map of group name → chat id, e.g. {"Parents & Teachers":"-100..."}
 *   TELEGRAM_TOPIC_ALIASES  JSON map of topic name → message_thread_id, e.g. {"Parents & Teachers":123}
 *   EVENT_CAPTURE_GROUP     Notion Stage for new events (default "Upcoming")
 *   EVENT_RECURRING_GROUP   Notion Stage for repeating events (default "Recurring")
 *   NOTION_POLL_SECONDS     how often to poll Notion for changes (default 60)
 *   CHILDCARE_DB            Notion childcare database title override
 *   KIDS_PROGRAMME_DB       Kids Programme Shifts database title override
 *   KIDS_PROGRAMME_TELEGRAM_CHAT_ID  Parents & Teachers chat (or TELEGRAM_CHAT_ALIASES)
 *   KIDS_PROGRAMME_TOPIC_ID          Forum topic for daily programme roundup
 *   VILA_OPS_API_TOKEN      WordPress ops token, needed for childcare absence writes
 */

"use strict";

const fs = require("fs");
const path = require("path");
const { execSync } = require("child_process");
require("./lib/load-env");
const notion = require("./lib/notion");
const campsiteOps = require("./lib/campsite-ops");
const openaiLib = require("./lib/openai");
const docsIndex = require("./lib/docs-index");
const locationsLib = require("./lib/locations");
const faqsLib = require("./lib/faqs");
const broadcastRemindersLib = require("./lib/broadcast-reminders");
const guestStaysLib = require("./lib/guest-stays");
const communityCookOffer = require("./lib/community-cook-offer");
const kidsProgrammeLib = require("./lib/kids-programme-shifts");
const vavaAgent = require("./lib/vava-agent");
const {
  messageRequestsNotionRemoval,
  looksLikeEventCancellationRequest,
  inferCancelAllFromText,
} = require("./lib/notion-guard");
const {
  CHAT_ACTIVITY_KIND,
  buildWeekCalendarContext,
  resolveScheduleRange,
  TASK_TYPES,
  TASK_PRIORITIES,
  DUTY_FREQUENCIES,
  EVENT_TYPES,
  LOCATION_UPDATE_KINDS,
} = require("./lib/community-actions");
const vavaToolAgent = require("./lib/vava-tool-agent");
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
  staggeredWeeklyDueDate,
} = require("./lib/duty-schedule");
const regionalMarkets = require("./lib/regional-markets");
const marketRatings = require("./lib/market-ratings");
const medicalFacilities = require("./lib/medical-facilities");

// ── Config ──────────────────────────────────────────────────────────────────
const TELEGRAM_BOT_TOKEN = process.env.TELEGRAM_BOT_TOKEN || "";
if (!TELEGRAM_BOT_TOKEN) {
  console.error("TELEGRAM_BOT_TOKEN is not set. Add it to .env in the repo root.");
  process.exit(1);
}
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
const EXPENSES_DB_NAME = process.env.EXPENSES_DB_NAME || notion.DB.EXPENSES;
const CAMPSITE_BOOKINGS_DB_NAME =
  process.env.CAMPSITE_BOOKINGS_DB || notion.DB.BOOKINGS;
const CAMPSITE_MEALS_DB_NAME = process.env.CAMPSITE_MEALS_DB || notion.DB.MEALS;
const CHILDCARE_DB_NAME = process.env.CHILDCARE_DB || notion.DB.CHILDCARE;
const KIDS_PROGRAMME_DB_NAME =
  process.env.KIDS_PROGRAMME_DB || kidsProgrammeLib.DB_NAME;
const LOCATIONS_DB_NAME = process.env.LOCATIONS_DB_NAME || notion.DB.LOCATIONS;
const FAQS_DB_NAME = process.env.FAQS_DB_NAME || notion.DB.FAQS;

const AMBIENT_CAPTURE = process.env.AMBIENT_CAPTURE === "true";
const AMBIENT_MIN_CONFIDENCE = Number(process.env.AMBIENT_MIN_CONFIDENCE || 0.7);
const INTERACTIVE_MIN_CONFIDENCE = Number(process.env.INTERACTIVE_MIN_CONFIDENCE || 0.42);
const AMBIENT_MIN_TEXT_LEN = Number(process.env.AMBIENT_MIN_TEXT_LEN || 4);
const EVENT_MIN_CONFIDENCE = Number(process.env.EVENT_MIN_CONFIDENCE || 0.5);
const LOG_DOC_GAPS = process.env.LOG_DOC_GAPS === "true";
const DAILY_RECAP = process.env.DAILY_RECAP !== "false";
const RECAP_TIME = process.env.RECAP_TIME || "08:00";
const RECAP_TZ = process.env.RECAP_TZ || "Europe/Lisbon";
const SITE_URL = String(process.env.WP_SITE_URL || "https://vila-akoya.com").replace(/\/$/, "");
const DINNER_BOOKING_URL = `${SITE_URL}/book-meals/`;
const CHILDCARE_BOOKING_URL = `${SITE_URL}/book-childcare/`;
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
const LOCK_FILE = process.env.LOCK_FILE || `${STATE_FILE}.lock`;

/** True after a successful getUpdates. Duplicate instances must not send recaps. */
let telegramPollActive = false;
let pollConflictStreak = 0;
let lockFd = null;

// Group titles / status labels that count as "completed".
const DONE_KEYWORDS = ["done", "logged", "decided", "past", "completed"];

// Chat activity kinds worth surfacing when AI summarisation is unavailable.
const RECAP_IMPORTANT_KINDS = new Set([
  "event_logged",
  "task_logged",
  "duty_logged",
  "guest_logged",
  "task_completed",
  "event_cancelled",
  "decision_logged",
  "announcement",
  "doc_logged",
  "stay_logged",
]);

/** Weekday morning-meeting focus, keyed by dayKeyFromISO (Mon–Fri). */
const MORNING_MEETING_THEMES = {
  Mon: "general",
  Tue: "kids",
  Wed: "villagers",
  Thu: "commercial",
  Fri: "creative",
};

let botMe = null;
const recentMessages = new Map();
/** In-flight voice transcriptions keyed by `${chatId}:${messageId}`. */
const pendingVoiceTranscriptions = new Map();

// ── Small utilities ───────────────────────────────────────────────────────
function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

const networkState = { retryMs: 3000, lastErrorLog: 0, lastPollTimeoutLog: 0 };

function isAbortTimeoutError(err) {
  const name = String(err?.name || "");
  const msg = String(err?.message || "").toLowerCase();
  return (
    name === "TimeoutError" ||
    name === "AbortError" ||
    msg.includes("aborted due to timeout") ||
    msg.includes("the operation was aborted") ||
    msg.includes("aborterror")
  );
}

function isTransientNetworkError(err) {
  if (isAbortTimeoutError(err)) return true;
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
      // Long-poll client aborts are normal — don't exponential-backoff them.
      if (isAbortTimeoutError(err)) throw err;
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

function isNotionTransientError(err) {
  return /Notion HTTP (500|502|503|504)|unavailable|memcached|try again later/i.test(
    String(err?.message || err)
  );
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

function morningMeetingThemeForDate(dateISO, tz = RECAP_TZ) {
  const day = dayKeyFromISO(dateISO, tz);
  if (day === "Sat" || day === "Sun") return null;
  return MORNING_MEETING_THEMES[day] || null;
}

function isWeekdayDinnerDay(dateISO, meals) {
  if (meals?.isWeekday === false) return false;
  if (meals?.isWeekday === true) return true;
  const day = dayKeyFromISO(dateISO);
  return day !== "Sat" && day !== "Sun";
}

function dinnerBookingReminderLine() {
  return `\n\nWant dinner tonight? <a href="${DINNER_BOOKING_URL}">Book it on the website</a>.`;
}

function looksLikeDinnerBookingRequest(text) {
  const t = String(text || "").toLowerCase();
  if (
    /\b(how many|quantos|numbers?|headcount|who(?:'s| is) cooking)\b/.test(t) &&
    /\b(dinner|jantar)\b/.test(t)
  ) {
    return false;
  }
  return (
    /\b(?:want|like|need|book|sign\s*(?:me\s*)?up|count\s*me\s*in|i'?ll\s*have|can\s*i\s*have|join|eat with|gostaria|quero)\b.*\b(?:dinner|jantar)\b/.test(
      t
    ) ||
    /\b(?:dinner|jantar)\b.*\b(?:tonight|today|this evening|esta noite|hoje|amanh[aã])\b/.test(t) ||
    /\bbook\s+(?:my\s+)?(?:the\s+)?(?:weekday\s+)?dinner\b/.test(t) ||
    /\bcan i eat (?:with you|there|tonight)\b/.test(t) ||
    /\bis there dinner (?:i can|to)\b/.test(t) ||
    /\b(?:reservar|marcar)\b.*\b(?:jantar|dinner)\b/.test(t)
  );
}

function looksLikeChildcareBookingRequest(text) {
  const t = String(text || "").toLowerCase();
  return (
    /\b(?:book|reserve|marcar|reservar)\b.*\b(?:childcare|child care|kids?|children|babysit|babysitter|childminder|child minder)\b/.test(t) ||
    /\b(?:need|want|quero|preciso)\b.*\b(?:childcare|child care|babysit|babysitter|childminder|child minder)\b/.test(t)
  );
}

function looksLikeStayBooking(text) {
  const t = String(text || "").toLowerCase();
  if (!t) return false;
  if (looksLikeDinnerBookingRequest(t) || looksLikeChildcareBookingRequest(t)) return false;
  const hasStay =
    /\b(campsite|camping|pitch|plot|tent|cabin|cabana|room|house|accommodation|alojamento|glamping|yurt|guest|guests|stay|staying|check[- ]?in|check[- ]?out|reserva)\b/.test(
      t
    );
  const hasBook =
    /\b(booked|booking|reserva|reservaram|reservou|arrived by email|via email|from (an )?email|off[- ]site)\b/.test(
      t
    );
  return hasStay && hasBook;
}

function looksLikeChildcareAbsenceRequest(text) {
  const t = String(text || "").toLowerCase();
  return (
    (
      /\b(?:won'?t|will not|can't|cannot|isn'?t|not|nao|não)\s+(?:come|attend|be there|go|be in)\b/.test(t) &&
      /\b(?:childcare|child care|today|hoje|kid|child|children|kids)\b/.test(t)
    ) ||
    /\b(?:child|kid|filh[oa])\b.*\b(?:absent|sick|doente|won'?t come|nao vai|não vai|off today|staying home)\b/.test(t) ||
    /\b(?:keeping|keeping .{1,40} home|off (?:childcare|kids)|not sending)\b/.test(t) ||
    /\b(?:n[aã]o vai(?:\s+hoje)? (?:para )?(?:o |a )?(?:childcare|infant[aá]rio|kids))\b/.test(t)
  );
}

function extractChildcareAbsence(text) {
  const raw = String(text || "").trim();
  const patterns = [
    /\b(?:my\s+(?:child|kid|son|daughter)\s+)?([A-ZÀ-ÖØ-Þ][\p{L}'-]{1,}(?:\s+[A-ZÀ-ÖØ-Þ][\p{L}'-]{1,})?)\s+(?:won'?t|will not|can't|cannot|isn'?t|not)\s+(?:come|attend|be there|go)/u,
    /\b([A-ZÀ-ÖØ-Þ][\p{L}'-]{1,}(?:\s+[A-ZÀ-ÖØ-Þ][\p{L}'-]{1,})?)\s+(?:is\s+)?(?:absent|sick|doente)/u,
    /\b(?:kid|child|filh[oa])\s+([A-ZÀ-ÖØ-Þ][\p{L}'-]{1,}(?:\s+[A-ZÀ-ÖØ-Þ][\p{L}'-]{1,})?)/u,
  ];
  for (const pattern of patterns) {
    const match = raw.match(pattern);
    if (match?.[1]) return { childName: match[1].trim(), date: todayISO() };
  }
  return { childName: "", date: todayISO() };
}

function looksLikeReceiptIntent(text) {
  const t = String(text || "").toLowerCase();
  if (!t) return false;
  return (
    /\b(receipt|recibo|factura|fatura|tal[aã]o|invoice|despesa|expense|reimburse|reembolso)\b/.test(
      t
    ) ||
    /\b(i spent|gastei|paguei|this cost|costs? €|euros? on)\b/.test(t) ||
    /^\/?(receipt|expense|recibo)\b/.test(t)
  );
}

function looksLikeExpenseQuestion(text) {
  const t = String(text || "").toLowerCase();
  if (!t) return false;
  return (
    /\b(how much (did|have) i spend|what have i spent|my (expenses|receipts|spending)|who (needs|is owed) reimburs|receipts this month|show (me )?(the )?(receipts|expenses))\b/.test(
      t
    ) ||
    /\b(quanto (gastei|despesa)|os meus recibos|quem (precisa|tem) (de )?reembolso)\b/.test(t)
  );
}

function monthKeyFromISO(iso) {
  const m = String(iso || "").match(/^(\d{4}-\d{2})/);
  return m ? m[1] : String(todayISO()).slice(0, 7);
}

function formatMoney(amount, currency = "EUR") {
  if (amount == null || !Number.isFinite(Number(amount))) return "—";
  const code = currency === "Other" ? "EUR" : currency || "EUR";
  try {
    return new Intl.NumberFormat("pt-PT", {
      style: "currency",
      currency: code,
    }).format(Number(amount));
  } catch {
    return `${Number(amount).toFixed(2)} ${currency || "EUR"}`;
  }
}

/** Accounting streams — separate NIFs for tax / reimbursement. */
const EXPENSE_ENTITIES = [
  {
    id: 1,
    notionSelect: "Aldeia Alcateia",
    legalName: "ALDEIA ALCATEIA LDA",
    nif: "517908794",
  },
  {
    id: 2,
    notionSelect: "Povoado Gentil",
    legalName: "POVOADO GENTIL LDA",
    nif: "519423178",
  },
];

const EXPENSE_ENTITY_TIMEOUT_MS = 60 * 60 * 1000;

function expenseEntityPrompt(preview) {
  const lines = [
    "Which NIF was on this receipt? Reply <b>1</b> or <b>2</b> (or <i>Alcateia</i> / <i>Povoado</i>):",
    "",
    "1. ALDEIA ALCATEIA LDA 517908794",
    "2. POVOADO GENTIL LDA 519423178",
  ];
  if (preview?.title) {
    let head = `🧾 <b>${escapeHtml(preview.title)}</b>`;
    if (preview.amount != null) {
      head += ` · ${escapeHtml(formatMoney(preview.amount, preview.currency))}`;
    }
    if (preview.merchant) head += `\n${escapeHtml(preview.merchant)}`;
    lines.unshift(head, "");
  }
  return lines.join("\n");
}

function parseExpenseEntityChoice(text) {
  const raw = String(text || "").trim();
  const t = raw.toLowerCase();
  if (!t) return null;
  if (t === "1") return EXPENSE_ENTITIES[0];
  if (t === "2") return EXPENSE_ENTITIES[1];
  const alcateia = /\b(alcateia|aldeia)\b/.test(t);
  const povoado = /\b(povoado|gentil)\b/.test(t);
  if (alcateia && !povoado) return EXPENSE_ENTITIES[0];
  if (povoado && !alcateia) return EXPENSE_ENTITIES[1];
  return null;
}

/** What the receipt is for — camp operations, not the OCR spend category. */
const EXPENSE_PURPOSES = [
  { id: 1, name: "Breakfast" },
  { id: 2, name: "Bar" },
  { id: 3, name: "Dinner" },
  { id: 4, name: "Kids Program" },
  { id: 5, name: "General" },
];

function expensePurposeByName(name) {
  const n = String(name || "").trim().toLowerCase();
  return EXPENSE_PURPOSES.find((p) => p.name.toLowerCase() === n) || null;
}

function expensePreviewFromExtracted(extracted) {
  return {
    title: extracted?.title || extracted?.merchant || "Receipt",
    amount: extracted?.amount,
    currency: extracted?.currency || "EUR",
    merchant: extracted?.merchant || "",
  };
}

function expensePurposePrompt(preview, entity) {
  const lines = [
    "What is this receipt for? Reply <b>1</b>, <b>2</b>, <b>3</b>, <b>4</b>, or <b>5</b>:",
    "",
    "1. Breakfast",
    "2. Bar",
    "3. Dinner",
    "4. Kids Program",
    "5. General",
  ];
  if (preview?.title) {
    let head = `🧾 <b>${escapeHtml(preview.title)}</b>`;
    if (preview.amount != null) {
      head += ` · ${escapeHtml(formatMoney(preview.amount, preview.currency))}`;
    }
    if (preview.merchant) head += `\n${escapeHtml(preview.merchant)}`;
    lines.unshift(head, "");
  }
  if (entity?.legalName) {
    lines.push("", `<i>${escapeHtml(entity.legalName)}</i>`);
  }
  return lines.join("\n");
}

/** Only read purpose from a short caption — not pasted till text (avoids false "bar" hits). */
function parseExplicitExpensePurpose(text) {
  const raw = String(text || "").trim();
  if (!raw) return null;
  if (raw.length > 120 || raw.split(/\n/).length > 3) return null;
  return parseExpensePurposeChoice(raw, { allowNumbers: true });
}

function parseExpensePurposeChoice(text, { allowNumbers = false } = {}) {
  const t = String(text || "").trim().toLowerCase();
  if (!t) return null;
  if (allowNumbers && /^[1-5]$/.test(t)) {
    return EXPENSE_PURPOSES[Number(t) - 1];
  }
  if (
    /\b(kids?\s*progr?amme?|kids|childcare|crian[cç]as|programa(?:\s+das?|\s+de)?\s+crian[cç]as)\b/.test(
      t
    )
  ) {
    return EXPENSE_PURPOSES[3];
  }
  if (/\b(breakfast|pequeno[-\s]?almo[cç]o)\b/.test(t)) {
    return EXPENSE_PURPOSES[0];
  }
  if (/\b(dinner|jantar|supper)\b/.test(t)) {
    return EXPENSE_PURPOSES[2];
  }
  if (/\bbar\b/.test(t)) return EXPENSE_PURPOSES[1];
  if (/\b(general|geral)\b/.test(t)) return EXPENSE_PURPOSES[4];
  return null;
}

function expensePurposeFollowupText() {
  return (
    "Reply <b>1</b>, <b>2</b>, <b>3</b>, <b>4</b>, or <b>5</b>:\n\n" +
    "1. Breakfast\n2. Bar\n3. Dinner\n4. Kids Program\n5. General"
  );
}

async function replyWithDinnerBookingLink(chatId, dateISO) {
  let meals = null;
  try {
    meals = await fetchCampsiteMealsForDay(dateISO);
  } catch {
    // Notion meals row optional — fall back to weekday from date.
  }
  const text = isWeekdayDinnerDay(dateISO, meals)
    ? `Want dinner tonight? <a href="${DINNER_BOOKING_URL}">Book it on the website</a>.`
    : `There's no communal dinner at weekends. Weekday dinners (Mon–Fri) can be booked at <a href="${DINNER_BOOKING_URL}">book-meals</a>.`;
  await sendMessage(chatId, text);
}

function messageThreadId(message) {
  return message?.is_topic_message ? message.message_thread_id || null : null;
}

function pushChatMemory(chatId, entry) {
  if (!chatId || !entry?.text) return;
  const buf = recentMessages.get(chatId) || [];
  buf.push(entry);
  while (buf.length > 60) buf.shift();
  recentMessages.set(chatId, buf);
}

/** Recent lines for this chat, limited to the current forum topic when there is one. */
function memoryFor(chatId, threadId = undefined) {
  const buf = recentMessages.get(chatId) || [];
  const thread =
    threadId === undefined ? replyThreadExtra.message_thread_id || null : threadId;
  if (!thread) return buf.filter((entry) => !entry.threadId);
  const id = String(thread);
  return buf.filter((entry) => String(entry.threadId || "") === id);
}

function bufferMessage(message) {
  const chatId = message.chat?.id;
  if (!chatId) return;
  const text = (message.text || message.caption || "").trim();
  if (!text) return;
  pushChatMemory(chatId, {
    messageId: message.message_id,
    text,
    from: message.from,
    name: senderDisplayName(message.from),
    role: "user",
    threadId: messageThreadId(message),
    ts: Date.now(),
  });
}

function bufferTranscribedText(message, text) {
  const chatId = message.chat?.id;
  if (!chatId || !text) return;
  pushChatMemory(chatId, {
    messageId: message.message_id,
    text,
    from: message.from,
    name: senderDisplayName(message.from),
    role: "user",
    threadId: messageThreadId(message),
    ts: Date.now(),
    isVoice: true,
  });
}

function plainFromTelegramHtml(text) {
  return String(text || "")
    .replace(/<br\s*\/?>/gi, " ")
    .replace(/<[^>]+>/g, "")
    .replace(/&amp;/g, "&")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/&quot;/g, '"')
    .replace(/&#39;|&apos;/g, "'")
    .replace(/\s+/g, " ")
    .trim();
}

/** Keep VAVA's own replies in the thread so "yes" / "that one" can refer to them. */
function rememberBotReply(chatId, text) {
  const plain = plainFromTelegramHtml(text);
  if (!plain || plain.length > 1200) return;
  pushChatMemory(chatId, {
    messageId: `bot-${Date.now()}`,
    text: plain.slice(0, 420),
    name: "VAVA",
    role: "assistant",
    isBot: true,
    threadId: replyThreadExtra.message_thread_id || null,
    ts: Date.now(),
  });
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
  const buf = memoryFor(chatId, messageThreadId(message));
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
  const buf = memoryFor(chatId, messageThreadId(message));
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
    if (!s.pendingExpenseEntity) s.pendingExpenseEntity = {};
    if (!s.pendingQuestionnaire) s.pendingQuestionnaire = {};
    else s.pendingQuestionnaire = normalizePendingQuestionnaires(s.pendingQuestionnaire);
    if (s.lastPersonalRecapDate === undefined) s.lastPersonalRecapDate = null;
    if (s.lastManagementCampsiteBriefingDate === undefined) {
      s.lastManagementCampsiteBriefingDate = null;
    }
    if (s.lastChildcareRecapDate === undefined) s.lastChildcareRecapDate = null;
    if (s.lastKidsProgrammeRecapDate === undefined) s.lastKidsProgrammeRecapDate = null;
    if (!s.telegramTopicAliases) s.telegramTopicAliases = {};
    if (!s.broadcastRemindersSent) s.broadcastRemindersSent = {};
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
      lastManagementCampsiteBriefingDate: null,
      lastChildcareRecapDate: null,
      lastKidsProgrammeRecapDate: null,
      telegramTopicAliases: {},
      broadcastRemindersSent: {},
      ambientKeys: {},
      chatActivity: [],
      chatLog: [],
      items: {},
      pendingClarify: {},
      pendingExpenseEntity: {},
      pendingQuestionnaire: {},
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

function pidIsRunning(pid) {
  if (!Number.isInteger(pid) || pid <= 0) return false;
  try {
    process.kill(pid, 0);
    return true;
  } catch {
    return false;
  }
}

function releaseInstanceLock() {
  try {
    if (lockFd != null) fs.closeSync(lockFd);
  } catch {
    // already closed
  }
  lockFd = null;
  try {
    fs.unlinkSync(LOCK_FILE);
  } catch {
    // already gone
  }
}

function listBotPids() {
  try {
    const out = execSync("pgrep -f vila-akoya-telegram-bot\\.js", {
      encoding: "utf8",
      stdio: ["ignore", "pipe", "ignore"],
    });
    return out
      .split(/\s+/)
      .map((s) => parseInt(s, 10))
      .filter((pid) => Number.isInteger(pid) && pid > 0 && pid !== process.pid);
  } catch {
    return [];
  }
}

function stopPid(pid, signal) {
  try {
    process.kill(pid, signal);
    return true;
  } catch {
    return false;
  }
}

function sleepSync(ms) {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
}

function stopOtherBotProcesses() {
  const pids = new Set(listBotPids());
  let lockPid = 0;
  try {
    lockPid = parseInt(fs.readFileSync(LOCK_FILE, "utf8"), 10) || 0;
  } catch {
    lockPid = 0;
  }
  if (lockPid && lockPid !== process.pid && pidIsRunning(lockPid)) pids.add(lockPid);
  if (!pids.size) return;

  for (const pid of pids) {
    log(`Stopping previous bot instance (pid ${pid})`);
    stopPid(pid, "SIGTERM");
  }
  const deadline = Date.now() + 5000;
  while (Date.now() < deadline) {
    if (![...pids].some((pid) => pidIsRunning(pid))) break;
    sleepSync(250);
  }
  for (const pid of pids) {
    if (!pidIsRunning(pid)) continue;
    log(`Force-stopping previous bot instance (pid ${pid})`);
    stopPid(pid, "SIGKILL");
  }
  try {
    fs.unlinkSync(LOCK_FILE);
  } catch {
    // already gone
  }
}

function acquireInstanceLock() {
  stopOtherBotProcesses();
  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      lockFd = fs.openSync(LOCK_FILE, "wx");
      fs.writeSync(lockFd, String(process.pid));
      process.on("exit", releaseInstanceLock);
      for (const sig of ["SIGINT", "SIGTERM"]) {
        process.on(sig, () => {
          releaseInstanceLock();
          process.exit(0);
        });
      }
      return;
    } catch (err) {
      if (err.code !== "EEXIST") throw err;
      log("Removing leftover bot lock after stopping the previous instance");
      try {
        fs.unlinkSync(LOCK_FILE);
      } catch {
        // raced with the process we just stopped
      }
    }
  }
  throw new Error(`Could not acquire bot lock at ${LOCK_FILE}`);
}

function isGetUpdatesConflict(err) {
  const msg = String(err?.message || "");
  return msg.includes('"error_code":409') || msg.includes("terminated by other getUpdates");
}

function refreshRecapDatesFromDisk() {
  try {
    const disk = JSON.parse(fs.readFileSync(STATE_FILE, "utf8"));
    if (disk.lastRecapDate) state.lastRecapDate = disk.lastRecapDate;
    if (disk.lastPersonalRecapDate) state.lastPersonalRecapDate = disk.lastPersonalRecapDate;
    if (disk.lastManagementCampsiteBriefingDate) {
      state.lastManagementCampsiteBriefingDate = disk.lastManagementCampsiteBriefingDate;
    }
    if (disk.lastChildcareRecapDate) state.lastChildcareRecapDate = disk.lastChildcareRecapDate;
    if (disk.lastKidsProgrammeRecapDate) state.lastKidsProgrammeRecapDate = disk.lastKidsProgrammeRecapDate;
  } catch {
    // state file missing or unreadable
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
async function telegram(method, payload, { clientTimeoutMs, attempts = 4 } = {}) {
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
    attempts,
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
  if (!chatId) return false;
  if (text == null || String(text).trim() === "") {
    log("sendMessage error: empty text");
    return false;
  }
  const isPrivate = Number(chatId) > 0;
  const payload = {
    chat_id: chatId,
    text,
    parse_mode: "HTML",
    disable_web_page_preview: true,
    ...(isPrivate ? {} : replyThreadExtra),
    ...extra,
  };
  // Forum thread ids are invalid in private chats and make DMs fail silently.
  if (isPrivate) delete payload.message_thread_id;
  try {
    await telegram("sendMessage", payload);
    rememberBotReply(chatId, text);
    return true;
  } catch (err) {
    log("sendMessage error:", err.message);
    return false;
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

function getMessageMedia(message) {
  const candidates = [message, message.reply_to_message].filter(Boolean);
  for (const src of candidates) {
    if (src.photo?.length) {
      const p = src.photo[src.photo.length - 1];
      return {
        fileId: p.file_id,
        kind: "image",
        mime: "image/jpeg",
        filename: "receipt.jpg",
        source: "Photo",
      };
    }
    const doc = src.document;
    if (!doc?.file_id) continue;
    const mime = String(doc.mime_type || "");
    const name = doc.file_name || "receipt";
    const isPdf = mime === "application/pdf" || /\.pdf$/i.test(name);
    const isImage =
      mime.startsWith("image/") ||
      /\.(jpe?g|png|webp|gif|heic|heif)$/i.test(name);
    if (!isPdf && !isImage) continue;
    return {
      fileId: doc.file_id,
      kind: isPdf ? "pdf" : "image",
      mime: mime || (isPdf ? "application/pdf" : "image/jpeg"),
      filename: name,
      source: isPdf ? "PDF" : "Photo",
    };
  }
  return null;
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

async function expenseSubmitterName(from) {
  try {
    const { page } = await findMemberByTelegram(senderHandle(from), from?.id);
    if (page) {
      const named =
        notion.pageTitle(page) || notion.pagePropText(page, "Display name");
      if (named) return named;
    }
  } catch {
    // Members lookup is optional.
  }
  return senderDisplayName(from);
}

async function createExpense({
  extracted,
  from,
  chatId,
  sourceText,
  source,
  upload,
  mediaKind,
  entity,
  purpose,
}) {
  const date = extracted?.date || todayISO();
  const merchant = extracted?.merchant || "";
  const title =
    (extracted?.title || merchant || "Receipt").slice(0, 80) || "Receipt";
  const submitter = await expenseSubmitterName(from);
  const handle = senderHandle(from);
  const month = monthKeyFromISO(date);
  const lineItems = (extracted?.line_items || []).filter(Boolean);
  const receiptText = String(extracted?.receipt_text || sourceText || "").slice(
    0,
    1900
  );
  const notes = [
    extracted?.notes || "",
    lineItems.length ? `Items: ${lineItems.join("; ")}` : "",
    `Logged from Telegram by ${submitter} (${handle})`,
    sourceText && sourceText !== receiptText
      ? `Caption: ${String(sourceText).slice(0, 400)}`
      : "",
  ]
    .filter(Boolean)
    .join("\n\n")
    .slice(0, 1900);

  const properties = {
    Amount: extracted?.amount,
    Currency: extracted?.currency || "EUR",
    Date: date,
    Month: month,
    "Submitted by": submitter,
    "Telegram username": handle,
    "Telegram user ID": from?.id != null ? String(from.id) : "",
    Category: extracted?.category || "Other",
    Status: "To reimburse",
    Merchant: merchant,
    VAT: extracted?.vat,
    Source: source || "Text",
    "Receipt text": receiptText,
    Notes: notes,
  };
  if (purpose?.name) {
    properties.For = purpose.name;
  }
  if (entity) {
    properties.Entity = entity.notionSelect;
    properties.NIF = entity.nif;
    properties.Company = entity.legalName;
  }

  const children = [];
  if (receiptText) {
    children.push({
      object: "block",
      type: "paragraph",
      paragraph: { rich_text: notion.richText(receiptText) },
    });
  }

  const page = await notion.createPage({
    databaseName: EXPENSES_DB_NAME,
    title,
    properties,
    children,
  });

  if (upload?.id) {
    try {
      await notion.attachFileToPage(page.id, {
        uploadId: upload.id,
        filename: upload.filename,
        propertyName: "Receipt",
        kind: mediaKind === "pdf" ? "pdf" : "image",
      });
    } catch (err) {
      log("attach receipt file error:", err.message);
    }
  }

  return {
    url: page.url,
    id: page.id,
    title,
    submitter,
    date,
    month,
    amount: extracted?.amount,
    currency: extracted?.currency || "EUR",
    merchant,
    entity: entity?.notionSelect || null,
    purpose: purpose?.name || null,
  };
}

async function finalizeAndLogExpense({
  extracted,
  from,
  chatId,
  sourceText,
  source,
  upload,
  mediaKind,
  entity,
  purpose,
}) {
  const result = await createExpense({
    extracted,
    from,
    chatId,
    sourceText,
    source,
    upload,
    mediaKind,
    entity,
    purpose,
  });
  let reply = `🧾 Logged expense: <b>${escapeHtml(result.title)}</b>`;
  if (result.amount != null) {
    reply += `\n${escapeHtml(formatMoney(result.amount, result.currency))}`;
  } else {
    reply += `\n<i>Amount not read — please check Notion</i>`;
  }
  if (result.date) reply += ` · ${escapeHtml(result.date)}`;
  if (result.merchant) reply += `\n${escapeHtml(result.merchant)}`;
  if (entity) {
    reply += `\n<b>${escapeHtml(entity.legalName)}</b> · NIF ${escapeHtml(entity.nif)}`;
  }
  if (purpose?.name) {
    reply += `\nFor: <b>${escapeHtml(purpose.name)}</b>`;
  }
  reply += `\nSubmitted by <b>${escapeHtml(result.submitter)}</b>`;
  if (result.url) reply += `\n<a href="${result.url}">Open in Notion</a>`;
  await sendMessage(chatId, reply);
  return result;
}

function pendingExpenseKey(chatId, fromId) {
  return `${chatId}:${fromId}`;
}

async function queueExpenseFollowup({
  extracted,
  from,
  chatId,
  sourceText,
  source,
  media,
  upload,
  entity = null,
  purpose = null,
  step = "entity",
}) {
  const key = pendingExpenseKey(chatId, from?.id);
  const hadPending = Boolean(state.pendingExpenseEntity?.[key]);
  state.pendingExpenseEntity = state.pendingExpenseEntity || {};
  state.pendingExpenseEntity[key] = {
    ts: Date.now(),
    step,
    extracted,
    sourceText: sourceText || "",
    source: source || "Text",
    mediaKind: media?.kind || null,
    telegramFileId: media?.fileId || null,
    mediaFilename: media?.filename || null,
    mediaMime: media?.mime || null,
    uploadId: upload?.id || null,
    uploadFilename: upload?.filename || null,
    fromId: from?.id,
    entity,
    purpose,
  };
  saveState();
  const preview = expensePreviewFromExtracted(extracted);
  let msg =
    step === "purpose"
      ? expensePurposePrompt(preview, entity)
      : expenseEntityPrompt(preview);
  if (hadPending) {
    const waiting =
      step === "purpose" ? "waiting for 1–5." : "waiting for 1 or 2.";
    msg =
      `<i>Previous receipt cancelled — this is the one ${waiting}</i>\n\n` + msg;
  }
  await sendMessage(chatId, msg);
}

async function resolvePendingExpenseUpload(pending) {
  if (pending.uploadId) {
    return {
      id: pending.uploadId,
      filename: pending.uploadFilename || "receipt.jpg",
    };
  }
  if (!pending.telegramFileId) return null;
  try {
    const downloaded = await downloadTelegramFile(pending.telegramFileId);
    const filename =
      pending.mediaFilename ||
      downloaded.filename ||
      (pending.mediaKind === "pdf" ? "receipt.pdf" : "receipt.jpg");
    const mime =
      pending.mediaMime ||
      notion.guessContentType(
        filename,
        pending.mediaKind === "pdf" ? "application/pdf" : "image/jpeg"
      );
    return await notion.uploadFile(downloaded.buf, filename, mime);
  } catch (err) {
    log("resolvePendingExpenseUpload error:", err.message);
    return null;
  }
}

async function completePendingExpense(message, pending, entity, purpose) {
  const chatId = message.chat.id;
  if (!purpose?.name) {
    pending.entity = entity;
    pending.step = "purpose";
    pending.ts = Date.now();
    saveState();
    await sendMessage(
      chatId,
      expensePurposePrompt(
        expensePreviewFromExtracted(pending.extracted),
        entity
      )
    );
    return;
  }
  const key = pendingExpenseKey(chatId, message.from?.id);
  delete state.pendingExpenseEntity[key];
  saveState();
  const upload = await resolvePendingExpenseUpload(pending);
  await finalizeAndLogExpense({
    extracted: pending.extracted,
    from: message.from,
    chatId,
    sourceText: pending.sourceText,
    source: pending.source,
    upload,
    mediaKind: pending.mediaKind,
    entity,
    purpose,
  });
}

async function handlePendingExpenseEntity(message) {
  const chatId = message.chat.id;
  const fromId = message.from?.id;
  const key = pendingExpenseKey(chatId, fromId);
  const pending = state.pendingExpenseEntity?.[key];
  if (!pending) return false;
  if (Date.now() - pending.ts > EXPENSE_ENTITY_TIMEOUT_MS) {
    delete state.pendingExpenseEntity[key];
    saveState();
    return false;
  }

  const text = (message.text || message.caption || "").trim();
  if (!text || text.startsWith("/")) return false;

  const step = pending.step || "entity";

  if (step === "entity") {
    const entity = parseExpenseEntityChoice(text);
    if (!entity) {
      await sendMessage(
        chatId,
        "Reply <b>1</b> or <b>2</b>:\n\n1. ALDEIA ALCATEIA LDA 517908794\n2. POVOADO GENTIL LDA 519423178"
      );
      return true;
    }
    pending.entity = entity;
    pending.step = "purpose";
    pending.ts = Date.now();
    saveState();
    await sendMessage(
      chatId,
      expensePurposePrompt(expensePreviewFromExtracted(pending.extracted), entity)
    );
    return true;
  }

  const purpose = parseExpensePurposeChoice(text, { allowNumbers: true });
  if (!purpose) {
    await sendMessage(chatId, expensePurposeFollowupText());
    return true;
  }

  try {
    await completePendingExpense(
      message,
      pending,
      pending.entity,
      purpose
    );
  } catch (err) {
    log("handlePendingExpenseEntity error:", err.message);
    await sendMessage(chatId, "Sorry, I could not save that receipt.");
  }
  return true;
}

function expensePageBelongsToUser(page, from) {
  if (!from?.id) return false;
  const uid = notion.pagePropText(page, "Telegram user ID").trim();
  if (uid && uid === String(from.id)) return true;
  const handle = senderHandle(from).replace(/^@/, "").toLowerCase();
  const display = senderDisplayName(from).toLowerCase();
  const who = (
    notion.pagePropText(page, "Submitted by") ||
    notion.pagePropText(page, "Telegram username") ||
    ""
  )
    .toLowerCase()
    .trim();
  if (!who) return false;
  if (handle && who.includes(handle)) return true;
  if (display && who.includes(display)) return true;
  return false;
}

function managementMemberRoleNames() {
  return (process.env.MANAGEMENT_MEMBER_ROLES || "Manager")
    .split(",")
    .map((s) => s.trim().toLowerCase())
    .filter(Boolean);
}

async function isManagementMember(from) {
  if (!from?.id) return false;
  const roles = managementMemberRoleNames();
  if (!roles.length) return false;
  const { page } = await findMemberByTelegram(senderHandle(from), from.id);
  if (!page) return false;
  const role = notion.pagePropText(page, "Role / focus").trim().toLowerCase();
  return roles.includes(role);
}

async function sendExpenseSummary(chatId, { from } = {}) {
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(EXPENSES_DB_NAME));
  } catch (err) {
    log("Expenses fetch failed:", err.message);
    await sendMessage(
      chatId,
      "Expenses board is not set up yet. Run <code>npm run notion:setup-expenses</code> first."
    );
    return;
  }

  const month = monthKeyFromISO(todayISO());
  let thisMonth = pages.filter(
    (p) => (notion.pagePropText(p, "Month") || "").startsWith(month)
  );

  const canViewAll = from ? await isManagementMember(from) : false;
  if (!canViewAll) {
    if (!from) {
      await sendMessage(
        chatId,
        "I can only show your own receipts — message me privately and I’ll pull yours up."
      );
      return;
    }
    thisMonth = thisMonth.filter((p) => expensePageBelongsToUser(p, from));
  }
  const byPerson = new Map();
  const byEntity = new Map();
  const byPurpose = new Map();
  for (const page of thisMonth) {
    const who =
      notion.pagePropText(page, "Submitted by") ||
      notion.pagePropText(page, "Telegram username") ||
      "Unknown";
    const amount = Number(notion.pagePropText(page, "Amount")) || 0;
    const cur = notion.pagePropText(page, "Currency") || "EUR";
    const entry = byPerson.get(who) || { amount: 0, count: 0, currency: cur };
    entry.amount += amount;
    entry.count += 1;
    byPerson.set(who, entry);

    const entity =
      notion.pagePropText(page, "Entity") ||
      notion.pagePropText(page, "Company") ||
      "Unassigned";
    const ent = byEntity.get(entity) || { amount: 0, count: 0 };
    ent.amount += amount;
    ent.count += 1;
    byEntity.set(entity, ent);

    const purpose = notion.pagePropText(page, "For") || "Unassigned";
    const pur = byPurpose.get(purpose) || { amount: 0, count: 0 };
    pur.amount += amount;
    pur.count += 1;
    byPurpose.set(purpose, pur);
  }

  const lines = [
    canViewAll
      ? `<b>Expenses — ${escapeHtml(month)}</b>`
      : `<b>Your receipts — ${escapeHtml(month)}</b>`,
  ];
  if (!byPerson.size) {
    lines.push(
      canViewAll ? "No receipts this month yet." : "You don’t have any receipts logged this month yet."
    );
  } else {
    const rows = [...byPerson.entries()].sort((a, b) =>
      a[0].localeCompare(b[0])
    );
    let total = 0;
    for (const [who, row] of rows) {
      total += row.amount;
      lines.push(
        `• <b>${escapeHtml(who)}</b> — ${escapeHtml(formatMoney(row.amount, row.currency))} (${row.count} receipt${row.count === 1 ? "" : "s"})`
      );
    }
    lines.push(`<b>Total</b> ${escapeHtml(formatMoney(total, "EUR"))}`);
  }

  if (canViewAll && byEntity.size) {
    lines.push("", "<b>By company</b>");
    for (const ent of EXPENSE_ENTITIES) {
      const row = byEntity.get(ent.notionSelect);
      if (!row) continue;
      lines.push(
        `• ${escapeHtml(ent.legalName)} — ${escapeHtml(formatMoney(row.amount, "EUR"))} (${row.count})`
      );
    }
    const other = byEntity.get("Unassigned");
    if (other?.count) {
      lines.push(
        `• <i>Unassigned</i> — ${escapeHtml(formatMoney(other.amount, "EUR"))} (${other.count})`
      );
    }
  }

  if (canViewAll && byPurpose.size) {
    lines.push("", "<b>By what it's for</b>");
    for (const purpose of EXPENSE_PURPOSES) {
      const row = byPurpose.get(purpose.name);
      if (!row) continue;
      lines.push(
        `• ${escapeHtml(purpose.name)} — ${escapeHtml(formatMoney(row.amount, "EUR"))} (${row.count})`
      );
    }
    const otherPurpose = byPurpose.get("Unassigned");
    if (otherPurpose?.count) {
      lines.push(
        `• <i>Unassigned</i> — ${escapeHtml(formatMoney(otherPurpose.amount, "EUR"))} (${otherPurpose.count})`
      );
    }
  }

  if (canViewAll) {
    const unpaid = thisMonth.filter(
      (p) => notion.pagePropText(p, "Status") === "To reimburse"
    );
    if (unpaid.length) {
      lines.push(`\n<i>${unpaid.length} still marked To reimburse</i>`);
    }
  }

  await sendMessage(chatId, lines.join("\n"));
}

async function handleReceiptSubmission({
  message,
  text,
  from,
  chatId,
  force = false,
  assumeReceipt = false,
  classifiedExpense = null,
}) {
  const media = getMessageMedia(message);
  const isPrivate = message.chat?.type === "private";
  const assume = assumeReceipt || (isPrivate && Boolean(media));
  if (!force && !assume && !media && !looksLikeReceiptIntent(text) && !classifiedExpense) {
    return false;
  }
  if (!force && !assume && !media && !String(text || "").trim() && !classifiedExpense) {
    return false;
  }

  await telegram("sendChatAction", { chat_id: chatId, action: "typing" }).catch(
    () => {}
  );

  let buf = null;
  let upload = null;
  let imageBase64 = null;
  let imageMime = null;
  let pdfBase64 = null;
  let source = media?.source || "Text";

  if (media?.fileId) {
    try {
      const downloaded = await downloadTelegramFile(media.fileId);
      buf = downloaded.buf;
      const filename = media.filename || downloaded.filename || "receipt.jpg";
      const mime =
        media.mime ||
        notion.guessContentType(filename, media.kind === "pdf" ? "application/pdf" : "image/jpeg");
      if (media.kind === "pdf") {
        pdfBase64 = buf.toString("base64");
      } else {
        imageBase64 = buf.toString("base64");
        imageMime = mime.startsWith("image/") ? mime : "image/jpeg";
      }
    } catch (err) {
      log("download receipt media error:", err.message);
      if (force || assume) {
        await sendMessage(
          chatId,
          "I couldn't download that file. Try sending the photo again, or paste the receipt text."
        );
        return true;
      }
    }
  }

  let extracted = null;
  if (openaiLib.isEnabled()) {
    extracted = await openaiLib.extractReceipt({
      text: text || classifiedExpense?.notes || "",
      imageBase64,
      imageMime,
      pdfBase64,
      filename: media?.filename,
      todayISO: todayISO(),
      submitter: senderDisplayName(from),
    });
  }

  if (!extracted && classifiedExpense) {
    extracted = {
      is_receipt: true,
      confidence: 1,
      title: classifiedExpense.title || "Receipt",
      merchant: classifiedExpense.merchant || "",
      amount: classifiedExpense.amount,
      currency: classifiedExpense.currency || "EUR",
      date: classifiedExpense.date || todayISO(),
      category: classifiedExpense.category || "Other",
      vat: null,
      notes: classifiedExpense.notes || "",
      line_items: [],
      receipt_text: text || "",
    };
  }

  if (!extracted && (force || assume) && (text || media)) {
    extracted = {
      is_receipt: true,
      confidence: 0,
      title: (text || "Receipt").slice(0, 80),
      merchant: "",
      amount: null,
      currency: "EUR",
      date: todayISO(),
      category: "Other",
      vat: null,
      notes: "",
      line_items: [],
      receipt_text: text || "",
    };
  }

  if (!extracted) return false;
  if (extracted.is_receipt === false) {
    if (assume || force) {
      await sendMessage(
        chatId,
        "That doesn't look like a receipt to me. Send a till receipt or factura, or use /task or /ask for something else."
      );
      return true;
    }
    return false;
  }
  if (
    !force &&
    !assume &&
    media &&
    !looksLikeReceiptIntent(text) &&
    !classifiedExpense
  ) {
    if ((extracted.confidence || 0) < 0.55) return false;
  }

  try {
    const entityFromText = parseExpenseEntityChoice(text);
    const purposeFromText = parseExplicitExpensePurpose(text);

    if (entityFromText && purposeFromText) {
      if (buf && media?.fileId) {
        try {
          const filename = media.filename || "receipt.jpg";
          const mime =
            media.mime ||
            notion.guessContentType(
              filename,
              media.kind === "pdf" ? "application/pdf" : "image/jpeg"
            );
          upload = await notion.uploadFile(buf, filename, mime);
        } catch (err) {
          log("Notion receipt upload failed:", err.message);
        }
      }
      await finalizeAndLogExpense({
        extracted,
        from,
        chatId,
        sourceText: text,
        source,
        upload,
        mediaKind: media?.kind,
        entity: entityFromText,
        purpose: purposeFromText,
      });
      return true;
    }

    await queueExpenseFollowup({
      extracted,
      from,
      chatId,
      sourceText: text,
      source,
      media,
      upload: null,
      entity: entityFromText || null,
      purpose: purposeFromText || null,
      step: entityFromText ? "purpose" : "entity",
    });
    return true;
  } catch (err) {
    log("createExpense error:", err.message);
    if (force || assume) {
      const missing = /not found/i.test(err.message)
        ? " Run <code>npm run notion:setup-expenses</code> first."
        : "";
      await sendMessage(
        chatId,
        `Sorry, I could not save that receipt.${missing}`
      );
      return true;
    }
    return false;
  }
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

async function findJobByTitle(title) {
  const q = String(title || "").trim();
  if (!q) return null;
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(JOBS_DB_NAME));
  } catch {
    return null;
  }
  const exact = pages.find((p) => notion.pageTitle(p).toLowerCase() === q.toLowerCase());
  if (exact) return exact;
  return pages.find((p) => titleMatches(q, notion.pageTitle(p))) || null;
}

async function findJobByAssignee(assignee) {
  const q = String(assignee || "").trim();
  if (!q) return null;
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(JOBS_DB_NAME));
  } catch {
    return null;
  }
  return (
    pages.find((p) => {
      if (notion.pagePropText(p, "Status") === "Archived") return false;
      const stored = notion.pagePropText(p, "Assignee");
      return stored && titleMatches(q, stored);
    }) || null
  );
}

async function findExistingRecurringDuty({ jobId, title }) {
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(RECURRING_DB_NAME));
  } catch {
    return null;
  }
  const needle = String(title || "").toLowerCase();
  return (
    pages.find((p) => {
      if (notion.pageTitle(p).toLowerCase() !== needle) return false;
      const rel = p.properties?.Job?.relation || [];
      if (!jobId) return !rel.length;
      return rel.some((r) => r.id === jobId);
    }) || null
  );
}

async function findOrCreateJobForDuty({ jobName, assignee, dutyTitle }) {
  let job = jobName ? await findJobByTitle(jobName) : null;
  if (!job && assignee) job = await findJobByAssignee(assignee);

  if (!job) {
    const name = jobName || dutyTitle || "Village duty";
    job = await notion.createPage({
      databaseName: JOBS_DB_NAME,
      title: String(name).slice(0, 200),
      properties: {
        Status: "Active",
        ...(assignee ? { Assignee: assignee } : {}),
      },
    });
    return job;
  }

  if (assignee) {
    const current = notion.pagePropText(job, "Assignee");
    if (!current || !titleMatches(assignee, current)) {
      await notion.updatePageProps(JOBS_DB_NAME, job.id, { Assignee: assignee });
    }
  }
  return job;
}

async function createRecurringDutyFromClassification({
  classified,
  text,
  from,
  chatId,
  nextDueDate,
}) {
  const duty = classified.recurring_duty;
  const frequency = duty.frequency || "Daily";
  const dueDate = nextDueDate || todayISO();

  const job = await findOrCreateJobForDuty({
    jobName: duty.job,
    assignee: duty.assignee,
    dutyTitle: duty.title,
  });
  const jobId = job.id;

  const existing = await findExistingRecurringDuty({ jobId, title: duty.title });
  if (existing) {
    await notion.updatePageProps(RECURRING_DB_NAME, existing.id, {
      Frequency: frequency,
      Status: "Active",
      "Next due date": dueDate,
    });
    if (assigneeNeedsJobLink(existing, jobId)) {
      await notion.updatePage(existing.id, {
        properties: { Job: { relation: [{ id: jobId }] } },
      });
    }
    markPageSeen(existing.id, { stage: "", status: "Active", doer: "", done: false });
    return {
      itemId: existing.id,
      url: existing.url,
      databaseName: RECURRING_DB_NAME,
      boardName: RECURRING_DB_NAME,
      updated: true,
      jobName: notion.pageTitle(job),
      frequency,
      assignee: duty.assignee || notion.pagePropText(job, "Assignee") || null,
      nextDue: dueDate,
    };
  }

  const created = await createDbPage({
    databaseName: RECURRING_DB_NAME,
    itemName: duty.title,
    properties: {
      Frequency: frequency,
      Status: "Active",
      "Next due date": dueDate,
    },
    from,
    chatId,
    sourceText: text,
  });

  await notion.updatePage(created.itemId, {
    properties: { Job: { relation: [{ id: jobId }] } },
  });

  return {
    ...created,
    updated: false,
    jobName: notion.pageTitle(job),
    frequency,
    assignee: duty.assignee || notion.pagePropText(job, "Assignee") || null,
    nextDue: dueDate,
  };
}

function assigneeNeedsJobLink(dutyPage, jobId) {
  const rel = dutyPage.properties?.Job?.relation || [];
  return !rel.some((r) => r.id === jobId);
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

async function fetchCommunityCookOffersForDay(dateISO) {
  const offers = [];
  try {
    const { pages } = await notion.queryDatabase(INTERNAL_EVENTS_DB_NAME);
    for (const page of pages) {
      if (notion.isDoneStage(notion.pagePropText(page, "Stage"))) continue;
      const notes = notion.pagePropText(page, "Notes");
      const parsed = communityCookOffer.parseOfferFromNotes(notes);
      if (!parsed) continue;
      const pageDate = notion.pageDateISO(page, "Date");
      const matchDate = parsed.dateISO || pageDate;
      if (matchDate !== dateISO) continue;
      const recurrenceText = recurrenceTextFromPage(page);
      if (recurrenceText && parseRecurrence(recurrenceText)) continue;
      const offer = {
        ...parsed,
        dish: parsed.dish || notion.pageTitle(page),
      };
      if (!communityCookOffer.isCompleteCookOffer(offer)) continue;
      offers.push(offer);
    }
  } catch (err) {
    log("Community cook offers fetch failed:", err.message);
  }
  return offers;
}

async function createCommunityCookOffer(offer, { from, chatId, sourceText }) {
  const key = ambientDedupeKey(
    chatId,
    "community_cook",
    `${offer.dish}:${offer.dateISO}:${offer.cook}`
  );
  if (wasRecentlyCaptured(key)) return { duplicate: true };

  const notes = communityCookOffer.buildNotesBlock({
    ...offer,
    sourceText,
  });
  const time = offer.time || communityCookOffer.DEFAULT_TIME;
  const dateStart = `${offer.dateISO}T${time}:00`;

  const props = {
    Stage: "Upcoming",
    Status: "Confirmed",
    "Event type": "Shared meal",
    Date: dateStart,
    "Announce on Telegram?": true,
    Source: "Telegram",
    Notes: notes,
    "People in charge": offer.cook || senderDisplayName(from),
  };

  const page = await notion.createPage({
    databaseName: INTERNAL_EVENTS_DB_NAME,
    title: String(offer.dish || "Community meal").slice(0, 200),
    properties: props,
  });
  markCaptured(key);
  recordChatActivity({
    kind: "event_logged",
    from,
    chatId,
    title: offer.dish,
    eventDate: offer.dateISO,
    text: sourceText,
  });
  return { url: page.url, pageId: page.id };
}

async function finishCommunityCookOffer({ offer, from, chatId }) {
  try {
    const result = await createCommunityCookOffer(offer, {
      from,
      chatId,
      sourceText: offer.sourceText,
    });
    if (result.duplicate) {
      await sendMessage(chatId, "I already logged that meal offer recently.");
      return;
    }
    await sendMessage(chatId, communityCookOffer.confirmMessage(offer));
  } catch (err) {
    log("createCommunityCookOffer error:", err.message);
    await sendMessage(chatId, "Sorry, I couldn't save that cooking plan.");
  }
}

async function beginCommunityCookOfferFlow({ offer, from, chatId, text }) {
  const key = `${chatId}:${from.id}`;
  const partial = { ...offer, sourceText: text, cook: offer.cook || senderDisplayName(from) };

  if (!partial.diet) {
    state.pendingClarify[key] = {
      kind: "community_cook",
      step: "diet",
      partial,
      ts: Date.now(),
    };
    saveState();
    await sendMessage(chatId, communityCookOffer.dietPromptMessage());
    return;
  }
  if (partial.priceEur == null) {
    state.pendingClarify[key] = {
      kind: "community_cook",
      step: "price",
      partial,
      ts: Date.now(),
    };
    saveState();
    await sendMessage(chatId, communityCookOffer.pricePromptMessage());
    return;
  }
  await finishCommunityCookOffer({ offer: partial, from, chatId });
}

function foldAccents(text) {
  return String(text || "")
    .normalize("NFD")
    .replace(/\p{M}/gu, "")
    .toLowerCase();
}

function looksLikeMarketQuestion(text) {
  const t = foldAccents(text);
  return /\b(market|mercado|feira|farmers|flea)\b/.test(t) || regionalMarkets.looksLikeMarketLocationQuestion(text);
}

function isMarketNotionPage(page) {
  const type = notion.pagePropText(page, "Event type");
  const title = notion.pageTitle(page);
  return (
    /^market\b/i.test(type) ||
    /^market\s*[—–-]/i.test(title) ||
    /^(flea|farmers?)\s+market/i.test(title)
  );
}

function marketKindFilterFromQuestion(text) {
  const t = foldAccents(text);
  const flea = /\b(flea|car\s+boot)\b/.test(t);
  const farmers = /\b(farmers?|gypsy)\b/.test(t);
  if (flea && !farmers) return "flea";
  if (farmers && !flea) return "farmers";
  return null;
}

function extractPlaceFromMarketTitle(title) {
  const m = String(title || "").match(/^market\s*[—–-]\s*(.+)$/i);
  return m ? m[1].trim() : String(title || "").replace(/^market\s*/i, "").trim();
}

function filterMarketsByPlaceInQuestion(text, catalog) {
  return regionalMarkets.findMarketsMatchingText(text, catalog);
}

function matchMarketPlaceInQuestion(text, catalog) {
  const matches = filterMarketsByPlaceInQuestion(text, catalog);
  if (matches.length === 1) return matches[0].place;
  return null;
}

function looksLikeBotHealthPing(text) {
  const t = foldAccents(text);
  return /\b(are you (alive|there|ok|working|awake)|you alive|bot broken|not responsive|vava doesn)\b/.test(
    t
  );
}

/** Jokes / talk about the bot — not a request for markets, events, or rota. */
function looksLikeMetaOrSocialBotMessage(text) {
  const raw = String(text || "").trim();
  if (!raw) return false;
  if (looksLikeBotHealthPing(text)) return true;
  const t = foldAccents(raw);
  if (
    /\b(programmed? you|program you|ignore me|ignoring me|did\s+\w+\s+program|why aren.t you|acting weird|talking to me|not responsive)\b/.test(
      t
    )
  ) {
    return true;
  }
  if (/^(haha|lol|hehe|lmao)\b/i.test(raw) && /\b(bot|vava|you)\b/.test(t)) return true;
  return false;
}

/** Only pull schedule/market data when this message itself asks for it (ignore chat context). */
function userWantsScheduleData(text) {
  if (!text || looksLikeMetaOrSocialBotMessage(text)) return false;
  return looksLikeScheduleQuestion(text);
}

async function shouldPreferMedicalFacilityRating(text) {
  if (!medicalFacilities.looksLikeMedicalFacilityRatingMessage(text)) return false;
  if (!marketRatings.looksLikeMarketRatingMessage(text)) return true;
  const catalog = await medicalFacilities.loadFacilityCatalog();
  const hints = medicalFacilities.parseMedicalRatingsFromText(text);
  if (!hints.length) return true;
  return hints.every((row) => medicalFacilities.ratingHintLooksMedical(row.hint, catalog));
}

async function handleMedicalFacilityRatings({ text, from, chatId }) {
  try {
    const reviewer = senderDisplayName(from);
    const results = await medicalFacilities.recordMedicalFacilityRatingsFromMessage({
      text,
      reviewer,
    });
    if (!results.length) {
      await sendMessage(
        chatId,
        "To rate a clinic or hospital (1–10), send e.g. <code>Centro de Saúde de Ourique: 9</code> or <code>Hospital José Joaquim Fernandes: 8</code>."
      );
      return true;
    }
    const lines = results.map((r) => {
      let line = `• ${escapeHtml(r.name)}: ${r.score}/10 saved`;
      if (r.count) line += ` — avg ${r.avg}/10 (${r.count} review${r.count === 1 ? "" : "s"})`;
      if (r.unknown) line += " (name not in the directory — rating still logged)";
      return line;
    });
    await sendMessage(chatId, `Thanks, ${escapeHtml(reviewer)}!\n${lines.join("\n")}`);
    return true;
  } catch (err) {
    log("handleMedicalFacilityRatings error:", err.message);
    if (/database|Medical facility|Could not find/i.test(err.message)) {
      await sendMessage(
        chatId,
        "Medical directory isn't wired in Notion yet. Run <code>npm run notion:setup-medical</code>, then try again."
      );
      return true;
    }
    await sendMessage(chatId, "Sorry, I couldn't save that facility review right now.");
    return true;
  }
}

async function routeFacilityOrMarketRating({ text, from, chatId }) {
  const med = medicalFacilities.looksLikeMedicalFacilityRatingMessage(text);
  const mkt = marketRatings.looksLikeMarketRatingMessage(text);
  if (med && !mkt) return handleMedicalFacilityRatings({ text, from, chatId });
  if (mkt && !med) return handleMarketRatings({ text, from, chatId });
  if (med && mkt) {
    const preferMedical = await shouldPreferMedicalFacilityRating(text);
    if (preferMedical) return handleMedicalFacilityRatings({ text, from, chatId });
    return handleMarketRatings({ text, from, chatId });
  }
  return false;
}

async function handleMarketRatings({ text, from, chatId }) {
  try {
    const catalog = await loadMarketCatalog();
    const reviewer = senderDisplayName(from);
    const results = await marketRatings.recordMarketRatingsFromMessage({
      text,
      reviewer,
      catalog,
    });
    if (!results.length) {
      await sendMessage(
        chatId,
        "To rate a market (1–10), send e.g. <code>Barão de S. João: 3</code> or <code>FATACIL: 9</code>. I'll keep the community average in Notion."
      );
      return true;
    }
    const lines = results.map((r) => {
      let line = `• ${escapeHtml(r.place)}: ${r.score}/10 saved`;
      if (r.count) line += ` — avg ${r.avg}/10 (${r.count} rating${r.count === 1 ? "" : "s"})`;
      if (r.unknownPlace) line += " (name not in the market list — rating still logged)";
      return line;
    });
    await sendMessage(chatId, `Thanks, ${escapeHtml(reviewer)}!\n${lines.join("\n")}`);
    return true;
  } catch (err) {
    log("handleMarketRatings error:", err.message);
    if (/database|Market ratings|Could not find/i.test(err.message)) {
      await sendMessage(
        chatId,
        "Market ratings aren't wired in Notion yet. Run <code>npm run notion:setup-market-ratings</code> on the server, then try again."
      );
      return true;
    }
    await sendMessage(chatId, "Sorry, I couldn't save that market rating right now.");
    return true;
  }
}

function resolveMarketQueryDate(text) {
  const t = foldAccents(text);
  if (/\b(today|hoje)\b/.test(t)) return todayISO();
  if (/\b(tomorrow|amanha)\b/.test(t)) return addDaysISO(todayISO(), 1);
  return todayISO();
}

async function loadMarketCatalog() {
  const notionEntries = [];
  try {
    const { pages: external } = await notion.queryDatabase(EXTERNAL_EVENTS_DB_NAME);
    for (const page of external) {
      if (notion.isDoneStage(notion.pagePropText(page, "Stage"))) continue;
      if (!isMarketNotionPage(page)) continue;
      notionEntries.push(regionalMarkets.notionPageToMarketEntry(page, notion));
    }
  } catch (err) {
    log(`Market catalog fetch failed (${EXTERNAL_EVENTS_DB_NAME}):`, err.message);
  }

  let catalog = regionalMarkets.mergeMarketCatalogs(notionEntries);
  try {
    const aggregates = await marketRatings.loadRatingAggregates();
    catalog = marketRatings.enrichCatalogWithRatings(catalog, aggregates);
  } catch (err) {
    log("Market ratings load skipped:", err.message);
  }
  return catalog;
}

async function fetchMarketsForDate(dateISO) {
  const catalog = await loadMarketCatalog();
  return catalog.filter((m) => eventMatchesDate(m.date, m.recurrenceText, dateISO));
}

async function answerMarketQuestion(text) {
  if (marketRatings.looksLikeMarketRatingMessage(text)) {
    return { answered: false, answer: "" };
  }

  const catalog = await loadMarketCatalog();
  const targetDate = resolveMarketQueryDate(text);
  const placeMatches = filterMarketsByPlaceInQuestion(text, catalog);
  const place = placeMatches.length === 1 ? placeMatches[0].place : null;
  const kindFilter = marketKindFilterFromQuestion(text);
  const locationQuestion = regionalMarkets.looksLikeMarketLocationQuestion(text);

  if (!catalog.length) {
    return { answered: false, answer: "" };
  }

  if (marketRatings.looksLikeMarketRatingQuery(text) && placeMatches.length) {
    const aggregates = await marketRatings.loadRatingAggregates();
    const lines = [];
    const seen = new Set();
    for (const m of placeMatches) {
      const key = regionalMarkets.normalizePlaceForDedupe(m.place);
      if (seen.has(key)) continue;
      seen.add(key);
      const agg = aggregates.get(key);
      if (agg?.count) {
        lines.push(`• ${m.place}: ${marketRatings.formatRatingSummary(agg)}`);
      } else {
        lines.push(`• ${m.place}: no ratings yet`);
      }
    }
    if (lines.length) {
      return { answered: true, answer: `Market ratings:\n${lines.join("\n")}` };
    }
  }

  let pool = catalog;
  if (placeMatches.length) {
    pool = placeMatches;
  }
  if (kindFilter) {
    pool = pool.filter((m) => m.kind === kindFilter);
  }

  if (placeMatches.length && !pool.length) {
    return {
      answered: true,
      answer: `I don't have a market listed for that place.`,
    };
  }

  if (locationQuestion && placeMatches.length && pool.length) {
    const lines = pool.map((m) => {
      const rule = parseRecurrence(m.recurrenceText || "");
      const when = rule ? describeRecurrence(rule) : "";
      const loc = m.locale ? m.locale : "(venue not listed)";
      return `• ${m.place}${when ? ` (${when})` : ""}: ${loc}`;
    });
    const label =
      place ||
      (placeMatches.length === 1 ? placeMatches[0].place : "the area you asked about");
    return {
      answered: true,
      answer: `Market location${lines.length > 1 ? "s" : ""} for ${label}:\n${lines.join("\n")}`,
    };
  }

  const onDate = pool.filter((m) => eventMatchesDate(m.date, m.recurrenceText, targetDate));

  if (place && pool.length === 1) {
    const m = pool[0];
    const rule = parseRecurrence(m.recurrenceText || "");
    const label = rule ? describeRecurrence(rule) : "";
    const dayLabel = targetDate === todayISO() ? "today" : targetDate;
    if (onDate.length) {
      const loc = m.locale ? ` Location: ${m.locale}.` : "";
      const rating =
        m.ratingAvg != null && m.ratingCount
          ? ` Rating: ${marketRatings.formatRatingSummary(m)}.`
          : "";
      return {
        answered: true,
        answer: `Yes — ${m.place} market is on ${dayLabel} (${targetDate}).${label ? ` (${label})` : ""}${loc}${rating}`,
      };
    }
    const next = rule ? nextOccurrence(rule, targetDate, RECAP_TZ) : null;
    let msg = `No — ${m.place} market is not on ${dayLabel}.`;
    if (label) msg += ` It's ${label}.`;
    if (m.locale) msg += ` Location: ${m.locale}.`;
    if (next && next !== targetDate) msg += ` Next: ${next}.`;
    return { answered: true, answer: msg };
  }

  if (onDate.length) {
    const dayLabel = targetDate === todayISO() ? "today" : targetDate;
    return {
      answered: true,
      answer: regionalMarkets.formatMarketsListAnswer(onDate, { dayLabel, dateISO: targetDate }),
    };
  }

  const dayLabel = targetDate === todayISO() ? "today" : targetDate;
  if (place) {
    return {
      answered: true,
      answer: `No market at ${place} on ${dayLabel} (${targetDate}).`,
    };
  }
  return {
    answered: true,
    answer: `No regional markets listed for ${dayLabel} (${targetDate}).`,
  };
}

function looksLikeScheduleQuestion(text) {
  const t = String(text || "").toLowerCase();
  if (!t) return false;
  if (looksLikeMarketQuestion(text)) return true;
  if (/\b(log|add|create|plan|organise|organize|cancel|remind)\b/.test(t) && !/[?]/.test(t)) {
    return false;
  }
  if (
    /\b(what(?:'s| is| are)? (?:happening|on|going on)|any events?|what events?|which events?|events? (?:this|next|on|do we)|this weekend|who(?:'s| is) on|on the rota|the schedule)\b/.test(
      t
    )
  ) {
    return true;
  }
  return (
    /\bevents?\b/.test(t) &&
    /\b(what|any|which|are there|is there|list|happening|weekend|coming up)\b/.test(t)
  );
}

function guessScheduleRange(text) {
  const t = String(text || "").toLowerCase();
  if (/\b(today|hoje|tonight)\b/.test(t)) return "today";
  if (/\b(next week|pr[oó]xima semana|semana que vem)\b/.test(t)) return "next_week";
  if (/\b(this week|esta semana|this weekend|este fim de semana)\b/.test(t)) return "this_week";
  return "two_weeks";
}

function looksLikeMyDayQuestion(text) {
  const t = String(text || "").toLowerCase();
  return (
    /what should i do/.test(t) ||
    /what do i (need to |have to )?do/.test(t) ||
    /what('?s| is) (on )?my (list|plate|agenda)/.test(t) ||
    /what('?s| is) on for me/.test(t) ||
    /what have i got( today)?/.test(t) ||
    /do i have (anything|any(thing)? (to do|tasks?|jobs?))/.test(t) ||
    /anything (for|on) me( today)?/.test(t) ||
    /my (tasks?|duties|chores|jobs)( for)?( today)?/.test(t) ||
    /\bmy day\b/.test(t) ||
    /o que (eu )?tenho (de |que )?fazer/.test(t) ||
    /tenho alguma coisa( hoje)?/.test(t) ||
    /minhas tarefas/.test(t)
  );
}

function looksLikeCompleteTask(text) {
  const t = String(text || "").toLowerCase();
  if (/\b(i'?m done|done for (the )?day|done for today)\b/.test(t) && !/\b(task|job|wifi|tap)\b/.test(t)) {
    return false;
  }
  return (
    /\bmark\b.*\b(done|complete|completed|finished)\b/.test(t) ||
    /\b(please|can you|could you)\b.*\b(mark|close)\b/.test(t) ||
    /\b(?:i'?ve|i have)\s+done\b/.test(t) ||
    /\b(?:it|that)('?s| is)\s+(?:done|sorted|fixed)\b/.test(t) ||
    /\b(?:that'?s|it'?s)\s+sorted\b/.test(t) ||
    /\bworking again\b/.test(t) ||
    /\b(podes? marcar|j[aá] est[aá] (feito|pronta|resolvido))\b/.test(t) ||
    (/\b(?:done|finished|completed|fixed|sorted)\b/.test(t) &&
      /\b(wifi|wi-?fi|task|job|tap|toilet|shower|leak|light|valve)\b/.test(t))
  );
}

function inferTaskTypePriority(text) {
  const t = String(text || "").toLowerCase();
  let type = null;
  let priority = null;
  if (
    /\b(villager|village work|anyone can|we can (?:all )?do|diy|pitch in|simples|qualquer um)\b/.test(
      t
    )
  ) {
    type = "Villager";
  }
  if (
    /\b(management|professional|plumber|electrician|contractor|arranging|needs? a pro|hire|especialista|canalizador|eletricista)\b/.test(
      t
    )
  ) {
    type = "Management";
  }
  if (/\b(low|no rush|whenever|not urgent|quando der|sem pressa)\b/.test(t)) priority = "Low";
  if (/\b(average|medium|normal)\b/.test(t)) priority = "Average";
  if (/\b(high|urgent|asap|emergency|safety|perigo|urgente)\b/.test(t)) priority = "High";
  return { type, priority };
}

function withTaskDefaults(task, text) {
  const inferred = inferTaskTypePriority(text);
  return {
    ...(task || {}),
    title: task?.title || String(text || "").slice(0, 80) || "(photo)",
    type: task?.type || inferred.type || "Villager",
    priority: task?.priority || inferred.priority || "Average",
  };
}

function actionMinConfidence(interactive) {
  return interactive ? INTERACTIVE_MIN_CONFIDENCE : AMBIENT_MIN_CONFIDENCE;
}

function looksLikeQuestion(text) {
  const t = String(text || "").trim();
  if (!t) return false;
  if (/[?¿]/.test(t)) return true;
  return /^(what|where|who|when|why|how|which|is |are |can |could |do |does |did |should |would |will |any |anyone |qual|onde|quem|quando|como|porque|porqu[eê]|h[aá] |tem )\b/i.test(
    t
  );
}

function recentChatLines(chatId, excludeText) {
  return memoryFor(chatId)
    .filter((m) => m.text && m.text !== excludeText)
    .slice(-10)
    .map((m) => {
      const who = m.isBot ? "VAVA" : m.name || senderDisplayName(m.from);
      return `${who}: ${m.text}`;
    });
}

async function replyConversationally({
  text,
  chatId,
  classified = null,
  situation = "",
  facts = "",
} = {}) {
  if (!text || !openaiLib.isEnabled()) return false;
  await telegram("sendChatAction", { chat_id: chatId, action: "typing" }).catch(() => {});
  const reply = await openaiLib.converseAsVava({
    text,
    recent: recentChatLines(chatId, text),
    classified,
    situation,
    facts,
    todayISO: todayISO(),
  });
  if (!reply) return false;
  await sendMessage(chatId, escapeHtml(reply));
  return true;
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
  const buf = memoryFor(chatId);
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

function mergeCancelEventIntent(classified, text) {
  if (!looksLikeEventCancellationRequest(text)) return classified;
  const query = extractEventCancelQuery(text);
  if (!query) return classified;
  const cancelAll = inferCancelAllFromText(text);
  const intent = {
    action: "cancel_event",
    actionable: true,
    confidence: 0.9,
    is_question: false,
    is_cancel_event: true,
    target: { title: query.slice(0, 80), cancel_all: cancelAll },
  };
  if (!classified || classified.action === "chat" || !classified.is_cancel_event) {
    log(`Cancel event intent: "${query}" cancel_all=${cancelAll}`);
    return { ...(classified || {}), ...intent };
  }
  if (classified.is_cancel_event && cancelAll && !classified.target?.cancel_all) {
    return {
      ...classified,
      target: { ...(classified.target || {}), title: classified.target?.title || query, cancel_all: true },
      confidence: Math.max(classified.confidence || 0, 0.9),
    };
  }
  return classified;
}

function mergeLocationIntent(classified, text) {
  if (classified?.is_location_question) {
    if (!classified.location?.item && !classified.location?.list_all) {
      const item = locationsLib.extractLocationQuestionItem(text);
      if (item) {
        classified = {
          ...classified,
          location: { ...(classified.location || {}), item },
          locations: [{ ...(classified.location || {}), item }],
        };
      }
    }
    return classified;
  }
  if (classified?.is_location_update) {
    if (!classified.location?.item || !classified.location?.location) {
      const extracted = locationsLib.extractLocationUpdate(text);
      if (extracted) {
        return {
          ...classified,
          location: { ...extracted, ...classified.location, item: classified.location?.item || extracted.item, location: classified.location?.location || extracted.location },
          locations:
            Array.isArray(classified.locations) && classified.locations.length
              ? classified.locations
              : [extracted],
        };
      }
    }
    return classified;
  }
  if (locationsLib.looksLikeLocationQuestion(text)) {
    const item =
      classified?.location?.item || locationsLib.extractLocationQuestionItem(text);
    const listAll =
      classified?.location?.list_all === true ||
      /\b(everything|all (?:the )?things|where things are)\b/i.test(text);
    log(`Location intent: ask where → "${item || (listAll ? "all" : "?")}"`);
    return {
      ...(classified || {}),
      action: "question_location",
      actionable: true,
      is_question: true,
      is_location_question: true,
      question_kind: "location",
      confidence: Math.max(classified?.confidence || 0, 0.86),
      location: {
        item: item || "",
        location: "",
        update_kind: "current",
        item_kind: "Other",
        list_all: listAll,
      },
      locations: [
        {
          item: item || "",
          location: "",
          update_kind: "current",
          item_kind: "Other",
          list_all: listAll,
        },
      ],
    };
  }
  if (
    locationsLib.looksLikeLocationUpdate(text) &&
    !classified?.is_complete &&
    classified?.action !== "complete_task"
  ) {
    const extracted = locationsLib.extractLocationUpdate(text);
    const loc = classified?.location || extracted;
    if (!loc?.item) return classified;
    const row = {
      item: loc.item,
      location: loc.location || extracted?.location || "",
      usual_place: loc.usual_place || null,
      update_kind: loc.update_kind || extracted?.update_kind || "current",
      item_kind: loc.item_kind || locationsLib.guessItemKind(loc.item),
      area: loc.area || null,
      aliases: loc.aliases || null,
      notes: loc.notes || null,
    };
    log(`Location intent: update → "${row.item}" @ "${row.location}"`);
    return {
      ...(classified || {}),
      action: "update_location",
      actionable: true,
      is_question: false,
      is_task: false,
      is_location_update: true,
      confidence: Math.max(classified?.confidence || 0, 0.86),
      location: row,
      locations:
        Array.isArray(classified?.locations) && classified.locations.length
          ? classified.locations
          : [row],
    };
  }
  return classified;
}

function mergeFaqIntent(classified, text) {
  if (classified?.is_faq_create || classified?.action === "create_faq") {
    if (!classified.faq?.question || !classified.faq?.answer) {
      const extracted = faqsLib.extractFaqUpsert(text);
      if (extracted?.question && extracted?.answer) {
        return {
          ...classified,
          action: "create_faq",
          actionable: true,
          is_faq_create: true,
          is_question: false,
          confidence: Math.max(classified?.confidence || 0, 0.88),
          faq: { ...(classified.faq || {}), ...extracted },
        };
      }
    }
    return classified;
  }
  if (classified?.is_faq_question || classified?.action === "question_faq") {
    return classified;
  }
  if (faqsLib.looksLikeFaqUpsert(text)) {
    const extracted = faqsLib.extractFaqUpsert(text);
    if (extracted?.question && extracted?.answer) {
      log(`FAQ intent: add → "${extracted.question.slice(0, 60)}"`);
      return {
        ...(classified || {}),
        action: "create_faq",
        actionable: true,
        is_question: false,
        is_faq_create: true,
        confidence: Math.max(classified?.confidence || 0, 0.88),
        faq: extracted,
      };
    }
  }
  if (
    faqsLib.looksLikeFaqQuestion(text) &&
    !classified?.is_faq_create &&
    !classified?.is_location_update &&
    !classified?.is_location_question
  ) {
    log(`FAQ intent: ask → "${String(text).slice(0, 60)}"`);
    return {
      ...(classified || {}),
      action: "question_faq",
      actionable: true,
      is_question: true,
      is_faq_question: true,
      question_kind: "faq",
      confidence: Math.max(classified?.confidence || 0, 0.9),
      faq: {
        ...(classified?.faq || {}),
        question: String(text || "").trim().replace(/\?+$/, ""),
      },
    };
  }
  return classified;
}

async function resolvePersonIdentity(from) {
  const handle = senderHandle(from);
  const { page: member } = await findMemberByTelegram(handle, from?.id);
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
    if (isMarketNotionPage(page)) continue;
    if (notion.pagePropText(page, "Notes").includes(communityCookOffer.MARKER)) continue;
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
    if (isMarketNotionPage(page)) continue;
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
async function fetchWebsiteCampsiteActivityForDay(dateISO) {
  let website = { arrivals: [], departures: [], staying: [] };
  try {
    const { pages } = await notion.queryDatabase(CAMPSITE_BOOKINGS_DB_NAME);
    const linesFromDetail = (text) =>
      String(text || "")
        .split("\n")
        .map((s) => s.trim())
        .filter(Boolean)
        .map((raw) => campsiteOps.stripPlotFromActivityLine(raw))
        .filter(Boolean)
        .map((line) => ({ line, name: line.split(" · ")[0] || line }));

    for (const page of pages) {
      if (!pageMatchesDay(page, dateISO)) continue;
      website = {
        arrivals: linesFromDetail(notion.pagePropText(page, "Checking in")),
        departures: linesFromDetail(notion.pagePropText(page, "Checking out")),
        staying: linesFromDetail(notion.pagePropText(page, "Staying")),
      };
      break;
    }
  } catch (err) {
    log("Campsite bookings database unavailable:", err.message);
  }
  return website;
}

async function fetchCampsiteActivityForDay(dateISO) {
  const website = await fetchWebsiteCampsiteActivityForDay(dateISO);
  const extra = await guestStaysLib.activityForDay(dateISO).catch(() => ({
    arrivals: [],
    departures: [],
    staying: [],
  }));
  return guestStaysLib.mergeActivity(website, extra);
}

function appendCampsiteActivityHtmlLines(lines, activity) {
  if (activity.arrivals?.length) {
    lines.push(`<i>Checking in today (${activity.arrivals.length})</i>`);
    for (const b of activity.arrivals) lines.push(`• ${escapeHtml(b.line)}`);
  }
  if (activity.departures?.length) {
    lines.push(`<i>Checking out today (${activity.departures.length})</i>`);
    for (const b of activity.departures) lines.push(`• ${escapeHtml(b.line)}`);
  }
  if (activity.staying?.length) {
    lines.push(
      `<i>Pitches booked / occupied tonight (${activity.staying.length})</i>`
    );
    for (const b of activity.staying) lines.push(`• ${escapeHtml(b.line)}`);
  }
}

async function buildManagementCampsiteBriefing(dateISO) {
  const website = await fetchWebsiteCampsiteActivityForDay(dateISO);
  const guest = await guestStaysLib.activityForDay(dateISO).catch(() => ({
    arrivals: [],
    departures: [],
    staying: [],
  }));

  const hasWebsite =
    website.arrivals.length || website.departures.length || website.staying.length;
  const hasGuest =
    guest.arrivals.length || guest.departures.length || guest.staying.length;
  if (!hasWebsite && !hasGuest) return null;

  const dayName = new Intl.DateTimeFormat("en-GB", {
    timeZone: RECAP_TZ,
    weekday: "long",
    day: "numeric",
    month: "long",
  }).format(new Date(dateISO + "T12:00:00"));

  const lines = [
    `<b>Campsite arrivals &amp; occupancy</b> — ${escapeHtml(dayName)}`,
    "",
  ];

  if (hasWebsite) {
    lines.push("<b>Booked through the website</b>");
    lines.push(
      "<i>These check-ins, check-outs, and occupied pitches are from online campsite bookings on the website — not email or phone stays.</i>"
    );
    appendCampsiteActivityHtmlLines(lines, website);
    if (hasGuest) lines.push("");
  }

  if (hasGuest) {
    lines.push("<b>Other guest stays</b>");
    lines.push(
      "<i>Logged manually (email, phone, walk-in, etc.) — not from the website checkout.</i>"
    );
    appendCampsiteActivityHtmlLines(lines, guest);
  }

  return lines.join("\n").trim();
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

async function fetchChildcareForDay(dateISO) {
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(CHILDCARE_DB_NAME));
  } catch (err) {
    log("Childcare database unavailable:", err.message);
    return null;
  }

  for (const page of pages) {
    if (!pageMatchesDay(page, dateISO)) continue;
    return {
      headcount: Number(notion.pagePropText(page, "Headcount") || 0) || 0,
      capacity: Number(notion.pagePropText(page, "Capacity") || 0) || 0,
      roster: notion.pagePropText(page, "Roster"),
      absent: Number(notion.pagePropText(page, "Absent") || 0) || 0,
      open: notion.pagePropText(page, "Open?").toLowerCase() === "true",
      name: notion.pageTitle(page),
    };
  }
  return { headcount: 0, capacity: 0, roster: "", absent: 0, open: false, name: "" };
}

function formatChildcareLine(childcare) {
  if (!childcare) return "Childcare: database not available";
  const cap = childcare.capacity ? ` / ${childcare.capacity}` : "";
  const absent = childcare.absent ? ` (${childcare.absent} absent)` : "";
  return `Childcare: ${childcare.headcount}${cap} children${absent}`;
}

function formatChildcareRoster(childcare) {
  if (!childcare) return "Childcare database is not available.";
  if (!childcare.headcount && !childcare.absent) return "No children booked into childcare today.";
  const lines = [formatChildcareLine(childcare)];
  if (childcare.roster) {
    lines.push("");
    for (const line of childcare.roster.split("\n").filter(Boolean)) {
      lines.push(`• ${line}`);
    }
  }
  return lines.join("\n");
}

const KIDS_PROGRAMME_HELP =
  "<b>Kids programme slot</b>\n" +
  "• <code>/kids Amber · Early Readers · Friday</code>\n" +
  "• <code>/kids Moon · Arete · 2026-10-10</code>\n" +
  "• Or say: “put Amber on Early Readers Friday”\n" +
  `Programmes: ${kidsProgrammeLib.PROGRAMME_ORDER.filter((p) => p !== "Day note").join(", ")}`;

async function assignKidsProgrammeShift(shift, { from, chatId } = {}) {
  const result = await kidsProgrammeLib.upsertKidsProgrammeShift(shift, {
    dbName: KIDS_PROGRAMME_DB_NAME,
    todayISO: todayISO(),
    tz: RECAP_TZ,
  });
  recordChatActivity({
    kind: "announcement",
    from,
    chatId,
    title: `${result.programme} ${result.dateISO}`,
    text: result.lead || result.support || result.status,
  });
  return result;
}

function formatKidsAssignReply(result) {
  if (result.status === kidsProgrammeLib.STATUS_CANCELLED) {
    return `📅 Cleared <b>${escapeHtml(result.programme)}</b> on ${escapeHtml(result.dateISO)}.`;
  }
  const people = [result.lead, result.support].filter(Boolean).join(" + ");
  const tbc =
    result.status === kidsProgrammeLib.STATUS_TBC ? " (TBC)" : "";
  let msg =
    `📅 ${result.updated ? "Updated" : "Added"} <b>${escapeHtml(result.programme)}</b> ` +
    `on ${escapeHtml(result.dateISO)}`;
  if (result.time) msg += ` · ${escapeHtml(result.time)}`;
  msg += ` — ${escapeHtml(people || "TBC")}${tbc}`;
  if (result.url) msg += ` — <a href="${result.url}">Notion</a>`;
  return msg;
}

async function handleKidsSlashCommand(args, { from, chatId }) {
  const raw = String(args || "").trim();
  if (!raw || /^help$/i.test(raw)) {
    await sendMessage(chatId, KIDS_PROGRAMME_HELP);
    return true;
  }

  let shift = null;
  const parts = raw.split(/·|\|/).map((s) => s.trim()).filter(Boolean);
  if (parts.length >= 2) {
    shift = {
      lead: parts[0],
      programme: parts[1],
      date: parts[2] || "today",
      assign_as: "lead",
    };
  } else {
    const onMatch = raw.match(
      /^(.+?)\s+on\s+(.+?)(?:\s+(today|tomorrow|\d{4}-\d{2}-\d{2}|monday|tuesday|wednesday|thursday|friday|saturday|sunday|segunda|terça|quarta|quinta|sexta|sábado|domingo))?$/i
    );
    if (onMatch) {
      shift = {
        lead: onMatch[1].trim(),
        programme: onMatch[2].trim(),
        date: onMatch[3] || "today",
        assign_as: "lead",
      };
    }
    const supportMatch = raw.match(
      /^(.+?)\s+(?:as\s+)?support\s+(?:on|for)\s+(.+?)(?:\s+(today|tomorrow|\d{4}-\d{2}-\d{2}|monday|tuesday|wednesday|thursday|friday|saturday|sunday))?$/i
    );
    if (!shift && supportMatch) {
      shift = {
        lead: supportMatch[1].trim(),
        programme: supportMatch[2].trim(),
        date: supportMatch[3] || "today",
        assign_as: "support",
      };
    }
    const clearMatch = raw.match(
      /^(?:clear|cancel)\s+(.+?)(?:\s+(today|tomorrow|\d{4}-\d{2}-\d{2}|monday|tuesday|wednesday|thursday|friday|saturday|sunday))?$/i
    );
    if (!shift && clearMatch) {
      shift = {
        programme: clearMatch[1].trim(),
        date: clearMatch[2] || "today",
        clear: true,
      };
    }
  }

  if (!shift?.programme) {
    await sendMessage(chatId, `Could not parse that.\n\n${KIDS_PROGRAMME_HELP}`);
    return true;
  }

  try {
    const result = await assignKidsProgrammeShift(shift, { from, chatId });
    await sendMessage(chatId, formatKidsAssignReply(result));
  } catch (err) {
    log("kids programme /kids error:", err.message);
    await sendMessage(chatId, `Sorry — ${escapeHtml(err.message)}`);
  }
  return true;
}

async function captureAssignKidsProgramme({ classified, from, chatId }) {
  const shift = classified?.kids_programme_shift;
  if (!shift?.programme) return false;
  try {
    const result = await assignKidsProgrammeShift(shift, { from, chatId });
    await sendMessage(chatId, formatKidsAssignReply(result));
    return true;
  } catch (err) {
    log("assign kids programme error:", err.message);
    return false;
  }
}

async function fetchKidsProgrammeShiftsForDay(dateISO) {
  try {
    return await kidsProgrammeLib.fetchShiftsForDay(dateISO, {
      dbName: KIDS_PROGRAMME_DB_NAME,
    });
  } catch (err) {
    log("Kids programme shifts unavailable:", err.message);
    return [];
  }
}

function formatKidsProgrammeHtmlLines(shifts) {
  const { formatLeadLine } = kidsProgrammeLib;
  const lines = [];
  for (const shift of shifts) {
    if (shift.programme === "Day note") {
      const text = formatLeadLine(shift);
      lines.push(`• <i>Note:</i> ${escapeHtml(text)}`);
      continue;
    }
    const band = shift.time ? `${escapeHtml(shift.time)} ` : "";
    let people = escapeHtml(formatLeadLine(shift));
    let line = `• ${band}<b>${escapeHtml(shift.programme)}</b> — ${people}`;
    if (shift.notes) line += ` <i>(${escapeHtml(shift.notes)})</i>`;
    lines.push(line);
  }
  return lines;
}

function formatCampsiteSnapshot(dateISO, activity, meals) {
  const lines = ["Campsite check-ins (website + email/manual):"];
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

  lines.push("", "Meals booked (from the website):");
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

async function buildScheduleSnapshot(question, { scheduleRange, includeChildcareDetails = false } = {}) {
  const { start, end, label } = resolveScheduleRangeForToday(scheduleRange || "two_weeks");

  const lines = [label, ""];

  if (start === end) {
    lines.push(
      await buildDataSnapshot(start, { includeChildcareDetails }).then((s) =>
        s.split("\n").slice(2).join("\n")
      )
    );
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

    lines.push("", "Campsite check-ins / meals / childcare:");
    day = start;
    let anyCampsite = false;
    while (day <= end) {
      const activity = await fetchCampsiteActivityForDay(day);
      const meals = await fetchCampsiteMealsForDay(day);
      const childcare = await fetchChildcareForDay(day);
      const hasActivity =
        activity.arrivals.length ||
        activity.departures.length ||
        activity.staying.length ||
        (meals && (meals.breakfastCook || meals.dinnerCook)) ||
        (childcare && (childcare.headcount || childcare.absent));
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
        if (childcare) {
          lines.push(`  ${formatChildcareLine(childcare)}`);
          if (includeChildcareDetails && childcare.roster) {
            for (const line of childcare.roster.split("\n").filter(Boolean)) {
              lines.push(`    - ${line}`);
            }
          }
        }
        const kidsDay = await fetchKidsProgrammeShiftsForDay(day);
        if (kidsDay.length) {
          for (const line of kidsProgrammeLib
            .formatKidsProgrammeBlock(kidsDay)
            .split("\n")
            .filter(Boolean)) {
            lines.push(`  kids: ${line.replace(/^•\s*/, "")}`);
          }
        }
      }
      day = addDaysISO(day, 1);
    }
    if (!anyCampsite) lines.push("- (no campsite bookings/meals/childcare in this period)");
  }
  return lines.join("\n");
}

async function buildDataSnapshot(dateISO, { includeChildcareDetails = false } = {}) {
  const chores = await fetchRotaForDay(dateISO);
  const events = await fetchEventsForDate(dateISO);
  const activity = await fetchCampsiteActivityForDay(dateISO);
  const meals = await fetchCampsiteMealsForDay(dateISO);
  const childcare = await fetchChildcareForDay(dateISO);
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
  const markets = await fetchMarketsForDate(dateISO);
  if (markets.length) {
    const { flea, farmers } = regionalMarkets.groupMarketsByKind(markets);
    lines.push("", "Markets on this date:");
    if (flea.length) {
      lines.push(`Flea markets:`);
      for (const m of flea) lines.push(`- ${regionalMarkets.formatMarketBullet(m)}`);
    }
    if (farmers.length) {
      lines.push(`Farmers & gypsy markets:`);
      for (const m of farmers) lines.push(`- ${regionalMarkets.formatMarketBullet(m)}`);
    }
  }
  lines.push("", formatCampsiteSnapshot(dateISO, activity, meals));
  lines.push("", "Childcare:");
  if (includeChildcareDetails) {
    lines.push(formatChildcareRoster(childcare));
  } else {
    lines.push(formatChildcareLine(childcare));
  }
  const kidsShifts = await fetchKidsProgrammeShiftsForDay(dateISO);
  if (kidsShifts.length) {
    lines.push("", "Kids programme shifts:");
    lines.push(kidsProgrammeLib.formatKidsProgrammeBlock(kidsShifts));
  }
  return lines.join("\n");
}

async function answerFromNotionData(question) {
  const snapshot = await buildScheduleSnapshot(question);
  return openaiLib.answerFromData(question, snapshot);
}

/** Reject AI rewrite that invented placeholders or cooking when there was none. */
function recapComposedLooksUnsafe(composed, { hadCookOffers } = {}) {
  const t = String(composed || "");
  if (/\[[^\]]+\]/i.test(t)) return true;
  if (!hadCookOffers && /\bcommunity cooking\b/i.test(t)) return true;
  if (!hadCookOffers && /\b(cooking today|is making|per portion)\b/i.test(t)) return true;
  return false;
}

async function buildDailyRecap(dateISO) {
  const events = await fetchEventsForDate(dateISO);
  const marketsToday = await fetchMarketsForDate(dateISO);
  const yesterday = yesterdayISO();
  const yesterdayHighlights = await buildYesterdayHighlights(yesterday);
  let meals = null;
  let childcare = null;
  let kidsShifts = [];
  try {
    meals = await fetchCampsiteMealsForDay(dateISO);
    childcare = await fetchChildcareForDay(dateISO);
    kidsShifts = await fetchKidsProgrammeShiftsForDay(dateISO);
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
  const meetingTheme = morningMeetingThemeForDate(dateISO);
  if (meetingTheme) {
    lines.push("<b>Morning meeting</b>");
    lines.push(`• Theme: ${escapeHtml(meetingTheme)}`);
    lines.push("");
  }
  if (events.length) {
    lines.push("<b>Events today</b>");
    for (const e of events) {
      const recur = e.recurrence ? ` — ${escapeHtml(e.recurrence)}` : "";
      lines.push(`• ${escapeHtml(e.title)}${e.type ? ` (${escapeHtml(e.type)})` : ""}${recur}`);
    }
  }

  if (marketsToday.length) {
    const { flea, farmers } = regionalMarkets.groupMarketsByKind(marketsToday);
    if (flea.length) {
      lines.push("", "<b>Flea markets today</b>");
      for (const m of flea) {
        lines.push(`• ${escapeHtml(regionalMarkets.formatMarketBullet(m))}`);
      }
    }
    if (farmers.length) {
      lines.push("", "<b>Farmers & gypsy markets today</b>");
      for (const m of farmers) {
        lines.push(`• ${escapeHtml(regionalMarkets.formatMarketBullet(m))}`);
      }
    }
  }

  const cookOffers = (await fetchCommunityCookOffersForDay(dateISO)).filter(
    communityCookOffer.isCompleteCookOffer
  );
  if (cookOffers.length) {
    lines.push("", "<b>Community cooking today</b>");
    for (const offer of cookOffers) {
      lines.push(`• ${escapeHtml(communityCookOffer.formatRecapLine(offer))}`);
    }
  }

  const mealLines = [];
  if (meals?.breakfastCook) {
    mealLines.push(`• Breakfast: ${escapeHtml(String(meals.breakfastCook))} to cook`);
    if (meals.breakfastDetail) {
      for (const line of meals.breakfastDetail.split("\n").filter(Boolean).slice(0, 6)) {
        mealLines.push(`  ${escapeHtml(line)}`);
      }
    }
  }
  if (meals && meals.isWeekday !== false && meals.dinnerCook) {
    mealLines.push(`• Dinner: ${escapeHtml(String(meals.dinnerCook))} to cook`);
    if (meals.dinnerDetail) {
      for (const line of meals.dinnerDetail.split("\n").filter(Boolean).slice(0, 6)) {
        mealLines.push(`  ${escapeHtml(line)}`);
      }
    }
  }
  if (mealLines.length) {
    lines.push("", "<b>Meals booked on the website</b>");
    lines.push(...mealLines);
  }

  if (childcare && (childcare.headcount || childcare.absent)) {
    lines.push("", "<b>Childcare today</b>");
    lines.push(`• ${escapeHtml(formatChildcareLine(childcare))}`);
  }

  if (kidsShifts.length) {
    lines.push("", "<b>Kids programme today</b>");
    lines.push(...formatKidsProgrammeHtmlLines(kidsShifts));
  }

  if (yesterdayHighlights.length) {
    lines.push("", "<b>Yesterday's highlights</b>");
    for (const highlight of yesterdayHighlights) {
      lines.push(`• ${escapeHtml(highlight)}`);
    }
  }
  const template = lines.join("\n");
  let recap;
  try {
    const plain = template.replace(/<[^>]+>/g, "");
    const extras = [
      "Do not mention missing or zero items. Never say there are no meals to prepare, no events, or no childcare — omit those topics instead.",
      "Do not mention campsite check-ins, check-outs, pitch bookings, or who is staying on site — that is management-only.",
      "Meal bookings come from the website.",
      "Village morning meetings are Monday–Friday only — never Saturday or Sunday.",
    ];
    if (cookOffers.length) {
      extras.push(
        "Keep the 'Community cooking today' bullets exactly as written (real cook name, dish, diet, price, time). Do not add other cooking mentions."
      );
    } else {
      extras.push(
        "The source has no community cooking block — do not mention anyone cooking, shared meals, or placeholder fields like [Name] or [Dish]."
      );
    }
    if (meetingTheme) {
      extras.push(`Keep the morning meeting theme (${meetingTheme}) near the top.`);
    } else {
      extras.push(
        "The source text has no morning meeting block today — do not mention morning meetings at all (do not say they happen every day or on weekends)."
      );
    }
    if (yesterdayHighlights.length) {
      extras.push(
        "Rewrite the 'Yesterday's highlights' bullets naturally, e.g. " +
          "'Yesterday Sam said the shower light is broken.' Keep all facts."
      );
    }
    const friendly = await openaiLib.composeRecap(
      extras.length ? `${plain}\n\n${extras.join(" ")}` : plain
    );
    const composed = friendly.split("\n").map(escapeHtml).join("\n");
    recap = recapComposedLooksUnsafe(composed, { hadCookOffers: cookOffers.length > 0 })
      ? template
      : composed;
  } catch {
    recap = template;
  }
  return recap && String(recap).trim() ? recap : template;
}

/** After the main recap has run, keep trying childcare + kids topic posts until their daily slots succeed. */
async function maybeSendMorningFollowUps(dateISO) {
  if (!DAILY_RECAP || !telegramPollActive) return;
  if (currentHHMM() < RECAP_TIME) return;
  refreshRecapDatesFromDisk();
  if (state.lastRecapDate !== dateISO) return;
  await maybeSendPersonalMorningBriefings(dateISO);
  await maybeSendManagementCampsiteBriefings(dateISO);
}

async function maybeSendDailyRecap() {
  if (!DAILY_RECAP || !TELEGRAM_CHAT_ID) return;
  if (!telegramPollActive) return;
  refreshRecapDatesFromDisk();
  const today = todayISO();
  if (state.lastRecapDate === today) {
    await maybeSendMorningFollowUps(today);
    return;
  }
  if (currentHHMM() < RECAP_TIME) return;

  // Claim the slot before sending so a second process sharing this file skips.
  const previous = state.lastRecapDate;
  state.lastRecapDate = today;
  saveState();
  try {
    const recap = await buildDailyRecap(today);
    if (!recap || !String(recap).trim()) {
      throw new Error("Daily recap text was empty");
    }
    const sent = await sendMessage(TELEGRAM_CHAT_ID, recap);
    if (!sent) throw new Error("Daily recap send failed");
    log(`Daily recap sent for ${today}`);
    await maybeSendPersonalMorningBriefings(today);
    await maybeSendManagementCampsiteBriefings(today);
  } catch (err) {
    state.lastRecapDate = previous;
    saveState();
    log("Daily recap error:", err.message);
  }
  await maybeSendMorningFollowUps(today);
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
async function findMemberByTelegram(handle, userId) {
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(MEMBERS_DB_NAME));
  } catch (err) {
    log("Members fetch failed:", err.message);
    return { page: null };
  }
  const lower = String(handle || "").toLowerCase();
  const uid = userId != null ? String(userId) : "";
  let byHandle = null;
  for (const page of pages) {
    if (uid) {
      const storedId = notion.pagePropText(page, "Telegram user ID").trim();
      if (storedId && storedId === uid) return { page };
    }
    const uname = notion.pagePropText(page, "Telegram username").toLowerCase();
    if (uname && uname === lower) byHandle = page;
  }
  return { page: byHandle };
}

function memberPageToMorningDm(page) {
  const stage = notion.pagePropText(page, "Stage");
  const status = notion.pagePropText(page, "Member status");
  if (stage === "Left / inactive" || status === "Inactive") return null;
  const userId = notion.pagePropText(page, "Telegram user ID").trim();
  if (!userId || !/^\d+$/.test(userId)) return null;
  const username = notion.pagePropText(page, "Telegram username");
  const displayName =
    notion.pagePropText(page, "Display name") || notion.pageTitle(page);
  return { userId, username, displayName };
}

async function fetchMembersForMorningDm() {
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(MEMBERS_DB_NAME));
  } catch (err) {
    log("Members fetch for morning DM failed:", err.message);
    return [];
  }

  return pages.map(memberPageToMorningDm).filter(Boolean);
}

async function fetchManagementMembersForDm() {
  const roles = managementMemberRoleNames();
  if (!roles.length) return [];
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(MEMBERS_DB_NAME));
  } catch (err) {
    log("Members fetch for management DM failed:", err.message);
    return [];
  }
  return pages
    .map((page) => {
      const role = notion.pagePropText(page, "Role / focus").trim().toLowerCase();
      if (!roles.includes(role)) return null;
      return memberPageToMorningDm(page);
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

function splitAssigneeNames(value) {
  return String(value || "")
    .split(/[,;&]|\band\b/i)
    .map((s) => s.trim())
    .filter(Boolean);
}

async function fetchChildMinderMembers() {
  let jobs;
  let members;
  try {
    ({ pages: jobs } = await notion.queryDatabase(JOBS_DB_NAME));
    members = await fetchMembersForMorningDm();
  } catch (err) {
    log("Child minder resolution failed:", err.message);
    return [];
  }

  const wantedNames = new Set();
  for (const job of jobs || []) {
    const title = notion.pageTitle(job);
    const status = notion.pagePropText(job, "Status");
    if (status === "Archived" || !/\bchild\s*minder\b/i.test(title)) continue;
    for (const name of splitAssigneeNames(notion.pagePropText(job, "Assignee"))) {
      wantedNames.add(name);
    }
  }

  if (!wantedNames.size) return [];
  return members.filter((member) => {
    for (const name of wantedNames) {
      if (nameMatchesPerson(member.displayName, name) || titleMatches(member.displayName, name)) {
        return true;
      }
    }
    return false;
  });
}

async function isChildMinder(from) {
  const uid = from?.id ? String(from.id) : "";
  const username = String(from?.username || "").replace(/^@/, "").toLowerCase();
  if (!uid && !username) return false;
  const minders = await fetchChildMinderMembers();
  return minders.some((member) => {
    const memberUid = String(member.userId || "");
    const memberUser = String(member.username || "").replace(/^@/, "").toLowerCase();
    return (uid && memberUid === uid) || (username && memberUser === username);
  });
}

function isPrivateChat(chatId, from) {
  return from?.id != null && String(chatId) === String(from.id);
}

function isSensitiveChildcareQuestion(text) {
  const t = String(text || "").toLowerCase();
  return (
    /\b(childcare|child care|child minder|kids?|children)\b/.test(t) &&
    /\b(allerg|medical|medication|phone|number|parent|roster|who|sam|contact)\b/.test(t)
  ) || /\b(allerg(?:y|ies)|medical|medication)\b.*\b(today|kid|child|children)\b/.test(t) ||
    /\b(parent'?s?|parents?)\b.*\b(phone|number|contact)\b/.test(t);
}

async function maybeSendChildcareMorningBriefings(dateISO) {
  if (!DAILY_RECAP) return;
  if (!telegramPollActive) return;
  refreshRecapDatesFromDisk();
  if (state.lastChildcareRecapDate === dateISO) return;
  if (currentHHMM() < RECAP_TIME) return;

  const childcare = await fetchChildcareForDay(dateISO);
  if (!childcare || (!childcare.headcount && !childcare.absent && childcare.open === false)) {
    state.lastChildcareRecapDate = dateISO;
    saveState();
    return;
  }

  const minders = await fetchChildMinderMembers();
  let sent = 0;
  for (const minder of minders) {
    try {
      await sendMessage(
        minder.userId,
        `<b>Childcare today</b>\n${escapeHtml(formatChildcareRoster(childcare))}`
      );
      sent += 1;
    } catch (err) {
      log(`Childcare morning DM failed for ${minder.displayName}:`, err.message);
    }
  }
  state.lastChildcareRecapDate = dateISO;
  saveState();
  if (sent) log(`Childcare morning briefings sent: ${sent}`);
}

function rememberForumTopicFromMessage(message, explicitName) {
  const threadId = message?.message_thread_id;
  if (!threadId) return null;
  const name =
    explicitName ||
    message.forum_topic_created?.name ||
    message.reply_to_message?.forum_topic_created?.name ||
    "";
  const label = String(name || "Parents & Teachers").trim();
  if (!state.telegramTopicAliases) state.telegramTopicAliases = {};
  state.telegramTopicAliases[label] = threadId;
  saveState();
  return { label, threadId };
}

async function maybeSendKidsProgrammeMorningUpdates(dateISO) {
  if (!DAILY_RECAP) return;
  if (!telegramPollActive) return;
  refreshRecapDatesFromDisk();
  if (state.lastKidsProgrammeRecapDate === dateISO) return;
  if (currentHHMM() < RECAP_TIME) return;

  const shifts = await fetchKidsProgrammeShiftsForDay(dateISO);
  if (!shifts.length) {
    state.lastKidsProgrammeRecapDate = dateISO;
    saveState();
    return;
  }

  const dayName = new Intl.DateTimeFormat("en-GB", {
    timeZone: RECAP_TZ,
    weekday: "long",
    day: "numeric",
    month: "long",
  }).format(new Date(dateISO + "T12:00:00"));

  const dest = kidsProgrammeLib.resolveParentsTeachersDestination({
    fallbackChatId: TELEGRAM_CHAT_ID,
    topicAliases: state.telegramTopicAliases,
  });
  let topicPosted = false;
  if (dest?.chatId) {
    const topicLines = [
      `<b>Kids programme — ${escapeHtml(dayName)}</b>`,
      "",
      ...formatKidsProgrammeHtmlLines(shifts),
    ];
    const tbc = kidsProgrammeLib.countTbcShifts(shifts);
    if (tbc) {
      topicLines.push(
        "",
        `⚠️ ${tbc} session${tbc === 1 ? "" : "s"} still TBC — update Notion or reply here.`
      );
    }
    const extra = dest.topicId ? { message_thread_id: dest.topicId } : {};
    if (!dest.topicId) {
      log(
        "Kids programme: no topic id yet — posting to group general. Run /kids-topic inside Parents & Teachers topic once."
      );
    }
    const ok = await sendMessage(dest.chatId, topicLines.join("\n"), extra);
    topicPosted = ok;
    if (ok) log(`Kids programme topic roundup sent for ${dateISO}`);
    else log(`Kids programme topic roundup failed for ${dateISO} (will retry)`);
  } else {
    log(
      "Kids programme topic skipped: set TELEGRAM_CHAT_ID / KIDS_PROGRAMME_TELEGRAM_CHAT_ID (will retry)"
    );
  }

  let members = await fetchMembersForMorningDm();
  let roleMembers = [];
  members = members.map((m) => ({
    ...m,
    role: "",
  }));
  try {
    roleMembers = await broadcastRemindersLib.fetchMembersForRoleDm();
    const roleById = new Map(roleMembers.map((m) => [m.userId, m]));
    members = members.map((m) => ({
      ...m,
      role: roleById.get(m.userId)?.role || "",
    }));
  } catch {
    // role optional
  }

  let leadDms = 0;
  for (const { member, shifts: mine } of kidsProgrammeLib.groupDmTargets(shifts, members)) {
    const bulletLines = mine.map((s) => {
      const band = s.time ? `${escapeHtml(s.time)} ` : "";
      return `• ${band}${escapeHtml(s.programme)}`;
    });
    const text =
      `<b>You're on kids programme today</b> (${escapeHtml(dayName)})\n` +
      `${bulletLines.join("\n")}\n\n` +
      `<i>Full schedule is in Parents &amp; Teachers.</i>`;
    try {
      await sendMessage(member.userId, text);
      leadDms += 1;
    } catch (err) {
      log(`Kids programme DM failed for ${member.displayName}:`, err.message);
    }
  }

  const tbcCount = kidsProgrammeLib.countTbcShifts(shifts);
  if (tbcCount) {
    const coordinators = kidsProgrammeLib.membersWithKidsRole(
      roleMembers.length ? roleMembers : members
    );
    const coordText =
      `<b>Kids programme — cover needed</b>\n` +
      `${tbcCount} session${tbcCount === 1 ? "" : "s"} today still TBC in Notion.`;
    for (const coord of coordinators) {
      try {
        await sendMessage(coord.userId, coordText);
      } catch (err) {
        log(`Kids coordinator TBC DM failed for ${coord.displayName}:`, err.message);
      }
    }
  }

  if (topicPosted) {
    state.lastKidsProgrammeRecapDate = dateISO;
    saveState();
  }
  if (leadDms) log(`Kids programme lead DMs sent: ${leadDms}`);
}

function childcareFromOpsDay(day) {
  if (!day) return null;
  const rosterLines = [];
  for (const child of day.children || []) {
    const bits = [
      `${child.child_name || ""}, age ${child.child_age || 0}`,
      `parent ${child.parent_name || ""}`,
      `phone ${child.parent_phone || ""}`,
    ];
    if (child.allergies) bits.push(`allergies/medical: ${child.allergies}`);
    rosterLines.push(bits.filter(Boolean).join(" · "));
  }
  for (const child of day.absent_children || []) {
    rosterLines.push(`ABSENT: ${child.child_name || ""}, age ${child.child_age || 0} · parent ${child.parent_name || ""} · phone ${child.parent_phone || ""}`);
  }
  return {
    headcount: Number(day.booked_count || 0),
    capacity: Number(day.capacity || 0),
    absent: Number(day.absent_count || 0),
    open: Boolean(day.open),
    roster: rosterLines.join("\n"),
  };
}

async function notifyChildMindersOfAbsence(place, day) {
  const minders = await fetchChildMinderMembers();
  if (!minders.length) return 0;
  const childcare = childcareFromOpsDay(day) || (await fetchChildcareForDay(place?.ymd || todayISO()));
  const childName = place?.child_name || "A child";
  const date = place?.ymd || todayISO();
  let sent = 0;
  for (const minder of minders) {
    const text =
      `<b>Childcare absence</b>\n` +
      `${escapeHtml(childName)} will not attend childcare on ${escapeHtml(date)}.\n\n` +
      escapeHtml(formatChildcareRoster(childcare));
    if (await sendMessage(minder.userId, text)) sent += 1;
  }
  return sent;
}

async function handleChildcareAbsence({ text, from, chatId, childName, date }) {
  const parsed = childName
    ? { childName, date: date || todayISO() }
    : extractChildcareAbsence(text);
  if (!parsed.childName) {
    await sendMessage(chatId, "Which child should I mark absent from childcare today?");
    return true;
  }
  try {
    const result = await campsiteOps.markChildcareAbsent({
      date: parsed.date || todayISO(),
      childName: parsed.childName,
      telegramUsername: from?.username || "",
    });
    const place = result?.place || {};
    await sendMessage(
      chatId,
      `Marked <b>${escapeHtml(place.child_name || parsed.childName)}</b> absent for childcare today. I’ve told the child minders.`
    );
    await notifyChildMindersOfAbsence(place, result?.day);
  } catch (err) {
    const msg = String(err?.message || "");
    if (msg.includes("409") || /ambiguous/i.test(msg)) {
      await sendMessage(chatId, "I found more than one matching child. Please send the full child name.");
    } else if (msg.includes("401") || msg.includes("503")) {
      await sendMessage(chatId, "I can understand that absence, but the website ops token is not configured for VAVA yet.");
    } else {
      await sendMessage(chatId, "I couldn't find a booked childcare place for that child today.");
    }
    log("childcare absence error:", msg);
  }
  return true;
}

async function maybeSendManagementCampsiteBriefings(dateISO) {
  if (!DAILY_RECAP) return;
  if (!telegramPollActive) return;
  refreshRecapDatesFromDisk();
  if (currentHHMM() < RECAP_TIME) return;
  if (state.lastManagementCampsiteBriefingDate === dateISO) return;
  if (state.lastRecapDate !== dateISO) return;

  const text = await buildManagementCampsiteBriefing(dateISO);
  if (!text) {
    state.lastManagementCampsiteBriefingDate = dateISO;
    saveState();
    return;
  }

  const members = await fetchManagementMembersForDm();
  if (!members.length) {
    log("Management campsite briefing skipped: no registered management members");
    state.lastManagementCampsiteBriefingDate = dateISO;
    saveState();
    return;
  }

  let sent = 0;
  for (const member of members) {
    if (await sendMessage(member.userId, text)) sent += 1;
  }
  state.lastManagementCampsiteBriefingDate = dateISO;
  saveState();
  if (sent) log(`Management campsite briefing sent to ${sent} member(s)`);
}

async function maybeSendPersonalMorningBriefings(dateISO) {
  if (!DAILY_RECAP) return;
  if (!telegramPollActive) return;
  refreshRecapDatesFromDisk();
  if (currentHHMM() < RECAP_TIME) return;
  if (state.lastPersonalRecapDate === dateISO) {
    await maybeSendChildcareMorningBriefings(dateISO);
    await maybeSendKidsProgrammeMorningUpdates(dateISO);
    return;
  }

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
  await maybeSendChildcareMorningBriefings(dateISO);
  await maybeSendKidsProgrammeMorningUpdates(dateISO);
}

async function maybeSendBroadcastReminders() {
  if (!telegramPollActive) return;
  refreshRecapDatesFromDisk();
  const today = todayISO();
  const hhmm = currentHHMM();
  if (!state.broadcastRemindersSent) state.broadcastRemindersSent = {};

  let reminders;
  try {
    reminders = await broadcastRemindersLib.fetchActiveBroadcastReminders();
  } catch (err) {
    log("Broadcast reminders fetch failed:", err.message);
    return;
  }
  if (!reminders.length) return;

  let roleMembers = null;

  for (const { page, row } of reminders) {
    if (!row.message?.trim()) continue;
    if (state.broadcastRemindersSent[row.id] === today) continue;
    if (hhmm < row.sendTime) continue;
    if (!broadcastRemindersLib.reminderDueOnDate(page, today, RECAP_TZ)) continue;

    const titleLine = row.title ? `<b>${escapeHtml(row.title)}</b>\n` : "";
    const text = `${titleLine}${escapeHtml(row.message)}`;

    const needsRole = row.audiences.includes(broadcastRemindersLib.AUDIENCE_ROLE);
    if (needsRole && !row.memberRole) {
      log(`Broadcast reminder "${row.title}" skipped: Members by role but no Member role set`);
      continue;
    }
    if (
      row.audiences.includes(broadcastRemindersLib.AUDIENCE_CHAT) &&
      !row.chatId
    ) {
      log(`Broadcast reminder "${row.title}" skipped: Telegram chat but no chat id`);
      continue;
    }

    roleMembers =
      roleMembers || (await broadcastRemindersLib.fetchMembersForRoleDm());

    const { ok, sentTo } = await broadcastRemindersLib.deliverBroadcastReminder(
      row,
      text,
      {
        sendMessage,
        mainChatId: TELEGRAM_CHAT_ID,
        roleMembers,
      }
    );

    if (ok) {
      state.broadcastRemindersSent[row.id] = today;
      saveState();
      log(`Broadcast reminder sent (${sentTo.join(" + ")}): ${row.title}`);
    } else if (sentTo.length) {
      log(
        `Broadcast reminder "${row.title}" partially sent (${sentTo.join(", ")}); will retry`
      );
    }
  }
}

async function registerMember({ displayName, from, chatId }) {
  const handle = senderHandle(from);
  const { page } = await findMemberByTelegram(handle, from?.id);

  const properties = {
    "Display name": displayName,
    "Telegram username": handle,
    "Telegram user ID": String(from.id),
    "Telegram chat ID": String(chatId),
    "Active?": true,
    "Member status": "Active",
  };

  if (page) {
    await notion.updatePageProps(MEMBERS_DB_NAME, page.id, properties);
    return { updated: true, pageId: page.id };
  }

  const created = await notion.createPage({
    databaseName: MEMBERS_DB_NAME,
    title: displayName,
    properties: {
      Stage: "Onboarding",
      "Joined date": todayISO(),
      ...properties,
    },
  });
  return { updated: false, pageId: created.id };
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

  if (dbName === INTERNAL_EVENTS_DB_NAME || dbName === EXTERNAL_EVENTS_DB_NAME) {
    const isOcc =
      notion.pagePropText(page, "Is occurrence?") === "true" ||
      Boolean(notion.pagePropText(page, "Occurrence ID").trim());
    if (!isOcc && notion.pagePropText(page, "Recurrence").trim()) {
      map.Recurrence = "";
    }
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

function eventSeriesBaseTitle(name) {
  return String(name || "")
    .replace(/\s+[—–-]\s+\d{4}-\d{2}-\d{2}\s*$/, "")
    .trim();
}

function eventPageMatchesCancelQuery(page, query) {
  const q = String(query || "").toLowerCase().trim();
  if (!q) return false;
  const name = notion.pageTitle(page);
  if (titleMatches(q, name) || name.toLowerCase().includes(q)) return true;
  const base = eventSeriesBaseTitle(name);
  return titleMatches(q, base) || base.toLowerCase().includes(q);
}

function extractEventCancelQuery(text) {
  const t = String(text || "");
  let m = t.match(
    /\b(?:remove|delete|cancel)\s+(?:all\s+)?(?:the\s+)?events?\s+(?:for|about|called|named)?\s+(.+?)(?:\.|,|$|please)/i
  );
  if (m) return m[1].trim().replace(/\s+events?\s*$/i, "");
  m = t.match(
    /\b(?:remove|delete|cancel)\s+(?:the\s+)?(.+?)\s+events?\b(?:\s+(?:repeated|recurring).*)?(?:\.|,|$)/i
  );
  if (m) {
    return m[1]
      .trim()
      .replace(/\s+(workshop|class|session|course)s?\s*$/i, "")
      .trim();
  }
  m = t.match(/\bno more\s+(.+?)(?:\s+anymore)?(?:\.|,|$)/i);
  if (m) {
    return m[1]
      .trim()
      .replace(/\s+(workshop|class|event|session)s?\s*$/i, "")
      .trim();
  }
  m = t.match(/\b(?:stop|discontinu(?:e|ed))\s+(?:the\s+)?(.+?)(?:\.|,|$)/i);
  if (m) return m[1].trim();
  m = t.match(
    /\b(?:remove|delete|cancel)\s+(?:the\s+)?(.+?)\s+(?:workshop|class|session|course)\b/i
  );
  if (m) return m[1].trim();
  return "";
}

async function findRecurringSeriesHubsForQuery(query) {
  const q = String(query || "").trim();
  if (!q) return [];
  const hubs = [];
  const seen = new Set();
  for (const dbName of [INTERNAL_EVENTS_DB_NAME, EXTERNAL_EVENTS_DB_NAME]) {
    let pages;
    try {
      ({ pages } = await notion.queryDatabase(dbName));
    } catch {
      continue;
    }
    for (const page of pages) {
      if (pageIsDone(page)) continue;
      const recurrence = notion.pagePropText(page, "Recurrence");
      if (!recurrence || !parseRecurrence(recurrence)) continue;
      const isOcc =
        notion.pagePropText(page, "Is occurrence?") === "true" ||
        Boolean(notion.pagePropText(page, "Occurrence ID").trim());
      if (isOcc) continue;
      if (!eventPageMatchesCancelQuery(page, q)) continue;
      if (seen.has(page.id)) continue;
      seen.add(page.id);
      hubs.push({ id: page.id, name: notion.pageTitle(page), boardName: dbName });
    }
  }
  return hubs;
}

async function sendCancelEventOutcome(chatId, result, { query, interactive }) {
  if (!interactive) return;
  if (result.duplicate) {
    await sendMessage(
      chatId,
      "I already marked that cancelled a moment ago — double-check Internal/External Events in Notion."
    );
    return;
  }
  const cancelledNames =
    result.names?.length ? result.names : result.name ? [result.name] : [];
  if (result.ok && cancelledNames.length) {
    const list = cancelledNames.map((n) => `• ${escapeHtml(n)}`).join("\n");
    await sendMessage(
      chatId,
      cancelledNames.length > 1
        ? `🚫 Marked cancelled in Notion (${cancelledNames.length}):\n${list}`
        : `🚫 Cancelled: <b>${escapeHtml(cancelledNames[0])}</b>`
    );
    return;
  }
  if (result.reason === "ambiguous" && result.matches?.length) {
    const names = result.matches.map((m) => m.name || m).join(", ");
    await sendMessage(chatId, `Which event? I found: ${escapeHtml(names)}.`);
    return;
  }
  if (result.reason === "not_found") {
    await sendMessage(
      chatId,
      query
        ? `I couldn't find any upcoming events matching “${escapeHtml(query)}”.`
        : "I couldn't find a matching upcoming event in Notion."
    );
  }
}

function reminderPageMatches(page, query) {
  const q = String(query || "").toLowerCase().trim();
  if (!q) return false;
  const title = notion.pageTitle(page);
  const notes = notion.pagePropText(page, "Notes");
  if (titleMatches(q, title)) return true;
  if (notes && titleMatches(q, notes)) return true;
  return title.toLowerCase().includes(q) || notes.toLowerCase().includes(q);
}

async function findEventsBySearchQuery(query) {
  const q = String(query || "").trim();
  if (!q) return [];
  const dbNames = [INTERNAL_EVENTS_DB_NAME, EXTERNAL_EVENTS_DB_NAME];
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
      if (!eventPageMatchesCancelQuery(page, q)) continue;
      matches.push({ id: page.id, name: notion.pageTitle(page), boardName: dbName });
    }
  }
  return matches;
}

async function cancelEventsMatchingFromChat({ query, text, from, chatId }) {
  const key = ambientDedupeKey(chatId, "cancel-all", query);
  if (wasRecentlyCaptured(key)) return { ok: true, duplicate: true, names: [] };

  let matches = await findEventsBySearchQuery(query);
  const hubs = await findRecurringSeriesHubsForQuery(query);
  const seen = new Set(matches.map((m) => m.id));
  for (const hub of hubs) {
    if (!seen.has(hub.id)) {
      seen.add(hub.id);
      matches.push(hub);
    }
  }
  if (!matches.length) return { ok: false, reason: "not_found" };

  const names = [];
  for (const m of matches) {
    const result = await markItemDone(m.id);
    if (result.ok) {
      names.push(result.name || m.name);
      recordChatActivity({
        kind: "event_cancelled",
        from,
        chatId,
        title: result.name || m.name,
        text,
      });
    }
  }
  if (!names.length) return { ok: false, reason: "not_found" };
  markCaptured(key);
  return { ok: true, names };
}

async function updateReminderFromChat({ reminder, from, chatId }) {
  const query = reminder.match_title || reminder.title;
  if (!query && !reminder.recurrence) return { ok: false, reason: "empty" };

  let pages;
  try {
    ({ pages } = await notion.queryDatabase(REMINDERS_DB_NAME));
  } catch (err) {
    log("Reminders fetch failed:", err.message);
    return { ok: false, reason: "db_error" };
  }

  const open = pages.filter((p) => {
    const status = notion.pagePropText(p, "Status").toLowerCase();
    return status !== "done" && status !== "cancelled";
  });
  const matches = open.filter((p) => reminderPageMatches(p, query));
  if (!matches.length) return { ok: false, reason: "not_found" };
  if (matches.length > 1) {
    return {
      ok: false,
      reason: "ambiguous",
      matches: matches.map((p) => notion.pageTitle(p)),
    };
  }

  const page = matches[0];
  const props = {};
  if (reminder.recurrence) {
    const info = normalizeRecurrenceField(reminder.recurrence);
    props.Recurrence = info.canonical || reminder.recurrence;
  }
  if (reminder.notes) props.Notes = reminder.notes;
  if (!Object.keys(props).length) return { ok: false, reason: "empty" };

  await notion.updatePageProps(REMINDERS_DB_NAME, page.id, props);
  recordChatActivity({
    kind: "reminder_logged",
    from,
    chatId,
    title: notion.pageTitle(page),
    text: reminder.recurrence ? `Recurrence → ${props.Recurrence}` : "",
  });
  return { ok: true, name: notion.pageTitle(page), recurrence: props.Recurrence || null };
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
async function createReminder({ title, dueDate, notes, from, chatId, broadcast }) {
  const props = {
    "Created by": senderDisplayName(from),
    "Telegram user": senderHandle(from),
    Status: "Open",
  };
  if (dueDate) props["Due date"] = dueDate;
  if (notes) props.Notes = notes;

  if (broadcast?.postToTelegram) {
    props["Post to Telegram"] = true;
    if (broadcast.recurrence) props.Recurrence = broadcast.recurrence;
    if (broadcast.audiences?.length) {
      props.Audiences = broadcast.audiences;
    }
    if (broadcast.telegramChat) props["Telegram chat"] = broadcast.telegramChat;
    if (broadcast.chatId) props["Telegram chat ID"] = broadcast.chatId;
    if (broadcast.topicId) props["Topic ID"] = String(broadcast.topicId);
    if (broadcast.memberRole) props["Member role"] = broadcast.memberRole;
  }

  const page = await notion.createPage({
    databaseName: REMINDERS_DB_NAME,
    title: String(title || "Reminder").slice(0, 200),
    properties: props,
  });
  markPageSeen(page.id, { stage: "", status: "", doer: "", done: false });
  return { itemId: page.id, url: page.url, broadcast: Boolean(broadcast?.postToTelegram) };
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

async function handleLocationQuestion({ text, classified, chatId }) {
  try {
    const result = await locationsLib.answerLocationQuestion({
      item: classified?.location?.item,
      listAll: classified?.location?.list_all,
      area: classified?.location?.area,
      question: text,
    });
    if (result.list) {
      await sendMessage(chatId, `<pre>${escapeHtml(result.snapshot)}</pre>`);
      return true;
    }
    if (result.ok && result.item) {
      let reply = `📍 ${escapeHtml(locationsLib.formatLocationAnswer(result.item))}`;
      if (result.item.url) reply += `\n<a href="${result.item.url}">Notion</a>`;
      await sendMessage(chatId, reply);
      return true;
    }
    if (result.reason === "ambiguous") {
      const names = (result.matches || []).map((m) => m.name).join(", ");
      await sendMessage(chatId, `Which one? I found: ${escapeHtml(names)}.`);
      return true;
    }
    return false;
  } catch (err) {
    log("handleLocationQuestion error:", err.message);
    return false;
  }
}

async function handleLocationUpdate({ text, from, chatId, classified }) {
  const items =
    Array.isArray(classified?.locations) && classified.locations.length
      ? classified.locations.filter((row) => row?.item)
      : classified?.location?.item
        ? [classified.location]
        : [];
  if (!items.length) {
    const extracted = locationsLib.extractLocationUpdate(text);
    if (extracted) items.push(extracted);
  }
  if (!items.length) {
    await sendMessage(
      chatId,
      "Tell me the object and where it is — e.g. “the drill is in the workshop” or “I left the bathroom keys on the hook”."
    );
    return false;
  }

  const fromName = senderDisplayName(from);
  const lines = [];
  for (const loc of items) {
    const key = ambientDedupeKey(chatId, "location", loc.item);
    if (wasRecentlyCaptured(key)) continue;
    try {
      const result = await locationsLib.upsertLocation({
        item: loc.item,
        location: loc.location,
        usualPlace: loc.usual_place,
        updateKind: loc.update_kind,
        itemKind: loc.item_kind,
        area: loc.area,
        aliases: loc.aliases,
        notes: loc.notes,
        fromName,
        todayISO: todayISO(),
        sourceText: text,
      });
      if (result.reason === "ambiguous") {
        const names = (result.matches || []).map((m) => m.name).join(", ");
        await sendMessage(chatId, `Which one? I found: ${escapeHtml(names)}.`);
        continue;
      }
      if (!result.ok) continue;
      markCaptured(key);
      recordChatActivity({
        kind: "location_logged",
        from,
        chatId,
        title: result.name,
        text,
      });
      let line = result.missing
        ? `⚠️ Marked missing: <b>${escapeHtml(result.name)}</b>`
        : result.created
          ? `📍 Added <b>${escapeHtml(result.name)}</b>`
          : `📍 Updated <b>${escapeHtml(result.name)}</b>`;
      if (result.current) line += ` → ${escapeHtml(result.current)}`;
      if (result.usual && result.usual !== result.current) {
        line += ` <i>(usual: ${escapeHtml(result.usual)})</i>`;
      }
      if (result.url) line += ` — <a href="${result.url}">Notion</a>`;
      lines.push(line);
    } catch (err) {
      log("upsertLocation error:", err.message);
    }
  }

  if (!lines.length) {
    await sendMessage(chatId, "Sorry, I could not save that on Where things are.");
    return false;
  }
  await sendMessage(chatId, lines.join("\n"));
  return true;
}

async function handleFaqQuestion({ text, classified, chatId }) {
  try {
    const result = await faqsLib.answerFaqQuestion({
      question: classified?.faq?.question || text,
      listAll: classified?.faq?.list_all,
      category: classified?.faq?.category,
    });
    if (result.list) {
      await sendMessage(chatId, `<pre>${escapeHtml(result.snapshot)}</pre>`);
      return true;
    }
    if (result.ok && result.faq) {
      let reply = `❓ ${faqsLib.formatFaqAnswer(result.faq)}`;
      if (result.faq.url) reply += `\n<a href="${result.faq.url}">Notion</a>`;
      await sendMessage(chatId, reply);
      return true;
    }
    if (result.reason === "ambiguous") {
      const names = (result.matches || []).map((m) => m.question).join(", ");
      await sendMessage(chatId, `Which FAQ? I found: ${escapeHtml(names)}.`);
      return true;
    }
    return false;
  } catch (err) {
    log("handleFaqQuestion error:", err.message);
    if (isNotionTransientError(err)) {
      await sendMessage(
        chatId,
        "Notion is having a brief outage — I can't read FAQs right now. Please try again in a minute."
      );
      return true;
    }
    return false;
  }
}

async function handleFaqCreate({ text, from, chatId, classified }) {
  let faq = classified?.faq;
  if (!faq?.question || !faq?.answer) {
    const extracted = faqsLib.extractFaqUpsert(text);
    if (extracted) faq = { ...(faq || {}), ...extracted };
  }
  if ((!faq?.question || !faq?.answer) && classified?.faq?.answer && !classified?.faq?.question) {
    faq = {
      question: "What is the address?",
      answer: classified.faq.answer,
      ...faq,
    };
  }
  if (!faq?.question || !faq?.answer) {
    await sendMessage(
      chatId,
      "Give me a question and answer — e.g. “Add FAQ: What is the delivery address? → Prado, GPS …”"
    );
    return false;
  }

  const key = ambientDedupeKey(chatId, "faq", faq.question);
  if (wasRecentlyCaptured(key)) return true;

  try {
    const result = await faqsLib.upsertFaq({
      question: faq.question,
      answer: faq.answer,
      category: faq.category,
      aliases: faq.aliases,
      fromName: senderDisplayName(from),
      sourceText: text,
    });
    if (result.reason === "ambiguous") {
      const names = (result.matches || []).map((m) => m.question).join(", ");
      await sendMessage(chatId, `Which FAQ should I update? I found: ${escapeHtml(names)}.`);
      return true;
    }
    if (!result.ok) {
      await sendMessage(chatId, "Sorry, I could not save that FAQ.");
      return false;
    }
    markCaptured(key);
    recordChatActivity({
      kind: "faq_logged",
      from,
      chatId,
      title: result.name,
      text,
    });
    let reply = result.created
      ? `❓ Added FAQ: <b>${escapeHtml(result.name)}</b>`
      : `❓ Updated FAQ: <b>${escapeHtml(result.name)}</b>`;
    if (result.url) reply += ` — <a href="${result.url}">Notion</a>`;
    await sendMessage(chatId, reply);
    return true;
  } catch (err) {
    log("handleFaqCreate error:", err.message);
    if (isNotionTransientError(err)) {
      await sendMessage(
        chatId,
        "Notion is having a brief outage — I couldn't save that FAQ. Please try again in a minute."
      );
      return true;
    }
    await sendMessage(chatId, "Sorry, I could not save that FAQ.");
    return false;
  }
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

// ── Member questionnaire (Community board, DM-only, no expiry) ───────────────

const ROLE_OPTIONS = ["Villager", "Volunteer", "Worker", "Guest", "Manager"];

const SKILLSET_OPTIONS = [
  "Building / carpentry",
  "Garden / land",
  "Cooking / food",
  "Solar / electrical",
  "Plumbing / water",
  "Vehicles / mechanics",
  "Languages / translation",
  "Kids / education",
  "Events / hosting",
  "Admin / money",
  "Art / music",
  "Healing / bodywork",
];

const AREA_OPTIONS = [
  "Water",
  "Compost & toilets",
  "Solar / electric",
  "Buildings / structures",
  "Tools / workshop",
  "Land / roads / drainage",
  "Campsite / guest",
  "Garden",
  "Community / events",
  "Safety & emergency",
  "Admin / money",
  "Other",
];

function multiSelectOpts(names) {
  const colors = [
    "gray",
    "brown",
    "orange",
    "yellow",
    "green",
    "blue",
    "purple",
    "pink",
    "red",
  ];
  return names.map((name, i) => ({
    name,
    color: colors[i % colors.length],
  }));
}

async function ensureMembersQuestionnaireSchema() {
  try {
    const db = await notion.resolveDatabase(MEMBERS_DB_NAME);
    const existing = db.properties || {};
    const toAdd = {};
    if (!existing.Skills) toAdd.Skills = { rich_text: {} };
    if (!existing.Bio) toAdd.Bio = { rich_text: {} };
    if (!existing.Profile) toAdd.Profile = { rich_text: {} };
    if (!existing["Questionnaire done?"]) {
      toAdd["Questionnaire done?"] = { checkbox: {} };
    }
    if (!existing.Skillsets) {
      toAdd.Skillsets = {
        multi_select: { options: multiSelectOpts(SKILLSET_OPTIONS) },
      };
    }
    if (!existing["Help areas"]) {
      toAdd["Help areas"] = {
        multi_select: { options: multiSelectOpts(AREA_OPTIONS) },
      };
    }
    const roleProp = existing["Role / focus"];
    if (roleProp?.select) {
      const existingOpts = roleProp.select.options || [];
      const have = new Set(existingOpts.map((o) => o.name));
      const extras = ROLE_OPTIONS.filter((n) => !have.has(n));
      if (extras.length) {
        toAdd["Role / focus"] = {
          select: {
            options: [
              ...existingOpts.map((o) => ({
                id: o.id,
                name: o.name,
                color: o.color,
              })),
              ...multiSelectOpts(extras),
            ],
          },
        };
      }
    }
    if (!Object.keys(toAdd).length) {
      log("Members questionnaire schema: OK");
      return;
    }
    await notion.notion("PATCH", `/databases/${db.id}`, { properties: toAdd });
    await notion.primeDatabase(MEMBERS_DB_NAME, db.id);
    log(`Members questionnaire schema: added ${Object.keys(toAdd).join(", ")}`);
  } catch (err) {
    log("Members questionnaire schema ensure failed:", err.message);
  }
}

function formatChoiceList(options) {
  return options.map((opt, i) => `${i + 1}. ${escapeHtml(opt)}`).join("\n");
}

function parseChoiceNumber(token, optionsLength) {
  const m = String(token || "").trim().match(/^(\d+)\s*[.):\-–]?\s*$/);
  if (!m) return null;
  const asNum = Number(m[1]);
  if (!Number.isInteger(asNum) || asNum < 1 || asNum > optionsLength) return null;
  return asNum;
}

function parseSingleChoice(text, options) {
  const raw = String(text || "").trim();
  if (!raw) return null;
  if (/^(skip|none|-)$/i.test(raw)) return { skip: true };
  const numbered = raw.match(/^(\d+)\s*[.):\-–]?\s*(.*)$/);
  if (numbered) {
    const asNum = Number(numbered[1]);
    if (Number.isInteger(asNum) && asNum >= 1 && asNum <= options.length) {
      return { value: options[asNum - 1] };
    }
  }
  const lower = raw.toLowerCase();
  const exact = options.find((o) => o.toLowerCase() === lower);
  if (exact) return { value: exact };
  const partial = options.filter((o) => o.toLowerCase().includes(lower));
  if (partial.length === 1) return { value: partial[0] };
  return null;
}

function parseMultiChoice(text, options) {
  const raw = String(text || "").trim();
  if (!raw) return null;
  if (/^(skip|none|-)$/i.test(raw)) return { values: [], other: "", skip: true };

  const otherMatch = raw.match(/\bother\s*[:=]\s*(.+)$/i);
  const other = otherMatch ? otherMatch[1].trim() : "";
  let main = otherMatch ? raw.slice(0, otherMatch.index).trim() : raw;
  main = main.replace(/[,;]/g, " ").replace(/\s+/g, " ").trim();

  const values = [];
  const seen = new Set();
  const add = (name) => {
    if (!name || seen.has(name)) return;
    seen.add(name);
    values.push(name);
  };

  if (/^all$/i.test(main)) {
    options.forEach(add);
  } else if (main) {
    for (const token of main.split(" ")) {
      if (!token || /^other$/i.test(token)) continue;
      const asNum = parseChoiceNumber(token, options.length);
      if (asNum) {
        add(options[asNum - 1]);
        continue;
      }
      const lower = token.toLowerCase();
      const exact = options.find((o) => o.toLowerCase() === lower);
      if (exact) {
        add(exact);
        continue;
      }
      const partial = options.filter(
        (o) =>
          o.toLowerCase().includes(lower) ||
          lower.includes(o.toLowerCase().split(/[\/&]/)[0].trim())
      );
      if (partial.length === 1) add(partial[0]);
    }
  }

  if (!values.length && !other) return null;
  return { values, other };
}

function questionnairePrompt(stepId) {
  const total = QUESTIONNAIRE_STEP_ORDER.length;
  const n = QUESTIONNAIRE_STEP_ORDER.indexOf(stepId) + 1;
  const progress = n > 0 ? `${n}/${total}` : "";
  if (stepId === "role") {
    return (
      `<b>Community questionnaire</b> (${progress})\n` +
      `What’s your <b>role</b> here?\n` +
      formatChoiceList(ROLE_OPTIONS) +
      `\n\nReply with a number (or <i>skip</i>).`
    );
  }
  if (stepId === "skills") {
    return (
      `<b>Community questionnaire</b> (${progress})\n` +
      `What <b>skillsets</b> can you share? Pick several.\n` +
      formatChoiceList(SKILLSET_OPTIONS) +
      `\n\nReply with numbers, e.g. <code>1 3 5</code>\n` +
      `Add something missing with <code>other: welding</code>\n` +
      `Or <i>skip</i>.`
    );
  }
  if (stepId === "areas") {
    return (
      `<b>Community questionnaire</b> (${progress})\n` +
      `Which <b>areas</b> can you help with? Pick several.\n` +
      formatChoiceList(AREA_OPTIONS) +
      `\n\nReply with numbers, e.g. <code>2 7 9</code>, or <i>skip</i>.`
    );
  }
  if (stepId === "bio") {
    return (
      `<b>Community questionnaire</b> (${progress})\n` +
      `Write a short <b>bio</b> for the Community board — who you are, what you care about.\n` +
      `Or <i>skip</i>.`
    );
  }
  if (stepId === "contact") {
    return (
      `<b>Community questionnaire</b> (${progress})\n` +
      `Phone or other contact (optional — only visible on the Members board).\n` +
      `Or <i>skip</i> / <i>done</i>.`
    );
  }
  return "Unknown questionnaire step.";
}

const QUESTIONNAIRE_STEP_ORDER = ["role", "skills"];

function normalizePendingQuestionnaires(bag) {
  const next = {};
  for (const [key, pending] of Object.entries(bag || {})) {
    if (!pending) continue;
    const uid = String(pending.fromId || String(key).split(":").pop());
    if (!uid) continue;
    if (pending.step === "stage") pending.step = "skills";
    const prev = next[uid];
    if (!prev || (pending.ts || 0) >= (prev.ts || 0)) next[uid] = pending;
  }
  return next;
}

function botUsername() {
  return botMe?.username || "vila_akoya_bot";
}

function questionnaireUserKey(fromId) {
  return String(fromId);
}

function findQuestionnaire(fromId) {
  const uid = questionnaireUserKey(fromId);
  const bag = state.pendingQuestionnaire || {};
  let found = null;
  if (bag[uid]) found = { key: uid, pending: bag[uid] };
  else {
    for (const [key, pending] of Object.entries(bag)) {
      if (String(pending?.fromId) === uid || key === `${uid}:${uid}` || key.endsWith(`:${uid}`)) {
        found = { key, pending };
        break;
      }
    }
  }
  if (found?.pending?.step === "stage") found.pending.step = "skills";
  return found || { key: uid, pending: null };
}

function looksLikeQuestionnaireReply(text, pending) {
  const step = pending?.step;
  const raw = String(text || "").trim();
  if (!raw) return false;
  if (/^(skip|none|-|done|all|cancel|stop|abort)$/i.test(raw)) return true;
  if (step === "role" || step === "skills" || step === "areas") {
    return /^\d/.test(raw);
  }
  return false;
}

/** True when the user is chatting, not answering a numbered questionnaire step. */
function looksLikeGeneralConversation(text) {
  const raw = String(text || "").trim();
  if (!raw) return false;
  if (looksLikeQuestionnaireReply(text, { step: "role" })) return false;
  if (/[?¿]$/.test(raw) || looksLikeQuestion(raw)) return true;
  if (
    /^(what|why|how|who|when|where|are you|you awake|hello|hi|hey|help|thanks|thank you|ok|okay|cool|nice|lol|haha|cheers|bom dia|boa tarde|obrigad)/i.test(
      raw
    )
  ) {
    return true;
  }
  if (
    /^(i don'?t understand|reply to what|what do you mean|never mind|huh|wait|sorry|can you|could you|please)\b/i.test(
      raw
    )
  ) {
    return true;
  }
  return false;
}

function clearQuestionnaire(key) {
  if (state.pendingQuestionnaire?.[key]) {
    delete state.pendingQuestionnaire[key];
    saveState();
  }
}

async function startQuestionnaire({ from, chatId, pageId, displayName }) {
  if (!pageId) {
    const handle = senderHandle(from);
    const { page } = await findMemberByTelegram(handle, from?.id);
    if (!page) {
      await sendMessage(
        chatId,
        "Register first with /register Your Full Name, then I’ll send the questionnaire by DM."
      );
      return false;
    }
    pageId = page.id;
    displayName =
      displayName ||
      notion.pagePropText(page, "Display name") ||
      notion.pageTitle(page);
  }

  const name = displayName || senderDisplayName(from);
  const uid = questionnaireUserKey(from.id);
  const session = {
    pageId,
    displayName: name,
    step: "role",
    answers: {},
    fromId: from.id,
    ts: Date.now(),
  };
  const intro =
    `Great — a few quick questions so your profile shows on the <b>Community</b> board.\n` +
    `Reply here with a number (or <i>skip</i>). You can take your time — even a week later is fine.\n` +
    `Say <i>cancel</i> anytime, or run /questionnaire later to update.\n\n` +
    questionnairePrompt("role");

  // Always persist against the user id so a later DM still matches.
  state.pendingQuestionnaire[uid] = session;
  saveState();

  const inGroup = String(chatId) !== String(from.id);
  const dmOk = await sendMessage(from.id, intro);

  if (dmOk) {
    if (inGroup) {
      await sendMessage(
        chatId,
        `I sent you a DM with the Community questionnaire — answer there (just a number is enough).\n` +
          `If you don’t see it, open @${botUsername()} and tap <b>Start</b>.`
      );
    }
    return true;
  }

  if (inGroup) {
    await sendMessage(
      chatId,
      `I couldn’t message you privately yet (Telegram only allows DMs after you start the bot).\n` +
        `Open @${botUsername()}, tap <b>Start</b>, and I’ll send the questions there.`
    );
  }
  return true;
}

function buildMemberProfileSummary(answers, displayName) {
  const lines = [];
  if (displayName) lines.push(displayName);
  if (answers.role) lines.push(`Role: ${answers.role}`);
  const skills = [...(answers.skillsets || [])];
  if (answers.skillsOther) skills.push(answers.skillsOther);
  if (skills.length) lines.push(`Skills: ${skills.join(", ")}`);
  if (answers.areas?.length) lines.push(`Helps with: ${answers.areas.join(", ")}`);
  if (answers.bio) lines.push(answers.bio);
  return lines.join("\n");
}

async function saveQuestionnaireAnswers({ pageId, displayName, answers }) {
  const skillsets = answers.skillsets || [];
  const skillsOther = answers.skillsOther || "";
  const skillsText = [...skillsets, skillsOther].filter(Boolean).join(", ");
  const areas = answers.areas || [];
  const profile = buildMemberProfileSummary(answers, displayName);

  const properties = {
    "Questionnaire done?": true,
    Profile: profile,
  };
  if (answers.role) properties["Role / focus"] = answers.role;
  if (skillsets.length) properties.Skillsets = skillsets;
  if (skillsText) properties.Skills = skillsText;
  if (areas.length) {
    properties["Help areas"] = areas;
    properties["Areas of help"] = areas[0];
  }
  if (answers.bio) properties.Bio = answers.bio;
  if (answers.contact) properties["Phone / contact"] = answers.contact;

  await notion.updatePageProps(MEMBERS_DB_NAME, pageId, properties);
}

async function handlePendingQuestionnaire(message) {
  const chatId = message.chat.id;
  const fromId = message.from?.id;
  if (!fromId) return false;

  const found = findQuestionnaire(fromId);
  const pending = found.pending;
  if (!pending) return false;

  const uid = questionnaireUserKey(fromId);
  if (found.key !== uid) {
    state.pendingQuestionnaire[uid] = pending;
    delete state.pendingQuestionnaire[found.key];
    saveState();
  }

  const isPrivate = message.chat?.type === "private";
  const text = (message.text || message.caption || "").trim();
  if (!text || text.startsWith("/")) return false;

  if (!isPrivate) {
    if (isAddressedToBot(message, botMe) && looksLikeQuestionnaireReply(text, pending)) {
      await sendMessage(
        chatId,
        "Please answer in our private chat — I’ll send the current question there."
      );
      await sendMessage(fromId, questionnairePrompt(pending.step));
      return true;
    }
    return false;
  }

  if (/^(cancel|stop|abort)$/i.test(text)) {
    clearQuestionnaire(uid);
    await sendMessage(
      chatId,
      "Questionnaire cancelled. Run /questionnaire whenever you’re ready."
    );
    return true;
  }

  // Let normal chat / questions through — questionnaire stays pending until skip or /cancel.
  if (looksLikeGeneralConversation(text) && !looksLikeQuestionnaireReply(text, pending)) {
    return false;
  }

  if (pending.step === "stage") {
    pending.step = "skills";
    saveState();
    await sendMessage(chatId, questionnairePrompt("skills"));
    return true;
  }

  pending.ts = Date.now();
  const step = pending.step;
  const answers = pending.answers || {};

  if (step === "role") {
    const parsed = parseSingleChoice(text, ROLE_OPTIONS);
    if (!parsed) {
      if (!looksLikeQuestionnaireReply(text, pending)) return false;
      await sendMessage(
        chatId,
        `Please reply with a number 1–${ROLE_OPTIONS.length}, or <i>skip</i>. Say <i>cancel</i> to exit.`
      );
      return true;
    }
    if (!parsed.skip) answers.role = parsed.value;
    log(`Questionnaire role from ${fromId}: ${answers.role || "skip"}`);
  } else if (step === "skills") {
    const parsed = parseMultiChoice(text, SKILLSET_OPTIONS);
    if (!parsed) {
      if (!looksLikeQuestionnaireReply(text, pending)) return false;
      await sendMessage(
        chatId,
        "Please reply with numbers like <code>1 3 5</code>, and/or <code>other: your skill</code>, or <i>skip</i>. Say <i>cancel</i> to exit."
      );
      return true;
    }
    if (!parsed.skip) {
      answers.skillsets = parsed.values;
      if (parsed.other) answers.skillsOther = parsed.other;
    }
  } else if (step === "areas") {
    const parsed = parseMultiChoice(text, AREA_OPTIONS);
    if (!parsed) {
      if (!looksLikeQuestionnaireReply(text, pending)) return false;
      await sendMessage(
        chatId,
        "Please reply with numbers like <code>2 7 9</code>, or <i>skip</i>. Say <i>cancel</i> to exit."
      );
      return true;
    }
    if (!parsed.skip) answers.areas = parsed.values;
  } else if (step === "bio") {
    if (looksLikeGeneralConversation(text)) return false;
    if (!/^(skip|none|-)$/i.test(text)) answers.bio = text;
  } else if (step === "contact") {
    if (looksLikeGeneralConversation(text)) return false;
    if (!/^(skip|none|-|done)$/i.test(text)) answers.contact = text;
  } else {
    clearQuestionnaire(uid);
    return false;
  }

  pending.answers = answers;
  const idx = QUESTIONNAIRE_STEP_ORDER.indexOf(step);
  const next = QUESTIONNAIRE_STEP_ORDER[idx + 1];

  if (next) {
    pending.step = next;
    saveState();
    await sendMessage(chatId, questionnairePrompt(next));
    return true;
  }

  try {
    await saveQuestionnaireAnswers({
      pageId: pending.pageId,
      displayName: pending.displayName,
      answers,
    });
    clearQuestionnaire(uid);
    await sendMessage(
      chatId,
      `✅ Saved to the <b>Community</b> Members board` +
        (pending.displayName
          ? ` for <b>${escapeHtml(pending.displayName)}</b>`
          : "") +
        `.\nRun /questionnaire anytime to update.`
    );
  } catch (err) {
    log("saveQuestionnaireAnswers error:", err.message);
    await sendMessage(
      chatId,
      "Sorry, I couldn’t save your questionnaire right now. Try /questionnaire again in a bit."
    );
  }
  return true;
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

  const text = (message.text || "").trim();
  if (!text) return false;

  if (/^(never mind|nevermind|forget it|cancel|stop|no thanks)\b/i.test(text)) {
    delete state.pendingClarify[key];
    saveState();
    await sendMessage(chatId, "Okay — I won’t log that.");
    return true;
  }

  const partial = pending.partial;
  if (pending.kind === "community_cook") {
    if (pending.step === "diet") {
      const diet = communityCookOffer.parseDietFromReply(text);
      if (!diet) {
        await sendMessage(
          chatId,
          "Reply <b>1</b>, <b>2</b>, or <b>3</b> (meat, veggie, or vegan)."
        );
        return true;
      }
      partial.diet = diet;
      if (partial.priceEur == null) {
        pending.step = "price";
        pending.partial = partial;
        pending.ts = Date.now();
        saveState();
        await sendMessage(chatId, communityCookOffer.pricePromptMessage());
        return true;
      }
    } else if (pending.step === "price") {
      const price = communityCookOffer.parsePriceFromReply(text);
      if (price == null) {
        await sendMessage(
          chatId,
          "Send a number for euros per portion, or <b>0</b> / <b>free</b>."
        );
        return true;
      }
      partial.priceEur = price;
    }
    delete state.pendingClarify[key];
    saveState();
    await finishCommunityCookOffer({ offer: partial, from: message.from, chatId });
    return true;
  }

  if (pending.kind === "task_type_priority") {
    const inferred = inferTaskTypePriority(text);
    if (inferred.type) partial.type = inferred.type;
    if (inferred.priority) partial.priority = inferred.priority;
    if (!inferred.type && !inferred.priority) {
      const ai = await openaiLib.interpretTaskClarification(text).catch(() => null);
      if (ai?.cancel) {
        delete state.pendingClarify[key];
        saveState();
        await sendMessage(chatId, "Okay — I won’t log that.");
        return true;
      }
      if (ai?.type) partial.type = ai.type;
      if (ai?.priority) partial.priority = ai.priority;
    }
    if (!partial.type && !partial.priority && looksLikeQuestion(text)) {
      return false;
    }
    if (!partial.type) partial.type = "Villager";
    if (!partial.priority) partial.priority = "Average";
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
  "I can log tasks (villager work or management jobs), <b>recurring duties</b> (daily/weekly/monthly rota), events, reminders, RSVP to events, add notes to projects, <b>receipts / expenses</b>, say where things are, answer questions from docs and schedule, and more.",
  "Talk normally — I go by meaning, not exact phrases, and I use the messages around yours.",
  "If you ask for something I don't already have a set action for, I'll try to do it in Notion anyway.",
  "Send a list of cleaning/rota jobs in one message (or voice note) — daily, weekly, monthly — and I'll add them all to Notion.",
  "Voice notes are transcribed — explain how something works to create a structured doc.",
  "Receipts: in a <b>private DM</b>, send a photo or PDF and I'll treat it as a receipt (unless it clearly isn't). I'll then ask which company NIF (Alcateia or Povoado), then what it's for (Breakfast / Bar / Dinner / Kids Program / General). In the group, tag me or use /receipt.",
  "",
  "<b>Email / off-site bookings</b>",
  "Voice note or text a stay booked by email or phone and I’ll put it on Guest Stays in Notion (management get a daily DM with arrivals and occupancy).",
  "• “the Martins booked the cabin 20–24 Sept, 2 adults 1 child, via email”",
  "• “email booking: João arriving Friday leaving Monday, pitch 4”",
  "",
  "<b>Where things are</b>",
  "• “where are the bathroom keys?” / “where is the drill?”",
  "• “I left the drill in the kitchen” / “the keys live on the hook by reception”",
  "• “I can't find the bathroom keys” — marked missing until someone says where they are",
  "The live list is the <i>Where things are</i> page in Notion.",
  "",
  "<b>FAQs</b>",
  "• “what is the address for deliveries?”",
  "• “Add FAQ: What is the delivery address? → Prado, GPS …”",
  "Short Q&A lives in the <i>FAQs</i> database in Notion.",
  "",
  "<b>Task types</b>",
  "• <i>Villager</i> — village work anyone can pitch in on",
  "• <i>Management</i> — needs a professional or arranging",
  "",
  "<b>Commands</b>",
  "/task &lt;text&gt; — add a task",
  "/receipt — log a receipt (photo, PDF, or text). Reply to a photo with /receipt works too",
  "/receipts — this month's totals by person",
  "/ask &lt;question&gt; — search our documentation",
  "/register &lt;your name&gt; — link Telegram to Members; questionnaire arrives by DM",
  "/questionnaire — fill or update your Community profile (sent privately)",
  "/mytasks — duties due today + open tasks assigned to you",
  "/kids — assign a teacher/caregiver to a kids programme slot (or say “put Amber on Early Readers Friday”)",
  "/kids-topic — run once inside the Parents &amp; Teachers forum topic so daily roundups post there",
  "/done &lt;item id&gt; — mark a duty/task done (duties reschedule: daily +1 day, weekly +7 days, monthly +1 month)",
  "/whoami — show your Telegram details",
  "/help — this message",
].join("\n");

async function classifyUserMessage(text, chatId, { interactive = false } = {}) {
  if (!text || !openaiLib.isEnabled()) return null;
  try {
    const cleanText = stripBotMention(text, botMe) || text;
    const contextCount = interactive ? 14 : 6;
    const earlier = memoryFor(chatId).filter((m) => m.text && m.text !== cleanText);
    const context = vavaAgent.formatTranscript(earlier, { limit: contextCount });
    const payload = context
      ? `Recent conversation (use this to resolve "it", "that", "yes", and follow-ups):\n${context}\n\nLatest message to classify:\n${cleanText}`
      : cleanText;
    const classified = await openaiLib.classifyGroupMessage(payload, todayISO());
    log(
      `AI classify: action=${classified.action} event=${classified.is_event} ` +
        `task=${classified.is_task} tasks=${classified.tasks?.length || 0} question=${classified.is_question} ` +
        `schedule_range=${classified.schedule_range || "-"} conf=${classified.confidence}`
    );
    return mergeFaqIntent(
      mergeLocationIntent(
        mergeCompletionIntent(mergeCancelEventIntent(classified, cleanText), cleanText, chatId),
        cleanText
      ),
      cleanText
    );
  } catch (err) {
    log("classifyGroupMessage error:", err.message);
    return null;
  }
}

async function handleClassifiedQuestion({ text, questionKind, from, chatId, interactive, scheduleRange, classified }) {
  const logDocGaps = interactive && LOG_DOC_GAPS;

  if (
    questionKind === "faq" ||
    faqsLib.looksLikeFaqQuestion(text) ||
    classified?.is_faq_question
  ) {
    const faqFirst = await handleFaqQuestion({ text, classified, chatId });
    if (faqFirst) return true;
  }

  if (medicalFacilities.looksLikeMedicalFacilityQuestion(text)) {
    try {
      const result = await medicalFacilities.answerMedicalQuestion(text);
      if (result.answered && result.answer) {
        await sendMessage(chatId, result.answer);
        log(`Medical directory answer: ${text.slice(0, 60)}`);
        return true;
      }
    } catch (err) {
      log("answerMedicalQuestion error:", err.message);
      if (interactive) {
        await sendMessage(
          chatId,
          "I couldn't load the medical directory. Run <code>npm run notion:setup-medical</code> if this is a new setup."
        );
        return true;
      }
    }
  }

  if (
    medicalFacilities.looksLikeMedicalFacilityRatingMessage(text) ||
    marketRatings.looksLikeMarketRatingMessage(text)
  ) {
    const routed = await routeFacilityOrMarketRating({ text, from, chatId });
    if (routed !== false) return routed;
  }

  if (questionKind === "expenses" || looksLikeExpenseQuestion(text)) {
    try {
      await sendExpenseSummary(chatId, { from });
      return true;
    } catch (err) {
      log("expense summary error:", err.message);
      if (interactive) {
        await sendMessage(chatId, "Sorry, I could not load expenses right now.");
      }
      return interactive;
    }
  }

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

  if (
    questionKind === "schedule" &&
    !faqsLib.looksLikeFaqQuestion(text) &&
    (userWantsScheduleData(text) ||
      marketRatings.looksLikeMarketRatingMessage(text) ||
      medicalFacilities.looksLikeMedicalFacilityRatingMessage(text))
  ) {
    if (
      marketRatings.looksLikeMarketRatingMessage(text) ||
      medicalFacilities.looksLikeMedicalFacilityRatingMessage(text)
    ) {
      return routeFacilityOrMarketRating({ text, from, chatId });
    }
    if (looksLikeMarketQuestion(text)) {
      try {
        const marketResult = await answerMarketQuestion(text);
        if (marketResult.answered && marketResult.answer) {
          await sendMessage(chatId, escapeHtml(marketResult.answer));
          log(`Market answer: ${text.slice(0, 60)}`);
          return true;
        }
      } catch (err) {
        log("answerMarketQuestion error:", err.message);
      }
    }
    const rangeOpts = { scheduleRange: scheduleRange || "two_weeks" };
    const calendar = buildWeekCalendarContext(todayISO(), RECAP_TZ);
    const wantsChildcareDetails = isSensitiveChildcareQuestion(text);
    const allowChildcareDetails =
      wantsChildcareDetails && isPrivateChat(chatId, from) && (await isChildMinder(from));
    if (wantsChildcareDetails && !allowChildcareDetails) {
      await sendMessage(
        chatId,
        "I can only share childcare names, allergies, and parent phone numbers in a private chat with someone on the Child minder role."
      );
      return true;
    }
    try {
      const snapshot = await buildScheduleSnapshot(text, {
        ...rangeOpts,
        includeChildcareDetails: allowChildcareDetails,
      });
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
    if (looksLikeMarketQuestion(text)) {
      try {
        const marketResult = await answerMarketQuestion(text);
        if (marketResult.answered && marketResult.answer) {
          await sendMessage(chatId, escapeHtml(marketResult.answer));
          return true;
        }
      } catch (err) {
        log("answerMarketQuestion fallback error:", err.message);
      }
    }
    if (looksLikeMarketQuestion(text)) {
      return false;
    }
    try {
      const snapshot = await buildScheduleSnapshot(text, {
        ...rangeOpts,
        includeChildcareDetails: allowChildcareDetails,
      });
      await sendMessage(chatId, `<pre>${escapeHtml(snapshot)}</pre>`);
      log(`Schedule answer (snapshot): ${text.slice(0, 60)}`);
      return true;
    } catch (err) {
      log("schedule answer error:", err.message);
    }
    return false;
  }

  if (questionKind === "faq") {
    const answered = await handleFaqQuestion({ text, classified, chatId });
    if (answered) return true;
    if (interactive) {
      await sendMessage(
        chatId,
        "I don't have that FAQ yet. Add one: “Add FAQ: your question? → short answer”"
      );
      return true;
    }
    return false;
  }

  if (questionKind === "location" || locationsLib.looksLikeLocationQuestion(text)) {
    const answered = await handleLocationQuestion({ text, classified, chatId });
    if (answered) return true;
    const docResult = await handleAsk({ question: text, from, chatId, logGap: false });
    if (docResult.answered) return true;
    if (interactive) {
      const item = locationsLib.extractLocationQuestionItem(text) || "that";
      await sendMessage(
        chatId,
        `I don't know where ${escapeHtml(item)} is yet. Tell me where it lives or where you left it — I'll put it on the Where things are page.`
      );
      return true;
    }
    return false;
  }

  if (questionKind === "documentation") {
    if (locationsLib.looksLikeLocationQuestion(text)) {
      const locHit = await handleLocationQuestion({ text, classified, chatId });
      if (locHit) return true;
    }
    const docResult = await handleAsk({ question: text, from, chatId, logGap: logDocGaps });
    if (docResult.answered) return true;
    const locAfter = await handleLocationQuestion({ text, classified, chatId });
    if (locAfter) return true;
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
  if (interactive) {
    return replyConversationally({
      text,
      chatId,
      classified,
      situation:
        "They asked something. Village docs and the schedule didn't have a clear answer. Be honest about that, then offer a useful next step — don't invent site facts.",
    });
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
  const tasks =
    Array.isArray(classified?.tasks) && classified.tasks.length
      ? classified.tasks.map((task) => withTaskDefaults(task, text))
      : classified?.task
        ? [withTaskDefaults(classified.task, text)]
        : [{ title: text || "(photo)", type: "Villager", priority: "Average" }];

  const created = [];
  const activityKind =
    classified.action === "guest_issue" ? "guest_logged" : "task_logged";
  const icon = classified.action === "guest_issue" ? "🏕️" : "📋";

  for (const task of tasks) {
    const title = task?.title || text || "(photo)";
    const key = ambientDedupeKey(chatId, "task", `${classified.action}:${title}`);
    if (wasRecentlyCaptured(key)) continue;
    try {
      const { url, boardName, project } = await createTaskFromClassification({
        classified: { ...classified, task },
        text,
        from,
        chatId,
        photoFileId: created.length === 0 ? photoFileId : null,
      });
      markCaptured(key);
      recordChatActivity({
        kind: activityKind,
        from,
        chatId,
        title,
        text,
      });
      created.push({ title, url, boardName, project, assignee: task?.assignee || null });
      log(`Task captured (${classified.action}): ${title}`);
    } catch (err) {
      log("createTaskFromClassification error:", err.message);
    }
  }

  if (!created.length) return false;

  if (created.length === 1) {
    const one = created[0];
    let reply = `${icon} Logged: <b>${escapeHtml(one.title)}</b>`;
    if (one.assignee) reply += ` → ${escapeHtml(one.assignee)}`;
    if (one.project?.name) reply += ` → project <b>${escapeHtml(one.project.name)}</b>`;
    else if (one.boardName) reply += ` (${escapeHtml(one.boardName)})`;
    if (one.url) reply += ` — <a href="${one.url}">Notion</a>`;
    await sendMessage(chatId, reply);
  } else {
    const lines = created.map((one) => {
      let line = `• <b>${escapeHtml(one.title)}</b>`;
      if (one.assignee) line += ` → ${escapeHtml(one.assignee)}`;
      if (one.project?.name) line += ` (${escapeHtml(one.project.name)})`;
      if (one.url) line += ` — <a href="${one.url}">Notion</a>`;
      return line;
    });
    await sendMessage(chatId, `${icon} Logged ${created.length} tasks:\n${lines.join("\n")}`);
  }
  return true;
}

async function captureAmbientRecurringDuty({ classified, text, from, chatId }) {
  const duties =
    Array.isArray(classified?.recurring_duties) && classified.recurring_duties.length
      ? classified.recurring_duties
      : classified?.recurring_duty
        ? [classified.recurring_duty]
        : [];
  if (!duties.length) return false;

  const { displayName } = await resolvePersonIdentity(from);
  const defaultAssignee = displayName || senderDisplayName(from);
  const sharedJob =
    duties.find((d) => d.job)?.job ||
    (/\bcleaner\b/i.test(text || "") ? "Cleaner" : null);
  const created = [];
  const today = todayISO();
  const weeklyDuties = duties.filter((d) =>
    String(d.frequency || "Daily").toLowerCase().includes("week")
  );
  const weeklyTotal = weeklyDuties.length;
  let weeklyIdx = 0;

  for (const rawDuty of duties) {
    const duty = {
      ...rawDuty,
      assignee: rawDuty.assignee || defaultAssignee,
      job: rawDuty.job || sharedJob,
    };
    if (!duty.title) continue;

    const isWeekly = String(duty.frequency || "Daily").toLowerCase().includes("week");
    const nextDueDate = isWeekly
      ? staggeredWeeklyDueDate(today, weeklyIdx, weeklyTotal)
      : today;
    if (isWeekly) weeklyIdx += 1;

    const key = ambientDedupeKey(
      chatId,
      "duty",
      `${duty.title}:${duty.frequency || "Daily"}:${duty.assignee || ""}`
    );
    if (wasRecentlyCaptured(key)) continue;

    try {
      const result = await createRecurringDutyFromClassification({
        classified: { ...classified, recurring_duty: duty },
        text,
        from,
        chatId,
        nextDueDate,
      });
      markCaptured(key);
      recordChatActivity({
        kind: "duty_logged",
        from,
        chatId,
        title: duty.title,
        text,
      });
      created.push({ duty, result });
      log(`Recurring duty captured: ${duty.title} (${result.frequency})`);
    } catch (err) {
      log("createRecurringDutyFromClassification error:", err.message);
    }
  }

  if (!created.length) return false;

  if (created.length === 1) {
    const { duty, result } = created[0];
    const verb = result.updated ? "Updated" : "Set up";
    let reply = `🔁 ${verb} recurring duty: <b>${escapeHtml(duty.title)}</b>`;
    reply += ` <i>(${escapeHtml(result.frequency || duty.frequency || "Daily")})</i>`;
    if (result.assignee) reply += ` → ${escapeHtml(result.assignee)}`;
    if (result.jobName) reply += `\nJob: <b>${escapeHtml(result.jobName)}</b>`;
    if (result.url) reply += ` — <a href="${result.url}">Notion</a>`;
    reply += `\n<i>You'll see it in /mytasks and the morning DM when it's due. Mark done with /done.</i>`;
    await sendMessage(chatId, reply);
    return true;
  }

  const lines = created.map(({ duty, result }) => {
    let line = `• <b>${escapeHtml(duty.title)}</b> <i>(${escapeHtml(result.frequency || duty.frequency || "Daily")})</i>`;
    if (result.nextDue && String(duty.frequency || "").toLowerCase().includes("week")) {
      line += ` — due <b>${escapeHtml(result.nextDue)}</b>`;
    }
    if (result.url) line += ` — <a href="${result.url}">Notion</a>`;
    return line;
  });
  const assignee = created[0].result.assignee;
  const jobName = created[0].result.jobName;
  let reply = `🔁 Set up ${created.length} recurring duties`;
  if (assignee) reply += ` for <b>${escapeHtml(assignee)}</b>`;
  if (jobName) reply += ` (${escapeHtml(jobName)})`;
  reply += `:\n${lines.join("\n")}`;
  reply += `\n<i>Daily items show every morning; weekly/monthly appear when due. Mark done with /done — it schedules the next due date.</i>`;
  await sendMessage(chatId, reply);
  return true;
}

/** Single AI-driven router for both @bot messages and ambient group messages. */
async function routeByAiClassification({ classified, text, from, chatId, photoFileId, interactive, message }) {
  if (!classified && !photoFileId && !getMessageMedia(message)) {
    if (interactive && !openaiLib.isEnabled()) {
      await sendMessage(
        chatId,
        "I need OPENAI_API_KEY to understand natural language. Use /task, /ask, or /help."
      );
      return true;
    }
    if (interactive) return false;
    return;
  }

  let c = classified || {
    action: "create_task",
    actionable: true,
    confidence: 1,
    is_task: true,
    task: { title: text },
  };
  if (
    text &&
    broadcastRemindersLib.looksLikeCommunityBroadcastReminder(text) &&
    !c.is_reminder
  ) {
    c = {
      ...c,
      action: "create_reminder",
      actionable: true,
      confidence: Math.max(c.confidence || 0, 0.88),
      is_reminder: true,
      is_add_note: false,
      reminder: {
        ...(c.reminder || {}),
        title:
          c.reminder?.title ||
          broadcastRemindersLib.inferReminderTitleFromText(text),
        notes: c.reminder?.notes || text,
      },
    };
  }
  const conf = c.confidence || 0;
  const minConf = actionMinConfidence(interactive);

  // Cheap path: personal agenda without waiting on classifier nuance
  if (interactive && text && looksLikeBotHealthPing(text)) {
    await sendMessage(
      chatId,
      "Yes — I'm here. Ask about markets (with ratings like <code>Loulé: 8</code>), today's schedule, tasks, or /help."
    );
    return;
  }

  if (interactive && text && looksLikeMetaOrSocialBotMessage(text)) {
    const ok = await replyConversationally({
      text,
      chatId,
      situation:
        "Joke or question about VAVA/the bot — reply briefly. Do NOT list markets or events.",
    });
    if (!ok) {
      await sendMessage(chatId, "No secret programming — I'm here when you @ me. 🙂");
    }
    return;
  }

  if (interactive && text && (marketRatings.looksLikeMarketRatingMessage(text) || medicalFacilities.looksLikeMedicalFacilityRatingMessage(text))) {
    await routeFacilityOrMarketRating({ text, from, chatId });
    return;
  }

  if (interactive && text && looksLikeMyDayQuestion(text)) {
    await sendMyDayBriefing(from, chatId, todayISO());
    return;
  }

  if (interactive && text && looksLikeExpenseQuestion(text)) {
    await sendExpenseSummary(chatId, { from });
    return;
  }

  if (interactive && text && looksLikeChildcareAbsenceRequest(text)) {
    await handleChildcareAbsence({ text, from, chatId });
    return;
  }

  if (interactive && text && looksLikeChildcareBookingRequest(text)) {
    await sendMessage(
      chatId,
      `You can book childcare on the website: <a href="${CHILDCARE_BOOKING_URL}">book-childcare</a>.`
    );
    return;
  }

  if (
    interactive &&
    text &&
    c.action === "childcare_absent" &&
    conf >= EVENT_MIN_CONFIDENCE
  ) {
    await handleChildcareAbsence({
      text,
      from,
      chatId,
      childName: c.childcare_absence?.child_name,
      date: c.childcare_absence?.date,
    });
    return;
  }

  if (
    interactive &&
    text &&
    (looksLikeDinnerBookingRequest(text) ||
      (c.action === "book_meal" && conf >= EVENT_MIN_CONFIDENCE))
  ) {
    await replyWithDinnerBookingLink(chatId, todayISO());
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
      classified: c,
    });
    if (interactive && !answered) {
      const ok = await replyConversationally({
        text,
        chatId,
        classified: c,
        situation:
          "They asked a question. Docs/schedule search didn't turn up a solid answer. Be honest, then help them with the next step.",
      });
      if (!ok) {
        await sendMessage(
          chatId,
          "I don't have that written down yet. Want me to log it as a job, or tell a steward?"
        );
      }
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
          ? "Got it — I'll mention that in tomorrow's morning roundup."
          : "Got it."
      );
    }
    return;
  }

  // Documentation drafts (voice brain dumps → structured workdoc)
  if (c.is_documentation && text && conf >= minConf) {
    const ok = await captureAmbientDocumentation({ text, from, chatId, interactive });
    if (!ok && interactive) {
      await sendMessage(chatId, "Sorry, I could not create that documentation.");
    }
    return;
  }

  // Receipts / expenses — private Notion table, not announced in the group recap
  if (c.is_expense || c.action === "create_expense") {
    const ok = await handleReceiptSubmission({
      message: message || { from, chat: { id: chatId }, text, caption: text },
      text,
      from,
      chatId,
      force: interactive,
      classifiedExpense: c.expense,
    });
    if (!ok && interactive) {
      await sendMessage(
        chatId,
        "I couldn't log that as a receipt. Send a photo or /receipt with the amount and shop."
      );
    }
    return;
  }

  // Mark task complete
  if (c.is_complete && c.target && conf >= minConf) {
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
  if (c.is_cancel_event && c.target && conf >= minConf) {
    try {
      const bulkQuery =
        c.target.title || extractEventCancelQuery(text || "") || "";
      const cancelAll =
        c.target.cancel_all ||
        inferCancelAllFromText(text || "") ||
        (/\b(all|every)\b/i.test(text || "") &&
          /\bevents?\b/i.test(text || "")) ||
        /\bno more\b/i.test(text || "");
      if (cancelAll && bulkQuery) {
        const result = await cancelEventsMatchingFromChat({
          query: bulkQuery,
          text,
          from,
          chatId,
        });
        await sendCancelEventOutcome(chatId, result, { query: bulkQuery, interactive });
        return;
      }

      const result = await cancelEventFromChat({ target: c.target, text, from, chatId });
      await sendCancelEventOutcome(chatId, result, {
        query: c.target.title || bulkQuery,
        interactive,
      });
    } catch (err) {
      log("cancelEventFromChat error:", err.message);
      if (interactive) await sendMessage(chatId, "Sorry, I could not cancel that event.");
    }
    return;
  }

  if (c.is_update_reminder && c.reminder && conf >= minConf) {
    try {
      let recurrence = c.reminder.recurrence;
      if (!recurrence) {
        recurrence = broadcastRemindersLib.inferWeekdaysOnlyRecurrence(text || "");
      }
      const result = await updateReminderFromChat({
        reminder: { ...c.reminder, recurrence },
        from,
        chatId,
      });
      if (result.ok) {
        let reply = `📅 Updated reminder: <b>${escapeHtml(result.name)}</b>`;
        if (result.recurrence) {
          reply += `\nSchedule: <code>${escapeHtml(result.recurrence)}</code> (not on weekends when set to weekdays).`;
        }
        await sendMessage(chatId, reply);
      } else if (result.reason === "ambiguous" && interactive) {
        await sendMessage(
          chatId,
          `Which reminder? I found: ${escapeHtml(result.matches.join(", "))}.`
        );
      } else if (result.reason === "not_found" && interactive) {
        await sendMessage(
          chatId,
          "I couldn't find an open reminder in Notion matching that. Check Calendars → Reminders (Post to Telegram)."
        );
      }
    } catch (err) {
      log("updateReminderFromChat error:", err.message);
      if (interactive) await sendMessage(chatId, "Sorry, I could not update that reminder.");
    }
    return;
  }

  // Governance
  if (c.action === "create_decision" && c.decision && conf >= minConf) {
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
  if (c.is_rsvp && c.rsvp?.title && conf >= minConf) {
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
  if (c.is_reminder && c.reminder?.title && conf >= minConf) {
    try {
      const { broadcast } = broadcastRemindersLib.enrichBroadcastReminder(
        c.reminder,
        text,
        {
          messageThreadId:
            message?.is_topic_message && message.message_thread_id
              ? message.message_thread_id
              : null,
        }
      );
      const { url, broadcast: isBroadcast } = await createReminder({
        title: c.reminder.title,
        dueDate: broadcast ? null : c.reminder.due_date,
        notes: c.reminder.notes || text,
        from,
        chatId,
        broadcast,
      });
      let reply;
      if (isBroadcast && broadcast) {
        reply = `📣 Scheduled reminder: <b>${escapeHtml(c.reminder.title)}</b>`;
        if (broadcast.recurrence) {
          reply += `\nWhen: <code>${escapeHtml(broadcast.recurrence)}</code>`;
        }
        const where = (broadcast.audiences || []).join(" + ");
        if (where) reply += `\nTo: ${escapeHtml(where)}`;
        if (broadcast.telegramChat) {
          reply += `\nChat: ${escapeHtml(broadcast.telegramChat)}`;
        }
        if (broadcast.topicId) reply += ` (topic ${broadcast.topicId})`;
        if (broadcast.memberRole) reply += `\nRole DMs: ${escapeHtml(broadcast.memberRole)}`;
        if (
          broadcast.audiences?.includes(broadcastRemindersLib.AUDIENCE_CHAT) &&
          !broadcast.chatId
        ) {
          reply +=
            "\n⚠️ Set <b>Telegram chat ID</b> in Notion or env <code>TELEGRAM_CHAT_ALIASES</code> for the Parents & Teachers group.";
        }
        reply += "\n<i>VAVA will post on those days after the Time in Notion (default morning).</i>";
      } else {
        reply = `⏰ Reminder set: <b>${escapeHtml(c.reminder.title)}</b>`;
        if (c.reminder.due_date) reply += ` (due ${escapeHtml(c.reminder.due_date)})`;
      }
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
  if (c.is_add_note && c.note?.target_title && conf >= minConf) {
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

  // FAQs — short Q&A
  if ((c.is_faq_create || c.action === "create_faq") && conf >= minConf) {
    await handleFaqCreate({ text, from, chatId, classified: c });
    return;
  }

  // Where things are — left it / lives here
  if (
    (c.is_location_update || c.action === "update_location") &&
    conf >= minConf
  ) {
    await handleLocationUpdate({ text, from, chatId, classified: c });
    return;
  }

  if (
    interactive &&
    text &&
    (c.is_community_meal_offer ||
      (communityCookOffer.looksLikeCommunityCookOffer(text) && !c.is_book_meal))
  ) {
    const offer =
      communityCookOffer.normalizeMealOffer(c.meal_offer || {}, {
        text,
        todayISO: todayISO(),
        tz: RECAP_TZ,
      }) ||
      communityCookOffer.normalizeMealOffer({}, { text, todayISO: todayISO(), tz: RECAP_TZ });
    if (offer && conf >= minConf) {
      await beginCommunityCookOfferFlow({ offer, from, chatId, text });
      return;
    }
  }

  // Off-site / email guest stays (before events — “booked the cabin” is not a workshop)
  if (c.is_stay || c.action === "create_stay") {
    const ok = await captureAmbientStay({
      classified: c,
      text,
      from,
      chatId,
      interactive,
    });
    if (ok || interactive) return;
  }

  // Events & deliveries
  if (
    c.is_event &&
    c.event &&
    conf >= (interactive ? minConf : EVENT_MIN_CONFIDENCE) &&
    !communityCookOffer.looksLikeCommunityCookOffer(text)
  ) {
    await captureAmbientEvent({ classified: c, text, from, chatId });
    return;
  }

  // Recurring duties (daily/weekly rota — not one-off Tasks)
  if (c.is_recurring_duty && c.recurring_duty && conf >= minConf) {
    const ok = await captureAmbientRecurringDuty({ classified: c, text, from, chatId });
    if (!ok && interactive) {
      await sendMessage(chatId, "Sorry, I could not set up that recurring duty.");
    }
    return;
  }

  if (c.is_kids_programme_assign && c.kids_programme_shift && conf >= minConf) {
    const ok = await captureAssignKidsProgramme({ classified: c, from, chatId });
    if (!ok && interactive) {
      await sendMessage(
        chatId,
        "Sorry, I could not update that kids programme slot. Try /kids or check the programme name."
      );
    }
    return;
  }

  // Tasks & guest issues. A photo with no caption can still become a task.
  // A photo that came with words only becomes a task when the classifier says so.
  const photoForcesTask = Boolean(photoFileId) && (!interactive || !String(text || "").trim());
  if (
    (c.is_task && c.task && conf >= minConf) ||
    (c.action === "guest_issue" && c.task && conf >= minConf) ||
    photoForcesTask
  ) {
    const taskList =
      Array.isArray(c.tasks) && c.tasks.length ? c.tasks : c.task ? [c.task] : [];
    const classified = {
      ...c,
      task: withTaskDefaults(c.task, text),
      tasks: taskList.map((task) => withTaskDefaults(task, text)),
    };
    const ok = await captureAmbientTask({ classified, text, from, chatId, photoFileId });
    if (!ok && interactive) {
      await sendMessage(chatId, "Sorry, I could not save that task.");
    }
    return;
  }

  if (interactive && text) {
    if (looksLikeQuestion(text)) {
      const docResult = await handleAsk({ question: text, from, chatId, logGap: false });
      if (docResult.answered) return true;
    }
    return false;
  }
}

async function thinkAboutMessage({ text, from, chatId, message, missedStandardAction = false }) {
  const entries = memoryFor(chatId, message ? messageThreadId(message) : undefined);
  const last = entries[entries.length - 1];
  if (text && last && !last.isBot && last.text !== text) {
    entries[entries.length - 1] = { ...last, text };
  } else if (text && (!last || last.isBot)) {
    entries.push({ name: senderDisplayName(from), text, role: "user" });
  }
  const thought = await vavaAgent.think({
    transcript: vavaAgent.formatTranscript(entries),
    todayISO: todayISO(),
    speaker: senderDisplayName(from),
    isPrivate: message?.chat?.type === "private",
    missedStandardAction,
  });
  if (thought) {
    log(
      `VAVA think: mode=${thought.mode}` +
        (thought.understanding ? ` — ${thought.understanding}` : "")
    );
  }
  return thought;
}

async function deliverAgentNotion(plan, { from, chatId, text, message }) {
  const transcript = vavaAgent.formatTranscript(memoryFor(chatId, message ? messageThreadId(message) : undefined));
  let executable = plan;
  try {
    if (!plan?.notion?.op) {
      executable = await vavaAgent.planNotion({
        transcript,
        todayISO: todayISO(),
        speaker: senderDisplayName(from),
        database: plan?.database,
        notion_request: plan?.notion_request || text,
        understanding: plan?.understanding,
      });
    }
  } catch (err) {
    log("freeform notion plan error:", err.message);
    await sendMessage(chatId, "I couldn't work that out just now. Try again in a moment?");
    return true;
  }

  if (executable?.mode === "reply" && executable.reply) {
    await sendMessage(chatId, escapeHtml(executable.reply));
    return true;
  }
  if (!executable?.notion?.op) {
    await sendMessage(
      chatId,
      "I can put that in Notion, but I need a clearer idea of what to save."
    );
    return true;
  }

  let result;
  try {
    result = await vavaAgent.executeNotionPlan(executable);
  } catch (err) {
    log("freeform notion error:", err.message);
    await sendMessage(chatId, "Notion didn't accept that. Tell me a bit more and I'll try again.");
    return true;
  }
  if (!result?.handled) {
    await sendMessage(chatId, "I couldn't do that in Notion. Want to say it another way?");
    return true;
  }
  if (result.ok && result.pageId) {
    markPageSeen(result.pageId);
    recordChatActivity({
      kind: "announcement",
      from,
      chatId,
      title: result.title || plan.understanding,
      text,
    });
  }
  let spoken = "";
  try {
    spoken = await vavaAgent.phraseReply({
      transcript,
      facts: result.reply || "",
      speaker: senderDisplayName(from),
      todayISO: todayISO(),
    });
  } catch (err) {
    log("phraseReply error:", err.message);
  }
  const body = escapeHtml(spoken || result.reply || "Done.");
  const reply = result.url ? `${body} — <a href="${result.url}">Notion</a>` : body;
  await sendMessage(chatId, reply);
  log(
    `Freeform Notion ${result.ok ? result.op : result.reason}: ${result.database || ""} ${result.title || ""}`
  );
  return true;
}

// ── VAVA tool agent (tagged messages + DMs) ──────────────────────────────────
// GPT reads the message and decides: chat, call one of these tools, or hand off.
// Privacy rules live inside the tools, not in the prompt.

const VAVA_AGENT_MODE = String(process.env.VAVA_AGENT || "tools").trim().toLowerCase();
const ISO_DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

function agentDate(value) {
  const s = String(value || "").trim();
  return ISO_DATE_RE.test(s) ? s : todayISO();
}

function agentScheduleWindow({ range, date }) {
  const today = todayISO();
  if (ISO_DATE_RE.test(String(date || ""))) return { start: date, end: date, label: date };
  if (range === "tomorrow") {
    const d = addDaysISO(today, 1);
    return { start: d, end: d, label: `Tomorrow (${d})` };
  }
  return resolveScheduleRange(range || "today", today, RECAP_TZ);
}

function marketForAgent(m, today) {
  const rule = m.recurrenceText ? parseRecurrence(m.recurrenceText) : null;
  let next = null;
  if (rule) {
    try {
      next = nextOccurrence(rule, today);
    } catch {
      next = null;
    }
  }
  return {
    place: m.place,
    kind: m.kind === regionalMarkets.MARKET_KIND.FLEA ? "flea market" : "farmers / gypsy market",
    venue: m.locale || null,
    hours: m.hours || null,
    schedule: rule ? describeRecurrence(rule) : m.date || null,
    next_date: next || null,
    rating: m.ratingCount ? `${m.ratingAvg}/10 from ${m.ratingCount}` : null,
  };
}

function buildAgentTools({ message, text, photoFileId, media }) {
  const chatId = message.chat.id;
  const from = message.from;
  const isPrivate = isPrivateChat(chatId, from);
  const today = todayISO();
  const dateParam = { type: "string", description: "YYYY-MM-DD; omit for today" };

  const dedupe = (type, title) => {
    const key = ambientDedupeKey(chatId, type, title);
    return { seen: wasRecentlyCaptured(key), mark: () => markCaptured(key) };
  };

  return [
    // ── Read ──
    {
      name: "get_schedule",
      description:
        "Village calendar: events (internal + external), and for single days also chores rota, community cooking offers and campsite meal counts. Does NOT include markets (use find_markets).",
      parameters: {
        type: "object",
        properties: {
          range: { type: "string", enum: ["today", "tomorrow", "this_week", "next_week", "two_weeks"] },
          date: { type: "string", description: "Specific day YYYY-MM-DD (overrides range)" },
        },
      },
      run: async (args) => {
        const win = agentScheduleWindow(args);
        const events = (await fetchEventsBetween(win.start, win.end)).map((e) => ({
          date: e.date,
          title: e.title,
          type: e.type || null,
          recurrence: e.recurrence || null,
        }));
        const out = { window: win.label, events };
        if (win.start === win.end) {
          const day = win.start;
          out.morning_meeting_theme = morningMeetingThemeForDate(day) || null;
          out.chores = (await fetchRotaForDay(day)).map((c) => ({ chore: c.chore, assignee: c.assignee }));
          out.community_cooking = await fetchCommunityCookOffersForDay(day);
          const meals = await fetchCampsiteMealsForDay(day);
          if (meals) {
            out.campsite_meals = {
              breakfast_bookings: meals.breakfastCook,
              dinner_bookings: meals.dinnerCook,
              weekday_dinner: isWeekdayDinnerDay(day, meals),
            };
          }
        }
        return out;
      },
    },
    {
      name: "find_markets",
      description:
        "Algarve/Odemira markets: which are on a date, where one is (venue), when one runs, its rating. Flea markets and farmers/gypsy markets are separate kinds — keep them in separate lists when replying.",
      parameters: {
        type: "object",
        properties: {
          date: { type: "string", description: "YYYY-MM-DD to list markets on that day" },
          place: { type: "string", description: "Town or market name, e.g. Loulé, Barão de São João" },
          kind: { type: "string", enum: ["flea", "farmers"] },
        },
      },
      run: async ({ date, place, kind }) => {
        const catalog = await loadMarketCatalog();
        let pool = place ? regionalMarkets.findMarketsMatchingText(`market at ${place}`, catalog) : catalog;
        if (kind) pool = pool.filter((m) => m.kind === kind);
        const day = date && ISO_DATE_RE.test(date) ? date : !place ? today : null;
        if (day) pool = pool.filter((m) => eventMatchesDate(m.date, m.recurrenceText, day));
        return {
          date: day,
          place_searched: place || null,
          total: pool.length,
          markets: pool.slice(0, 40).map((m) => marketForAgent(m, today)),
        };
      },
    },
    {
      name: "search_docs",
      description: "Search village documentation (rules, how-tos, policies, guides) and answer from it.",
      parameters: {
        type: "object",
        properties: { question: { type: "string" } },
        required: ["question"],
      },
      run: async ({ question }) => {
        const r = await answerQuestion(question);
        if (!r.available || !r.indexed) return { found: false, reason: "docs search unavailable" };
        if (!r.answered) return { found: false };
        return {
          found: true,
          answer: r.answer,
          links: (r.sources || []).slice(0, 3).map((s) => ({ title: s.name, url: s.url })),
        };
      },
    },
    {
      name: "faq_lookup",
      description: "Look up the village FAQs (addresses, wifi, practical answers). list_all=true lists them.",
      parameters: {
        type: "object",
        properties: { question: { type: "string" }, list_all: { type: "boolean" } },
      },
      run: async ({ question, list_all }) => {
        const r = await faqsLib.answerFaqQuestion({ question: question || text, listAll: list_all });
        if (r.list) return { list: r.snapshot };
        if (r.ok && r.faq) {
          return { answer: faqsLib.formatFaqAnswer(r.faq), url: r.faq.url, linkTitle: "FAQ" };
        }
        if (r.reason === "ambiguous") return { ambiguous: (r.matches || []).map((m) => m.question) };
        return { found: false };
      },
    },
    {
      name: "where_is",
      description: "Where a shared item/tool/key is kept on site (Where things are). list_all=true lists everything.",
      parameters: {
        type: "object",
        properties: { item: { type: "string" }, area: { type: "string" }, list_all: { type: "boolean" } },
      },
      run: async ({ item, area, list_all }) => {
        const r = await locationsLib.answerLocationQuestion({ item, area, listAll: list_all, question: item || text });
        if (r.list) return { list: r.snapshot };
        if (r.ok && r.item) {
          return { answer: locationsLib.formatLocationAnswer(r.item), url: r.item.url, linkTitle: r.item.name || "Notion" };
        }
        if (r.reason === "ambiguous") return { ambiguous: (r.matches || []).map((m) => m.name) };
        return { found: false };
      },
    },
    {
      name: "booking_links",
      description: "Links to book weekday community dinners (Mon–Fri) or childcare. The links are attached to your reply automatically.",
      parameters: { type: "object", properties: {} },
      run: async () => ({
        note: "Links will be attached under your reply.",
        links: [
          { title: "Book dinner", url: DINNER_BOOKING_URL },
          { title: "Book childcare", url: CHILDCARE_BOOKING_URL },
        ],
      }),
    },
    {
      name: "childcare",
      description: "Childcare headcount for a day. The child roster is only returned in a DM with a child minder.",
      parameters: { type: "object", properties: { date: dateParam } },
      run: async ({ date }) => {
        const c = await fetchChildcareForDay(agentDate(date));
        const canSeeRoster = isPrivate && (await isChildMinder(from));
        return {
          summary: formatChildcareLine(c),
          ...(canSeeRoster
            ? { roster: c?.roster || "" }
            : { privacy: "Children's names and details are only shared with child minders in a DM." }),
        };
      },
    },
    {
      name: "kids_programme",
      description: "Kids programme shifts (who is teaching what) for a day.",
      parameters: { type: "object", properties: { date: dateParam } },
      run: async ({ date }) => {
        const shifts = await fetchKidsProgrammeShiftsForDay(agentDate(date));
        return { shifts: shifts.length ? kidsProgrammeLib.formatKidsProgrammeBlock(shifts) : "No shifts." };
      },
    },
    {
      name: "campsite_activity",
      description: "Campsite check-ins, check-outs and pitches occupied. Management only, DM only.",
      parameters: { type: "object", properties: { date: dateParam } },
      run: async ({ date }) => {
        if (!(await isManagementMember(from))) return { error: "Only management can see campsite guests." };
        if (!isPrivate) return { error: "Guest details are only shared in a DM — ask me privately." };
        const a = await fetchCampsiteActivityForDay(agentDate(date));
        return {
          checking_in: (a.arrivals || []).map((b) => b.line),
          checking_out: (a.departures || []).map((b) => b.line),
          on_site: (a.staying || []).map((b) => b.line),
        };
      },
    },
    {
      name: "notion_describe_database",
      description: "Show a Notion database's fields and recent row titles. Use before notion_write if unsure of field names.",
      parameters: {
        type: "object",
        properties: { database: { type: "string" } },
        required: ["database"],
      },
      run: async ({ database }) => {
        const db = vavaAgent.resolveDatabaseName(database);
        if (!db) return { error: `Unknown database. Options: ${vavaAgent.WRITE_DATABASES.join(", ")}` };
        const ctx = await vavaAgent.loadDatabaseContext(db);
        return { database: db, schema: ctx.schema, recent_titles: ctx.titles };
      },
    },
    {
      name: "notion_search",
      description: "Read rows from any village Notion database (projects, partnerships, jobs, members' roles, etc.). Private fields are hidden.",
      parameters: {
        type: "object",
        properties: {
          database: { type: "string" },
          query: { type: "string", description: "Words to match in row titles; omit for recent rows" },
        },
        required: ["database"],
      },
      run: async ({ database, query }) => {
        const db = vavaAgent.resolveDatabaseName(database);
        if (!db) return { error: `Unknown database. Options: ${vavaAgent.WRITE_DATABASES.join(", ")}` };
        const found = await vavaAgent.findPages(db, query || "");
        const pool = found.matches?.length ? found.matches : query ? [] : found.all;
        return {
          database: db,
          matched: Boolean(found.matches?.length),
          rows: pool.slice(0, 15).map((p) => vavaAgent.summarizePage(p)),
        };
      },
    },

    // ── Write ──
    {
      name: "rate_market",
      description: "Save someone's 1–10 rating of a market (call once per market). Returns the new average.",
      writes: true,
      parameters: {
        type: "object",
        properties: {
          place: { type: "string" },
          score: { type: "integer", minimum: 1, maximum: 10 },
          comment: { type: "string" },
        },
        required: ["place", "score"],
      },
      run: async ({ place, score, comment }) => {
        const n = Math.round(Number(score));
        if (!(n >= 1 && n <= 10)) return { error: "Score must be 1–10." };
        const catalog = await loadMarketCatalog();
        const target = marketRatings.resolveRatingTarget(String(place || ""), catalog);
        try {
          const agg = await marketRatings.recordMarketRating({
            place: target.place,
            placeKey: target.placeKey,
            score: n,
            reviewer: senderDisplayName(from),
            comment: comment || "",
          });
          return {
            saved: true,
            market: target.place,
            known_market: !target.unknownPlace,
            average: agg.avg,
            ratings: agg.count,
            done: `Rated ${target.place} ${n}/10`,
          };
        } catch (err) {
          return { error: `Couldn't save the rating (${err.message}). The Market ratings database may not be set up yet.` };
        }
      },
    },
    {
      name: "create_task",
      description: "Add a task to the Tasks board.",
      writes: true,
      parameters: {
        type: "object",
        properties: {
          title: { type: "string" },
          type: { type: "string", enum: TASK_TYPES },
          priority: { type: "string", enum: TASK_PRIORITIES },
          assignee: { type: "string" },
          project: { type: "string" },
        },
        required: ["title"],
      },
      run: async ({ title, type, priority, assignee, project }) => {
        const d = dedupe("task", title);
        if (d.seen) return { duplicate: true, note: "Already added a moment ago." };
        const created = await createTaskFromClassification({
          classified: { task: { title, type, priority, assignee, project } },
          text,
          from,
          chatId,
          photoFileId,
        });
        d.mark();
        return {
          created: true,
          title,
          project: created.project?.name || null,
          url: created.url,
          linkTitle: title,
          done: `Added task “${title}”`,
        };
      },
    },
    {
      name: "create_recurring_duty",
      description: "Add (or update) a recurring duty, linked to a Job.",
      writes: true,
      parameters: {
        type: "object",
        properties: {
          title: { type: "string" },
          frequency: { type: "string", enum: DUTY_FREQUENCIES },
          job: { type: "string" },
          assignee: { type: "string" },
        },
        required: ["title", "frequency"],
      },
      run: async ({ title, frequency, job, assignee }) => {
        const r = await createRecurringDutyFromClassification({
          classified: { recurring_duty: { title, frequency, job, assignee } },
          text,
          from,
          chatId,
        });
        return {
          ok: true,
          updated_existing: Boolean(r.updated),
          job: r.jobName,
          frequency: r.frequency,
          assignee: r.assignee,
          next_due: r.nextDue,
          url: r.url,
          linkTitle: title,
          done: `${r.updated ? "Updated" : "Added"} duty “${title}”`,
        };
      },
    },
    {
      name: "complete_task",
      description: "Mark a task / duty / event as done.",
      writes: true,
      parameters: { type: "object", properties: { title: { type: "string" } }, required: ["title"] },
      run: async ({ title }) => {
        const r = await completeTaskFromChat({ target: { title }, text, from, chatId });
        if (r.reason === "ambiguous") return { ambiguous: r.matches.map((m) => m.name) };
        if (!r.ok) return { error: "Not found." };
        return { ok: true, name: r.name, done: `Marked “${r.name || title}” done` };
      },
    },
    {
      name: "create_event",
      description: "Add an event to the calendar. Internal = village-only; external = public/outside.",
      writes: true,
      parameters: {
        type: "object",
        properties: {
          title: { type: "string" },
          date: { type: "string", description: "YYYY-MM-DD (first occurrence)" },
          event_type: { type: "string", enum: EVENT_TYPES },
          recurrence: { type: "string", description: "e.g. weekly:Sat, monthly:1st-Sun, monthly:last-Fri" },
          scope: { type: "string", enum: ["internal", "external"] },
        },
        required: ["title", "date"],
      },
      run: async ({ title, date, event_type, recurrence, scope }) => {
        if (!ISO_DATE_RE.test(String(date || ""))) return { error: "date must be YYYY-MM-DD" };
        const d = dedupe("event", `${title}|${date}`);
        if (d.seen) return { duplicate: true };
        const r = await createEvent({
          title,
          date,
          eventType: event_type,
          recurrence,
          scope,
          from,
          chatId,
          sourceText: text,
        });
        d.mark();
        return {
          created: true,
          date: r.date,
          calendar: r.databaseName,
          recurrence: r.recurrenceLabel,
          url: r.url,
          linkTitle: title,
          done: `Added “${title}” on ${r.date}`,
        };
      },
    },
    {
      name: "cancel_event",
      description:
        "Cancel event(s). One match cancels directly. all_matching=true cancels every match but first returns needs_confirmation with the list — confirm with the person, then call again with confirmed=true.",
      writes: true,
      parameters: {
        type: "object",
        properties: {
          title: { type: "string" },
          all_matching: { type: "boolean" },
          confirmed: { type: "boolean" },
        },
        required: ["title"],
      },
      run: async ({ title, all_matching, confirmed }) => {
        if (all_matching) {
          const matches = await findEventsBySearchQuery(title);
          if (!matches.length) return { error: "No matching events." };
          if (!confirmed) {
            return { needs_confirmation: true, would_cancel: matches.map((m) => m.name) };
          }
          const r = await cancelEventsMatchingFromChat({ query: title, text, from, chatId });
          if (!r.ok) return { error: "Not found." };
          return { ok: true, cancelled: r.names, done: `Cancelled ${r.names.length} event(s)` };
        }
        const r = await cancelEventFromChat({ target: { title }, text, from, chatId });
        if (r.reason === "ambiguous") {
          return { ambiguous: r.matches.map((m) => m.name), hint: "Ask which one, or use all_matching." };
        }
        if (!r.ok) return { error: "Not found." };
        return { ok: true, cancelled: r.name, done: `Cancelled “${r.name || title}”` };
      },
    },
    {
      name: "rsvp_event",
      description: "RSVP someone (default: the speaker) to an event.",
      writes: true,
      parameters: {
        type: "object",
        properties: { event_title: { type: "string" }, name: { type: "string" } },
        required: ["event_title"],
      },
      run: async ({ event_title, name }) => {
        const r = await rsvpEvent({ title: event_title, displayName: name || senderDisplayName(from), from, chatId });
        if (!r.ok) return { error: "Event not found." };
        return { ok: true, event: r.name, already_going: r.already, done: `RSVP’d to ${r.name}` };
      },
    },
    {
      name: "create_reminder",
      description:
        "Create a reminder. post_to_telegram=true makes VAVA post it in this chat on the due date (and on the recurrence if given).",
      writes: true,
      parameters: {
        type: "object",
        properties: {
          title: { type: "string" },
          due_date: { type: "string", description: "YYYY-MM-DD" },
          notes: { type: "string" },
          post_to_telegram: { type: "boolean" },
          recurrence: { type: "string", description: "e.g. weekly:Mon, monthly:1st-Fri" },
        },
        required: ["title"],
      },
      run: async ({ title, due_date, notes, post_to_telegram, recurrence }) => {
        const d = dedupe("reminder", title);
        if (d.seen) return { duplicate: true };
        const broadcast = post_to_telegram
          ? {
              postToTelegram: true,
              recurrence: recurrence ? normalizeRecurrenceField(recurrence).canonical || recurrence : undefined,
              chatId: String(chatId),
              topicId: messageThreadId(message) || undefined,
            }
          : null;
        const r = await createReminder({
          title,
          dueDate: ISO_DATE_RE.test(String(due_date || "")) ? due_date : null,
          notes,
          from,
          chatId,
          broadcast,
        });
        d.mark();
        return { created: true, will_post: r.broadcast, url: r.url, linkTitle: title, done: `Reminder “${title}” saved` };
      },
    },
    {
      name: "update_reminder",
      description: "Change an existing reminder's recurrence or notes.",
      writes: true,
      parameters: {
        type: "object",
        properties: { match_title: { type: "string" }, recurrence: { type: "string" }, notes: { type: "string" } },
        required: ["match_title"],
      },
      run: async ({ match_title, recurrence, notes }) => {
        const r = await updateReminderFromChat({ reminder: { match_title, recurrence, notes }, from, chatId });
        if (r.reason === "ambiguous") return { ambiguous: r.matches };
        if (!r.ok) return { error: r.reason || "Not found." };
        return { ok: true, name: r.name, recurrence: r.recurrence, done: `Updated reminder “${r.name}”` };
      },
    },
    {
      name: "add_note",
      description: "Append a note to an existing task, project, job or event page.",
      writes: true,
      parameters: {
        type: "object",
        properties: { target_title: { type: "string" }, note: { type: "string" } },
        required: ["target_title", "note"],
      },
      run: async ({ target_title, note }) => {
        const r = await addNoteToPage({ targetTitle: target_title, noteText: note, from, chatId });
        if (!r.ok) return { error: "Page not found." };
        return { ok: true, page: r.name, board: r.dbName, done: `Added a note to “${r.name}”` };
      },
    },
    {
      name: "set_location",
      description: "Record where a shared item is now (or usually lives, or that it's missing).",
      writes: true,
      parameters: {
        type: "object",
        properties: {
          item: { type: "string" },
          location: { type: "string" },
          usual_place: { type: "string" },
          update_kind: { type: "string", enum: LOCATION_UPDATE_KINDS },
          notes: { type: "string" },
        },
        required: ["item"],
      },
      run: async ({ item, location, usual_place, update_kind, notes }) => {
        const r = await locationsLib.upsertLocation({
          item,
          location,
          usualPlace: usual_place,
          updateKind: update_kind,
          notes,
          fromName: senderDisplayName(from),
          todayISO: today,
          sourceText: text,
        });
        if (r.reason === "ambiguous") return { ambiguous: (r.matches || []).map((m) => m.name) };
        if (!r.ok) return { error: "Couldn't save it." };
        return {
          ok: true,
          name: r.name,
          created: r.created,
          current: r.current,
          usual: r.usual,
          missing: r.missing,
          url: r.url,
          linkTitle: r.name,
          done: `Saved where ${r.name} is`,
        };
      },
    },
    {
      name: "add_faq",
      description: "Add or update a village FAQ.",
      writes: true,
      parameters: {
        type: "object",
        properties: { question: { type: "string" }, answer: { type: "string" }, category: { type: "string" } },
        required: ["question", "answer"],
      },
      run: async ({ question, answer, category }) => {
        const r = await faqsLib.upsertFaq({
          question,
          answer,
          category,
          fromName: senderDisplayName(from),
          sourceText: text,
        });
        if (r.reason === "ambiguous") return { ambiguous: (r.matches || []).map((m) => m.question) };
        if (!r.ok) return { error: "Couldn't save the FAQ." };
        return { ok: true, name: r.name, created: r.created, url: r.url, linkTitle: "FAQ", done: `Saved FAQ “${r.name}”` };
      },
    },
    {
      name: "create_doc",
      description: "Save a piece of documentation (rules, how-to, process) to the Documentation database.",
      writes: true,
      parameters: {
        type: "object",
        properties: { content: { type: "string", description: "The full text to document" } },
        required: ["content"],
      },
      run: async ({ content }) => {
        const r = await createDocumentation({ rawText: content, from, chatId, sourceText: text });
        const title = r.structured?.title || "Documentation";
        return { ok: true, title, url: r.doc?.url, linkTitle: title, done: `Saved doc “${title}”` };
      },
    },
    {
      name: "notion_write",
      description:
        "Generic Notion create/update for anything without a specific tool (projects, partnerships, jobs, members' roles…). op=create adds a row; op=update sets fields on the row matching `match`; op=note appends body text. Never deletes. Properties are field name → value strings.",
      writes: true,
      parameters: {
        type: "object",
        properties: {
          database: { type: "string" },
          op: { type: "string", enum: ["create", "update", "note"] },
          title: { type: "string", description: "Row title for create" },
          match: { type: "string", description: "Title of the row to update / note" },
          properties: { type: "object", additionalProperties: { type: "string" } },
          body: { type: "string", description: "Text to add to the page body" },
        },
        required: ["database", "op"],
      },
      run: async ({ database, op, title, match, properties, body }) => {
        const db = vavaAgent.resolveDatabaseName(database);
        if (!db) return { error: `Unknown database. Options: ${vavaAgent.WRITE_DATABASES.join(", ")}` };
        const r = await vavaAgent.executeNotionPlan({
          notion: { op, database: db, title, match, properties: properties || {}, body },
        });
        if (!r.ok) return { error: r.reply || r.reason || "Couldn't write to Notion." };
        return { ok: true, result: r.reply, url: r.url, linkTitle: r.title || db, done: r.reply };
      },
    },

    // ── Hand-offs: these send their own reply ──
    {
      name: "my_day",
      description: "Send the speaker their personal briefing (their chores, duties, tasks, events) for today. Replies itself.",
      parameters: { type: "object", properties: { date: dateParam } },
      run: async ({ date }) => {
        await sendMyDayBriefing(from, chatId, agentDate(date));
        return { handedOff: true };
      },
    },
    {
      name: "expenses_summary",
      description: "Send the expenses summary (spend totals, what's owed). Replies itself.",
      parameters: { type: "object", properties: {} },
      run: async () => {
        await sendExpenseSummary(chatId, { from });
        return { handedOff: true };
      },
    },
    {
      name: "log_receipt",
      description: "Log an expense/receipt from the attached photo/PDF or the amount + shop in the message. Replies itself.",
      parameters: { type: "object", properties: {} },
      run: async () => {
        const logged = await handleReceiptSubmission({
          message,
          text,
          from,
          chatId,
          force: true,
          assumeReceipt: Boolean(media),
        });
        if (!logged) return { error: "Couldn't read a receipt — ask for a photo, or the amount and shop." };
        return { handedOff: true };
      },
    },
    {
      name: "childcare_absence",
      description: "Mark a child absent from childcare for a day. Replies itself.",
      parameters: {
        type: "object",
        properties: { child_name: { type: "string" }, date: dateParam },
      },
      run: async ({ child_name, date }) => {
        await handleChildcareAbsence({ text, from, chatId, childName: child_name, date: agentDate(date) });
        return { handedOff: true };
      },
    },
    {
      name: "use_legacy_router",
      description:
        "Hand the message to VAVA's older specialised handlers. Use ONLY for: logging a guest stay / campsite booking, assigning kids programme teachers, or someone offering to cook for the community. Replies itself.",
      parameters: { type: "object", properties: {} },
      run: async () => {
        await routeAddressedMessageLegacy(message);
        return { handedOff: true };
      },
    },
  ];
}

function buildAgentContext({ message, text, media }) {
  const chatId = message.chat.id;
  const isPrivate = message.chat?.type === "private";
  const today = todayISO();
  const dayName = new Intl.DateTimeFormat("en-GB", { timeZone: RECAP_TZ, weekday: "long" }).format(
    new Date(`${today}T12:00:00`)
  );
  const earlier = memoryFor(chatId, messageThreadId(message)).filter(
    (m) => m.messageId !== message.message_id
  );
  const transcript = vavaAgent.formatTranscript(earlier, { limit: 20 });
  const reply = message.reply_to_message;
  const replyText = reply ? (reply.text || reply.caption || "").trim() : "";

  return [
    `SPEAKER: ${senderDisplayName(message.from)} (${senderHandle(message.from)})`,
    `CHAT: ${isPrivate ? "private DM with you" : `group chat “${message.chat.title || "group"}”`}`,
    `TODAY: ${today} (${dayName}), ${RECAP_TZ}`,
    buildWeekCalendarContext(today, RECAP_TZ),
    media ? `ATTACHMENT: ${media.kind}${media.filename ? ` (${media.filename})` : ""}` : "",
    replyText
      ? `THEY ARE REPLYING TO ${reply.from?.is_bot ? "YOU" : senderDisplayName(reply.from)}: ${replyText.slice(0, 600)}`
      : "",
    `CONVERSATION SO FAR (oldest first; VAVA is you):\n${transcript || "(nothing earlier)"}`,
    `LATEST MESSAGE:\n${text || "(no text)"}`,
  ]
    .filter(Boolean)
    .join("\n\n");
}

function agentLinksHtml(links) {
  const seen = new Set();
  const unique = [];
  for (const l of links || []) {
    if (!l?.url || seen.has(l.url)) continue;
    seen.add(l.url);
    unique.push(l);
  }
  return unique
    .slice(0, 4)
    .map((l) => `<a href="${escapeHtml(l.url)}">${escapeHtml(l.title || "Notion")}</a>`)
    .join(" · ");
}

const AGENT_PRICES_PER_M = {
  "gpt-6.1-sol": { input: 2, output: 10, cached: 0.1 },
  "gpt-6-luna": { input: 0.1, output: 0.5, cached: 0.1 },
};

function approxAgentCostUsd(usage) {
  const prices = AGENT_PRICES_PER_M[openaiLib.AGENT_MODEL] || AGENT_PRICES_PER_M["gpt-6.1-sol"];
  const inRate = Number(process.env.VAVA_AGENT_INPUT_PER_M || prices.input);
  const outRate = Number(process.env.VAVA_AGENT_OUTPUT_PER_M || prices.output);
  const cachedRate = Number(process.env.VAVA_AGENT_CACHED_PER_M || prices.cached);
  const fresh = Math.max(0, usage.prompt - usage.cached);
  return (fresh * inRate + usage.cached * cachedRate + usage.completion * outRate) / 1e6;
}

/** Returns true if the agent replied (or a hand-off tool did); false → caller uses the legacy router. */
async function routeViaToolAgent({ message, text, photoFileId, media }) {
  const chatId = message.chat.id;
  telegram("sendChatAction", { chat_id: chatId, action: "typing" }).catch(() => {});
  const started = Date.now();
  const result = await vavaToolAgent.runToolAgent({
    tools: buildAgentTools({ message, text, photoFileId, media }),
    context: buildAgentContext({ message, text, media }),
    databases: vavaAgent.WRITE_DATABASES,
    log,
  });

  const tools = result.trace.map((t) => `${t.tool}${t.ok ? "" : "✗"}`).join(",") || "none";
  log(
    `VAVA agent: ${result.rounds} round(s), tools=[${tools}], tokens in=${result.usage.prompt} ` +
      `(cached ${result.usage.cached}) out=${result.usage.completion}, ~$${approxAgentCostUsd(result.usage).toFixed(4)}, ` +
      `${Date.now() - started}ms${result.error ? `, error: ${result.error}` : ""}`
  );

  if (result.handedOff) return true;

  const writes = result.trace.filter((t) => t.wrote);
  let reply = String(result.reply || "").trim();
  if (!reply && writes.length) {
    reply = `Done: ${writes.map((t) => t.summary || t.tool).join("; ")}.`;
  }
  if (!reply) return false;

  let html = escapeHtml(reply);
  const links = agentLinksHtml(result.links);
  if (links) html += `\n${links}`;
  await sendMessage(chatId, html);
  return true;
}

/** Text (incl. voice transcription), photo, and attachment for a message addressed to VAVA. */
async function resolveAddressedInput(message) {
  const chatId = message.chat.id;
  const media = getMessageMedia(message);
  const photoFileId =
    message.photo && message.photo.length
      ? message.photo[message.photo.length - 1].file_id
      : media?.kind === "image"
        ? media.fileId
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
  return { text, media, photoFileId };
}

async function routeAddressedMessage(message) {
  if (VAVA_AGENT_MODE !== "legacy" && openaiLib.isEnabled()) {
    try {
      const input = await resolveAddressedInput(message);
      const chatId = message.chat.id;
      if (input.text && medicalFacilities.looksLikeMedicalFacilityQuestion(input.text)) {
        const result = await medicalFacilities.answerMedicalQuestion(input.text);
        if (result.answered && result.answer) {
          await sendMessage(chatId, result.answer);
          log(`Medical directory answer (pre-agent): ${input.text.slice(0, 80)}`);
          return;
        }
      }
      if (input.text || input.media || input.photoFileId) {
        const handled = await routeViaToolAgent({ message, ...input });
        if (handled) return;
      }
    } catch (err) {
      log("VAVA agent error — using legacy router:", err.message);
    }
  }
  return routeAddressedMessageLegacy(message);
}

async function routeAddressedMessageLegacy(message) {
  const chatId = message.chat.id;
  const from = message.from;
  let { text, media, photoFileId } = await resolveAddressedInput(message);

  if (!text && !photoFileId && !media) {
    await sendMessage(chatId, "Hey — I’m here. What’s up?");
    return;
  }

  if (text && looksLikeBotHealthPing(text)) {
    await sendMessage(
      chatId,
      "Yes — I'm here. Ask about markets (rate with <code>Place: 7</code>, 1–10), the schedule, or /help."
    );
    return;
  }

  if (text && (marketRatings.looksLikeMarketRatingMessage(text) || medicalFacilities.looksLikeMedicalFacilityRatingMessage(text))) {
    await routeFacilityOrMarketRating({ text, from, chatId });
    return;
  }

  if (text && medicalFacilities.looksLikeMedicalFacilityQuestion(text)) {
    try {
      const result = await medicalFacilities.answerMedicalQuestion(text);
      if (result.answered && result.answer) {
        await sendMessage(chatId, result.answer);
        log(`Medical directory answer: ${text.slice(0, 80)}`);
        return;
      }
    } catch (err) {
      log("answerMedicalQuestion error:", err.message);
    }
  }

  const isPrivate = message.chat?.type === "private";
  const assumeReceipt = isPrivate && Boolean(media);
  const receiptWords = /\b(receipt|recibo|factura|fatura|invoice|€|eur|euro|euros)\b/i.test(text || "");
  const tryReceipt =
    assumeReceipt ||
    (media && !text) ||
    (media && looksLikeReceiptIntent(text)) ||
    (looksLikeReceiptIntent(text) && (!openaiLib.isEnabled() || receiptWords));
  if (tryReceipt) {
    const logged = await handleReceiptSubmission({
      message,
      text,
      from,
      chatId,
      force: assumeReceipt || (!openaiLib.isEnabled() && looksLikeReceiptIntent(text)),
      assumeReceipt,
    });
    if (logged) return;
  }

  if (text && messageRequestsNotionRemoval(text)) {
    await sendMessage(
      chatId,
      "I can’t delete or archive things in Notion — only add or update. Happy to mark something done, cancel an event, or add a note if that helps."
    );
    return;
  }

  if (text) {
    const weekdayRec = broadcastRemindersLib.inferWeekdaysOnlyRecurrence(text);
    if (weekdayRec) {
      const matchTitle = /\bmorning\s+meeting/i.test(text) ? "morning meeting" : "morning";
      try {
        const result = await updateReminderFromChat({
          reminder: { match_title: matchTitle, recurrence: weekdayRec },
          from,
          chatId,
        });
        if (result.ok) {
          await sendMessage(
            chatId,
            `📅 Updated <b>${escapeHtml(result.name)}</b> — reminder is now <b>Monday–Friday only</b> (<code>${escapeHtml(
              result.recurrence || weekdayRec
            )}</code>), not weekends.`
          );
          return;
        }
        if (result.reason === "ambiguous") {
          await sendMessage(
            chatId,
            `Which reminder should be weekdays only? I found: ${escapeHtml(result.matches.join(", "))}.`
          );
          return;
        }
        if (result.reason === "not_found" && /\bmorning\s+meeting/i.test(text)) {
          await sendMessage(
            chatId,
            "The daily group recap already skips morning-meeting themes on weekends. I couldn't find a separate <b>Reminders</b> row in Notion to change — if you use a Telegram broadcast for morning meeting, add or name it in Calendars → Reminders (tick Post to Telegram), then tell me again."
          );
          return;
        }
      } catch (err) {
        log("weekday reminder tweak error:", err.message);
      }
    }

    if (looksLikeEventCancellationRequest(text)) {
      const query = extractEventCancelQuery(text);
      const cancelAll = inferCancelAllFromText(text);
      if (query) {
        try {
          if (cancelAll) {
            const result = await cancelEventsMatchingFromChat({ query, text, from, chatId });
            await sendCancelEventOutcome(chatId, result, { query, interactive: true });
            return;
          }
          const result = await cancelEventFromChat({
            target: { title: query },
            text,
            from,
            chatId,
          });
          await sendCancelEventOutcome(chatId, result, { query, interactive: true });
          return;
        } catch (err) {
          log("cancel events error:", err.message);
          await sendMessage(chatId, "Sorry, I could not cancel that event.");
          return;
        }
      }
    }
  }

  if (!(text && openaiLib.isEnabled())) {
    if (text && looksLikeMyDayQuestion(text)) {
      try {
        await sendMyDayBriefing(from, chatId, todayISO());
      } catch (err) {
        log("my day briefing error:", err.message);
        await sendMessage(chatId, "Sorry, I could not build your day list right now.");
      }
      return;
    }

    if (text && looksLikeExpenseQuestion(text)) {
      await sendExpenseSummary(chatId, { from });
      return;
    }

    if (text && locationsLib.looksLikeLocationQuestion(text)) {
      const classified = mergeLocationIntent(null, text);
      const answered = await handleLocationQuestion({ text, classified, chatId });
      if (answered) return;
    }
  }

  let understood = null;
  if (text && openaiLib.isEnabled()) {
    try {
      understood = await vavaAgent.understandRequest({
        transcript: vavaAgent.formatTranscript(
          memoryFor(chatId, message ? messageThreadId(message) : undefined)
        ),
        todayISO: todayISO(),
        speaker: senderDisplayName(from),
      });
    } catch (err) {
      log("VAVA understand error:", err.message);
    }
    if (userWantsScheduleData(text)) {
      understood = {
        kind: "lookup",
        question: understood?.question || text,
        databases: ["Internal Events", "External Events"],
      };
    }
    if (understood) {
      log(
        `VAVA understand: kind=${understood.kind}` +
          (understood.question ? ` — ${understood.question}` : "")
      );
    }

    const transcript = vavaAgent.formatTranscript(
      memoryFor(chatId, message ? messageThreadId(message) : undefined)
    );
    const question = understood?.question || text;
    const onlyGreeting = /^(hey|hi|hello|yo|thanks|thank you|ok|okay|cool|lol)[.!?\s]*$/i.test(
      text.trim()
    );

    if (looksLikeMetaOrSocialBotMessage(text)) {
      const ok = await replyConversationally({
        text,
        chatId,
        situation:
          "They're joking or asking about VAVA/the bot (e.g. being ignored, who programmed you). Reply briefly and warmly in chat — do NOT list markets, events, or the rota unless they clearly asked for that in this message.",
      });
      if (ok) return;
      await sendMessage(
        chatId,
        "Ha — no secret programming. I'm here when you @ me. Ask about markets, today's plan, or /help."
      );
      return;
    }

    if (!onlyGreeting) {
      const wantsSchedule =
        userWantsScheduleData(text) ||
        userWantsScheduleData(question) ||
        marketRatings.looksLikeMarketRatingMessage(text);
      if (wantsSchedule) {
        const answered = await handleClassifiedQuestion({
          text: userWantsScheduleData(question) ? question : text,
          questionKind: "schedule",
          from,
          chatId,
          interactive: true,
          scheduleRange: guessScheduleRange(`${text} ${question}`),
          classified: null,
        });
        if (answered) return;
      }
      if ((understood?.databases || []).includes("Expenses") || looksLikeExpenseQuestion(text)) {
        await sendExpenseSummary(chatId, { from });
        return;
      }

      let decision = null;
      try {
        const knowledge = await vavaAgent.loadNotionKnowledge({
          question,
          databases: understood?.databases,
        });
        decision = await vavaAgent.decideWithKnowledge({
          transcript,
          question,
          knowledge,
          todayISO: todayISO(),
          speaker: senderDisplayName(from),
        });
      } catch (err) {
        log("Notion knowledge error:", err.message);
      }
      if (decision) log(`VAVA knowledge: mode=${decision.mode}`);

      if (decision?.mode === "answer" && decision.facts) {
        const spoken =
          (await vavaAgent.phraseReply({
            transcript,
            facts: decision.facts,
            speaker: senderDisplayName(from),
            todayISO: todayISO(),
          })) || decision.facts;
        await sendMessage(chatId, escapeHtml(spoken));
        return;
      }
      if (decision?.mode === "write") {
        const done = await deliverAgentNotion(
          {
            understanding: question,
            database: decision.database,
            notion_request: decision.notion_request || question,
            notion: decision.notion,
          },
          { from, chatId, text, message }
        );
        if (done) return;
        text = question;
      } else if (decision?.mode === "missing") {
        const spoken = await vavaAgent.phraseReply({
          transcript,
          facts:
            "Notion, the village knowledge base, does not contain this. Say that briefly, then answer from general knowledge if you can. Do not invent village events, bookings, rotas, or where an object is.",
          speaker: senderDisplayName(from),
          todayISO: todayISO(),
        });
        if (spoken) {
          await sendMessage(chatId, escapeHtml(spoken));
          return;
        }
      } else if (decision?.mode === "chat") {
        const ok = await replyConversationally({
          text,
          chatId,
          situation:
            "Notion was checked and nothing needs to be saved or looked up. Answer directly. Do not say you are fetching.",
        });
        if (ok) return;
      }
    } else {
      const ok = await replyConversationally({
        text,
        chatId,
        situation: "A greeting or thanks. Answer directly. Do not say you are looking something up.",
      });
      if (ok) return;
    }

    if (understood?.kind === "write" && understood.question) text = understood.question;
  }

  const classified = text ? await classifyUserMessage(text, chatId, { interactive: true }) : null;
  const acted = await routeByAiClassification({
    classified,
    text,
    from,
    chatId,
    photoFileId,
    interactive: true,
    message,
  });
  if (acted !== false || !text) return;

  if (openaiLib.isEnabled()) {
    try {
      const second = await thinkAboutMessage({
        text,
        from,
        chatId,
        message,
        missedStandardAction: true,
      });
      if (second?.mode === "notion") {
        const done = await deliverAgentNotion(second, { from, chatId, text, message });
        if (done) return;
      }
      if ((second?.mode === "reply" || second?.mode === "chat") && second.reply) {
        await sendMessage(chatId, escapeHtml(second.reply));
        return;
      }
    } catch (err) {
      log("VAVA improvise error:", err.message);
    }
  }

  const ok = await replyConversationally({
    text,
    chatId,
    classified,
    situation:
      "The usual village actions didn't fit, and there wasn't a safe Notion change to make. Respond like a person who has read the conversation. If they might want something saved, offer that in one question.",
  });
  if (!ok) {
    await sendMessage(
      chatId,
      "Happy to help — tell me what’s broken, what’s happening, or what you’re looking for."
    );
  }
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
  if (message.forum_topic_created?.name) {
    rememberForumTopicFromMessage(message, message.forum_topic_created.name);
  }

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

  if (text && !text.startsWith("/") && (await handlePendingExpenseEntity(message))) {
    return;
  }

  if (text && !text.startsWith("/") && (await handlePendingQuestionnaire(message))) {
    return;
  }

  if (text && !text.startsWith("/") && (await handlePendingClarification(message))) {
    return;
  }

  const isCommand = text.startsWith("/");
  const [rawCmd, ...rest] = text.split(/\s+/);
  const cmd = rawCmd.split("@")[0].toLowerCase();
  const args = rest.join(" ").trim();

  if (isCommand && (cmd === "/start" || cmd === "/help")) {
    if (cmd === "/start" && message.chat?.type === "private") {
      const { pending } = findQuestionnaire(from.id);
      if (pending) {
        await sendMessage(
          chatId,
          `Welcome — let’s continue your Community questionnaire.\n` +
            `Reply with a number (or <i>skip</i>).\n\n` +
            questionnairePrompt(pending.step)
        );
        return;
      }
    }
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

  if (isCommand && cmd === "/kids") {
    await handleKidsSlashCommand(args, { from, chatId });
    return;
  }

  if (isCommand && cmd === "/kids-topic") {
    if (!message.is_topic_message || !message.message_thread_id) {
      await sendMessage(
        chatId,
        "Open the <b>Parents & Teachers</b> forum topic in the Vila Akoya group, then run <code>/kids-topic</code> there (or <code>/kids-topic Parents &amp; Teachers</code>). I'll remember the topic for daily kids programme posts."
      );
      return;
    }
    const label = args.trim() || "Parents & Teachers";
    const saved = rememberForumTopicFromMessage(message, label);
    await sendMessage(
      chatId,
      `Saved forum topic <b>${escapeHtml(saved.label)}</b> → thread <code>${saved.threadId}</code> for daily kids programme roundups.`
    );
    return;
  }

  if (isCommand && cmd === "/register") {
    if (!args) {
      await sendMessage(chatId, "Usage: /register Your Full Name");
      return;
    }
    try {
      const { updated, pageId } = await registerMember({
        displayName: args,
        from,
        chatId,
      });
      await sendMessage(
        chatId,
        updated
          ? `Updated your member profile as <b>${escapeHtml(args)}</b>.`
          : `Registered you as <b>${escapeHtml(args)}</b> on the Community Members board.`
      );
      await startQuestionnaire({
        from,
        chatId,
        pageId,
        displayName: args,
      });
    } catch (err) {
      log("register error:", err.message);
      await sendMessage(chatId, "Sorry, I could not register you right now.");
    }
    return;
  }

  if (isCommand && cmd === "/questionnaire") {
    try {
      await startQuestionnaire({ from, chatId });
    } catch (err) {
      log("questionnaire error:", err.message);
      await sendMessage(
        chatId,
        "Sorry, I could not start the questionnaire right now."
      );
    }
    return;
  }

  if (isCommand && cmd === "/cancel") {
    const { key, pending } = findQuestionnaire(from.id);
    if (pending) {
      clearQuestionnaire(key);
      await sendMessage(
        chatId,
        "Questionnaire cancelled. Run /questionnaire whenever you’re ready."
      );
      return;
    }
    await sendMessage(chatId, "Nothing to cancel.");
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

  if (isCommand && (cmd === "/receipt" || cmd === "/expense" || cmd === "/recibo")) {
    const caption = args || stripBotAddressing((message.caption || "").trim(), botMe);
    const logged = await handleReceiptSubmission({
      message,
      text: caption,
      from,
      chatId,
      force: true,
      assumeReceipt: message.chat?.type === "private" && Boolean(getMessageMedia(message)),
    });
    if (!logged) {
      await sendMessage(
        chatId,
        "Send a receipt photo, PDF, or the till text with /receipt — or reply to a photo with /receipt."
      );
    }
    return;
  }

  if (isCommand && (cmd === "/receipts" || cmd === "/expenses")) {
    try {
      await sendExpenseSummary(chatId, { from });
    } catch (err) {
      log("receipts command error:", err.message);
      await sendMessage(chatId, "Sorry, I could not load expenses right now.");
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

  const faqHit = await handleFaqQuestion({ text: question, classified: null, chatId });
  if (faqHit) return { answered: true };

  const faqShaped = faqsLib.looksLikeFaqQuestion(question);

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

  if (faqShaped) {
    await sendMessage(
      chatId,
      "I don't have that in FAQs yet. Add it with: “Add FAQ: What is the address? → …”"
    );
    return { answered: false };
  }

  if (locationsLib.looksLikeLocationQuestion(question)) {
    const locHit = await handleLocationQuestion({
      question,
      text: question,
      classified: null,
      chatId,
    });
    if (locHit) return { answered: true };
  }

  if (!logGap) return { answered: false };

  try {
    await createDocGapTask({ question, from, chatId });
    await sendMessage(
      chatId,
      "I don’t have that written down yet. I’ve logged it as a documentation gap so a steward can fill it in."
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
  if (communityCookOffer.looksLikeCommunityCookOffer(text)) return false;
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

async function captureAmbientStay({ classified, text, from, chatId, interactive }) {
  const rawList =
    Array.isArray(classified?.stays) && classified.stays.length
      ? classified.stays
      : classified?.stay
        ? [classified.stay]
        : [];
  const stays = rawList
    .map((row) => guestStaysLib.normalizeStay(row, { sourceText: text }))
    .filter(Boolean);
  if (!stays.length) {
    if (interactive) {
      await sendMessage(
        chatId,
        "I can log that stay — who is it for, and which dates (check-in and check-out)?"
      );
    }
    return false;
  }

  const created = [];
  for (const stay of stays) {
    if (!stay.check_in) {
      if (interactive) {
        await sendMessage(
          chatId,
          `I have <b>${escapeHtml(stay.guest_name)}</b> — what are the check-in and check-out dates?`
        );
      }
      continue;
    }
    const key = ambientDedupeKey(
      chatId,
      "stay",
      `${stay.guest_name}:${stay.check_in}:${stay.check_out || ""}`
    );
    if (wasRecentlyCaptured(key)) continue;
    try {
      const result = await guestStaysLib.createStay(stay, {
        loggedBy: `${senderDisplayName(from)} (${senderHandle(from)})`,
        notes: telegramNote({ from, chatId, sourceText: text }),
      });
      markCaptured(key);
      markPageSeen(result.id, {
        stage: "",
        status: stay.status || "Confirmed",
        doer: "",
        done: false,
      });
      recordChatActivity({
        kind: "stay_logged",
        from,
        chatId,
        title: stay.guest_name,
        eventDate: stay.check_in,
        text,
      });
      created.push({ stay, url: result.url });
      log(`Stay captured: ${stay.guest_name} ${stay.check_in} → ${stay.check_out || "?"}`);
    } catch (err) {
      log("createStay error:", err.message);
    }
  }

  if (!created.length) return false;

  if (created.length === 1) {
    const { stay, url } = created[0];
    const when = stay.check_out ? `${stay.check_in} → ${stay.check_out}` : stay.check_in;
    let reply = `🏕️ Stay logged: <b>${escapeHtml(stay.guest_name)}</b> (${escapeHtml(when)})`;
    if (stay.kind) reply += ` · ${escapeHtml(stay.kind)}`;
    if (stay.unit) reply += ` · ${escapeHtml(stay.unit)}`;
    if (stay.party) reply += ` · ${escapeHtml(stay.party)}`;
    if (url) reply += ` — <a href="${url}">Notion</a>`;
    await sendMessage(chatId, reply);
  } else {
    const lines = created.map(({ stay, url }) => {
      const when = stay.check_out ? `${stay.check_in} → ${stay.check_out}` : stay.check_in;
      let line = `• <b>${escapeHtml(stay.guest_name)}</b> (${escapeHtml(when)})`;
      if (stay.unit) line += ` · ${escapeHtml(stay.unit)}`;
      if (url) line += ` — <a href="${url}">Notion</a>`;
      return line;
    });
    await sendMessage(chatId, `🏕️ Logged ${created.length} stays:\n${lines.join("\n")}`);
  }
  return true;
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
    const buf = memoryFor(chatId, messageThreadId(message));
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
    message,
  });
}

async function pollTelegramOnce() {
  let updates;
  try {
    // Long-poll ~25s; client abort is only a safety net (laptop sleep / hung socket).
    // On abort we loop immediately — that is normal, not a crash.
    updates = await telegram(
      "getUpdates",
      {
        offset: state.telegramOffset,
        timeout: 25,
        allowed_updates: ["message"],
      },
      { clientTimeoutMs: 90000, attempts: 1 }
    );
  } catch (err) {
    if (isGetUpdatesConflict(err)) {
      telegramPollActive = false;
      pollConflictStreak += 1;
      if (pollConflictStreak === 1 || pollConflictStreak % 20 === 0) {
        log(
          "getUpdates conflict: another bot instance is polling this token. " +
            "This process will not send recaps or Notion announcements until it is the only poller. " +
            "Stop the other instance to fix duplicate messages."
        );
      }
      await sleep(5000);
      return;
    }
    // Client abort / idle timeout: keep polling with no backoff and almost no logs.
    if (isAbortTimeoutError(err)) {
      telegramPollActive = true;
      networkState.retryMs = 3000;
      const now = Date.now();
      if (now - networkState.lastPollTimeoutLog > 30 * 60 * 1000) {
        networkState.lastPollTimeoutLog = now;
        log("getUpdates idle timeout — still running, restarting poll");
      }
      return;
    }
    telegramPollActive = false;
    if (isTransientNetworkError(err)) {
      logTransientError("getUpdates", err, networkState.retryMs);
      await sleep(networkState.retryMs);
      networkState.retryMs = Math.min(networkState.retryMs * 2, 30000);
    } else {
      log("getUpdates error:", err.message);
      await sleep(3000);
    }
    return;
  }

  telegramPollActive = true;
  pollConflictStreak = 0;
  networkState.retryMs = 3000;

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
        if (
          announce &&
          ANNOUNCE_NEW_ITEMS &&
          !done &&
          !(
            dbName === REMINDERS_DB_NAME &&
            broadcastRemindersLib.pageCheckbox(page, "Post to Telegram")
          )
        ) {
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

// ── Fly.io keepalive (no-op locally unless PORT is set) ─────────────────────
function startHealthServer() {
  const port = Number(process.env.PORT || 0);
  if (!port) return;
  const http = require("http");
  http
    .createServer((_req, res) => {
      res.writeHead(200, { "Content-Type": "text/plain" });
      res.end("ok\n");
    })
    .listen(port, () => log(`Health check listening on :${port}`));
}

// ── Main loop ─────────────────────────────────────────────────────────────
async function main() {
  if (!TELEGRAM_BOT_TOKEN) {
    throw new Error("Missing TELEGRAM_BOT_TOKEN env variable.");
  }
  startHealthServer();
  acquireInstanceLock();
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

  await ensureMembersQuestionnaireSchema();
  try {
    const br = await broadcastRemindersLib.ensureBroadcastReminderSchema();
    if (br.added?.length) {
      log(`Reminders broadcast schema: added ${br.added.join(", ")}`);
    }
  } catch (err) {
    log("Reminders broadcast schema ensure failed:", err.message);
  }
  try {
    const loc = await locationsLib.ensureLocationsDatabase();
    log(
      `Where things are: ${loc.wasCreated ? "created" : "ready"} (${LOCATIONS_DB_NAME})` +
        (loc.seeded ? `, seeded ${loc.seeded} items` : "")
    );
  } catch (err) {
    log("Where things are setup skipped:", err.message);
  }
  try {
    const faqDb = await faqsLib.ensureFaqsDatabase();
    log(
      `FAQs: ${faqDb.wasCreated ? "created" : "ready"} (${FAQS_DB_NAME})` +
        (faqDb.seeded ? `, seeded ${faqDb.seeded} FAQ(s)` : "")
    );
  } catch (err) {
    log("FAQs setup skipped:", err.message);
  }
  try {
    const med = await medicalFacilities.ensureMedicalFacilitiesDatabase();
    log(
      `Medical & emergency: ${med.wasCreated ? "created" : "ready"} (${medicalFacilities.FACILITIES_DB_NAME})` +
        (med.seeded ? `, added ${med.seeded} row(s)` : "") +
        (med.updated ? `, updated ${med.updated} row(s)` : "")
    );
  } catch (err) {
    log("Medical directory setup skipped:", err.message);
  }
  try {
    const stays = await guestStaysLib.ensureGuestStaysDatabase();
    log(
      `Guest stays: ${stays.wasCreated ? "created" : "ready"} (${guestStaysLib.GUEST_STAYS_DB_NAME})`
    );
  } catch (err) {
    log("Guest stays setup skipped:", err.message);
  }

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
    await maybeSendBroadcastReminders();

    const now = Date.now();
    if (
      TELEGRAM_CHAT_ID &&
      telegramPollActive &&
      now - lastNotionPoll >= NOTION_POLL_SECONDS * 1000
    ) {
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

async function sendDailyRecapOnce() {
  if (!TELEGRAM_BOT_TOKEN) {
    throw new Error("Missing TELEGRAM_BOT_TOKEN env variable.");
  }
  if (!TELEGRAM_CHAT_ID) {
    throw new Error("Missing TELEGRAM_CHAT_ID");
  }
  notion.assertKey();
  const today = todayISO();
  log(`Building daily recap for ${today}...`);
  const recap = await buildDailyRecap(today);
  if (!recap || !String(recap).trim()) {
    throw new Error("Daily recap text was empty");
  }
  log(`Recap ready (${recap.length} chars), sending to ${TELEGRAM_CHAT_ID}`);
  const sent = await sendMessage(TELEGRAM_CHAT_ID, recap);
  if (!sent) throw new Error("Daily recap send failed");
  log(`Daily recap sent for ${today}`);
  const mgmtBrief = await buildManagementCampsiteBriefing(today);
  if (mgmtBrief) {
    const members = await fetchManagementMembersForDm();
    for (const member of members) {
      await sendMessage(member.userId, mgmtBrief);
    }
    if (members.length) {
      log(`Management campsite briefing sent to ${members.length} member(s)`);
    }
  }
}

if (process.argv.includes("--agent-tools-check")) {
  const fake = { message_id: 1, chat: { id: -1, type: "supergroup", title: "Test" }, from: { id: 1, first_name: "Test" }, text: "hi" };
  const tools = buildAgentTools({ message: fake, text: "hi", photoFileId: null, media: null });
  const names = tools.map((t) => t.name);
  const problems = [];
  if (new Set(names).size !== names.length) problems.push("duplicate tool names");
  for (const t of tools) {
    if (!/^[a-z_]{1,64}$/.test(t.name)) problems.push(`bad name ${t.name}`);
    if (typeof t.run !== "function") problems.push(`${t.name}: no run()`);
    if (t.parameters?.type !== "object") problems.push(`${t.name}: parameters must be an object schema`);
    for (const [key, prop] of Object.entries(t.parameters?.properties || {})) {
      if (prop.enum && !prop.enum.length) problems.push(`${t.name}.${key}: empty enum`);
    }
    for (const req of t.parameters?.required || []) {
      if (!t.parameters.properties?.[req]) problems.push(`${t.name}: required ${req} not in properties`);
    }
  }
  console.log(`${tools.length} agent tools: ${names.join(", ")}`);
  if (problems.length) {
    console.error(problems.join("\n"));
    process.exit(1);
  }
  console.log("agent tools check OK");
  process.exit(0);
} else if (process.argv.includes("--send-recap")) {
  sendDailyRecapOnce()
    .then(() => process.exit(0))
    .catch((err) => {
      console.error("Daily recap send failed:", err);
      process.exit(1);
    });
} else {
  main().catch((err) => {
    console.error("Bot crashed:", err);
    process.exit(1);
  });
}
