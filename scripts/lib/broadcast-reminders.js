/**
 * Scheduled community reminders from Notion → Telegram (groups, topics, or role DMs).
 */

"use strict";

const notion = require("./notion");
const { parseRecurrence, occursOn } = require("./event-recurrence");

const REMINDERS_DB_NAME = process.env.REMINDERS_DB_NAME || notion.DB.REMINDERS;
const MEMBERS_DB_NAME = process.env.MEMBERS_DB_NAME || notion.DB.MEMBERS;

const AUDIENCE_MAIN = "Main group";
const AUDIENCE_CHAT = "Telegram chat";
const AUDIENCE_ROLE = "Members by role";

const AUDIENCE_OPTIONS = [AUDIENCE_MAIN, AUDIENCE_CHAT, AUDIENCE_ROLE];

function selectOpts(names) {
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

function pageCheckbox(page, name) {
  const p = page.properties?.[name];
  return p?.type === "checkbox" ? Boolean(p.checkbox) : false;
}

/** Multi-select Audiences, or legacy single Audience select. */
function pageAudiences(page) {
  const multi = page.properties?.Audiences;
  if (multi?.type === "multi_select" && multi.multi_select?.length) {
    return multi.multi_select.map((x) => x.name).filter(Boolean);
  }
  const single = page.properties?.Audience;
  if (single?.type === "multi_select" && single.multi_select?.length) {
    return single.multi_select.map((x) => x.name).filter(Boolean);
  }
  if (single?.type === "select" && single.select?.name) {
    return [single.select.name];
  }
  const text = notion.pagePropText(page, "Audiences") || notion.pagePropText(page, "Audience");
  if (text) {
    return text
      .split(",")
      .map((s) => s.trim())
      .filter(Boolean);
  }
  return [AUDIENCE_MAIN];
}

function normalizeRole(s) {
  return String(s || "")
    .toLowerCase()
    .replace(/[’']/g, "")
    .replace(/\s+/g, " ")
    .trim();
}

function roleMatches(memberRole, wanted) {
  const m = normalizeRole(memberRole);
  const w = normalizeRole(wanted);
  if (!m || !w) return false;
  if (m === w) return true;
  return m.includes(w) || w.includes(m);
}

function parseChatAliases() {
  const raw = process.env.TELEGRAM_CHAT_ALIASES || "";
  if (!raw.trim()) return {};
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === "object" ? parsed : {};
  } catch {
    return {};
  }
}

function resolveChatIdFromField(raw, aliases) {
  const text = String(raw || "").trim();
  if (!text) return "";
  if (/^-?\d+$/.test(text)) return text;
  if (aliases[text]) return String(aliases[text]);
  const lower = text.toLowerCase();
  for (const [name, id] of Object.entries(aliases)) {
    if (name.toLowerCase() === lower) return String(id);
  }
  return text;
}

/** HH:MM (24h) from Notion Time field; falls back to defaultTime. */
function parseSendTime(raw, defaultTime) {
  const fallback = String(defaultTime || "08:00").trim();
  const text = String(raw || "").trim();
  if (!text) return fallback;
  const m = text.match(/^(\d{1,2}):(\d{2})/);
  if (!m) return fallback;
  const h = Math.min(23, Math.max(0, parseInt(m[1], 10)));
  const min = Math.min(59, Math.max(0, parseInt(m[2], 10)));
  return `${String(h).padStart(2, "0")}:${String(min).padStart(2, "0")}`;
}

function reminderIsActive(page) {
  const status = notion.pagePropText(page, "Status").toLowerCase();
  if (status === "done" || status === "cancelled") return false;
  return pageCheckbox(page, "Post to Telegram");
}

function reminderDueOnDate(page, dateISO, tz) {
  const recurrence = notion.pagePropText(page, "Recurrence").trim();
  if (recurrence) {
    const rule = parseRecurrence(recurrence);
    if (rule) return occursOn(rule, dateISO, tz);
  }
  const due = notion.pageDateISO(page, "Due date");
  return due && due === dateISO;
}

function buildReminderMessage(page) {
  const notes = notion.pagePropText(page, "Notes").trim();
  const title = notion.pageTitle(page);
  if (notes) return notes;
  return title;
}

function parseBroadcastPage(page) {
  if (!reminderIsActive(page)) return null;
  const aliases = parseChatAliases();
  const audiences = pageAudiences(page);
  const chatLabel = notion.pagePropText(page, "Telegram chat").trim();
  const chatIdRaw = notion.pagePropText(page, "Telegram chat ID").trim();
  const topicRaw = notion.pagePropText(page, "Topic ID").trim();
  const memberRole = notion.pagePropText(page, "Member role").trim();
  const sendTime = parseSendTime(
    notion.pagePropText(page, "Time"),
    process.env.REMINDER_TIME || process.env.RECAP_TIME || "08:00"
  );

  let chatId = "";
  if (audiences.includes(AUDIENCE_CHAT)) {
    chatId = resolveChatIdFromField(chatIdRaw || chatLabel, aliases);
  }

  const topicId =
    topicRaw && /^\d+$/.test(topicRaw) ? Number(topicRaw) : null;

  return {
    id: page.id,
    title: notion.pageTitle(page),
    message: buildReminderMessage(page),
    audiences,
    chatId,
    topicId,
    memberRole,
    sendTime,
    url: page.url,
  };
}

async function ensureBroadcastReminderSchema() {
  const db = await notion.resolveDatabase(REMINDERS_DB_NAME);
  const existing = db.properties || {};
  const toAdd = {};
  if (!existing["Post to Telegram"]) toAdd["Post to Telegram"] = { checkbox: {} };
  if (!existing.Audience) {
    toAdd.Audience = { select: { options: selectOpts(AUDIENCE_OPTIONS) } };
  }
  if (!existing.Audiences) {
    toAdd.Audiences = { multi_select: { options: selectOpts(AUDIENCE_OPTIONS) } };
  }
  if (!existing["Telegram chat"]) toAdd["Telegram chat"] = { rich_text: {} };
  if (!existing["Telegram chat ID"]) toAdd["Telegram chat ID"] = { rich_text: {} };
  if (!existing["Topic ID"]) toAdd["Topic ID"] = { rich_text: {} };
  if (!existing["Member role"]) toAdd["Member role"] = { rich_text: {} };
  if (!Object.keys(toAdd).length) return { added: [] };
  await notion.notion("PATCH", `/databases/${db.id}`, { properties: toAdd });
  await notion.primeDatabase(REMINDERS_DB_NAME, db.id);
  return { added: Object.keys(toAdd) };
}

async function fetchActiveBroadcastReminders() {
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(REMINDERS_DB_NAME));
  } catch (err) {
    throw new Error(`Reminders database: ${err.message}`);
  }
  const out = [];
  for (const page of pages) {
    const row = parseBroadcastPage(page);
    if (row) out.push({ page, row });
  }
  return out;
}

async function fetchMembersForRoleDm() {
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(MEMBERS_DB_NAME));
  } catch {
    return [];
  }
  return pages
    .map((page) => {
      const stage = notion.pagePropText(page, "Stage");
      const status = notion.pagePropText(page, "Member status");
      if (stage === "Left / inactive" || status === "Inactive") return null;
      const userId = notion.pagePropText(page, "Telegram user ID").trim();
      if (!userId || !/^\d+$/.test(userId)) return null;
      const role = notion.pagePropText(page, "Role / focus");
      const displayName =
        notion.pagePropText(page, "Display name") || notion.pageTitle(page);
      return { userId, role, displayName };
    })
    .filter(Boolean);
}

function membersMatchingRole(members, roleWanted) {
  if (!roleWanted) return [];
  return members.filter((m) => roleMatches(m.role, roleWanted));
}

function parseTopicAliases() {
  const raw = process.env.TELEGRAM_TOPIC_ALIASES || "";
  if (!raw.trim()) return {};
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === "object" ? parsed : {};
  } catch {
    return {};
  }
}

function resolveTopicIdFromField(raw, aliases) {
  const text = String(raw || "").trim();
  if (!text) return null;
  if (/^\d+$/.test(text)) return Number(text);
  if (aliases[text] != null) return Number(aliases[text]);
  const lower = text.toLowerCase();
  for (const [name, id] of Object.entries(aliases)) {
    if (name.toLowerCase() === lower) return Number(id);
  }
  return null;
}

/** Detect month-day recurrence like 1st–3rd of each month from natural language. */
/** Mon–Fri only, e.g. morning meeting reminders not on weekends. */
function inferWeekdaysOnlyRecurrence(text) {
  const lower = String(text || "").toLowerCase();
  const mentionsMeeting =
    /\bmorning\s+meetings?\b/.test(lower) ||
    /\bmorning\s+meeting\b/.test(lower) ||
    /\bdaily\s+reminder\b/.test(lower);
  const wantsWeekdays =
    /\b(monday|mon)\b.*\b(friday|fri)\b/.test(lower) ||
    (/\bweekdays?\b/.test(lower) && /\bonly\b/.test(lower)) ||
    (/\b(weekend|saturday|sunday)\b/.test(lower) &&
      /\b(never|not|no|skip|without)\b/.test(lower));
  if (!mentionsMeeting && !/\breminder\b/.test(lower)) return null;
  if (!wantsWeekdays) return null;
  return "weekly:Mon,Tue,Wed,Thu,Fri";
}

function inferMonthDaysRecurrence(text) {
  const lower = String(text || "").toLowerCase();
  if (/\bmonthly:1,2,3\b/.test(lower)) return "monthly:1,2,3";
  if (
    /\b(1st|first|1)\s*[,/&]\s*(2nd|second|2)\s*[,/&]?\s*(and\s+)?(3rd|third|3)\b/.test(
      lower
    ) &&
    /\b(month|each month|of the month|mensal)\b/.test(lower)
  ) {
    return "monthly:1,2,3";
  }
  const has1 = /\b(1st|first|primeir[oa])\b/.test(lower);
  const has2 = /\b(2nd|second|segund[oa])\b/.test(lower);
  const has3 = /\b(3rd|third|terceir[oa])\b/.test(lower);
  if (has1 && has2 && has3) return "monthly:1,2,3";
  return null;
}

function inferReminderTitleFromText(text) {
  const raw = String(text || "").trim();
  if (/lunch/i.test(raw) && /pay|money|jar/i.test(raw)) {
    return "Parents: pay for lunch";
  }
  const forParents = raw.match(
    /\bfor\s+parents?\s+to\s+(.+?)(?:\.|,|$)/i
  );
  if (forParents?.[1]) {
    return `Parents: ${forParents[1].trim()}`.slice(0, 80);
  }
  return "Community reminder";
}

function looksLikeCommunityBroadcastReminder(text) {
  const lower = String(text || "").toLowerCase();
  if (inferMonthDaysRecurrence(text)) return true;
  if (/\bpost\b.*\b(telegram|group|topic|channel)\b/.test(lower)) return true;
  if (/\bparents?\s*(and|&)\s*teachers?\b/.test(lower)) return true;
  if (/\bremind(er)?\b.*\bparents?\b/.test(lower) && /\b(group|topic|telegram)\b/.test(lower)) {
    return true;
  }
  if (/\bremind(er)?\b.*\b(every|each)\b/.test(lower) && /\bmonth\b/.test(lower)) {
    return true;
  }
  return false;
}

/**
 * Fill broadcast fields when the user asked for a scheduled group/topic reminder.
 * @param {object} reminder - partial from AI or empty
 * @param {string} text - user message
 * @param {{ messageThreadId?: number|null }} context
 */
function enrichBroadcastReminder(reminder, text, context = {}) {
  const base = { ...(reminder || {}) };
  const lower = String(text || "").toLowerCase();
  const chatAliases = parseChatAliases();
  const topicAliases = parseTopicAliases();

  const wantsBroadcast =
    base.broadcast === true ||
    base.post_to_telegram === true ||
    (Array.isArray(base.audiences) && base.audiences.length > 0) ||
    looksLikeCommunityBroadcastReminder(text);

  if (!wantsBroadcast) return { reminder: base, broadcast: null };

  const recurrence =
    String(base.recurrence || "").trim() || inferMonthDaysRecurrence(text) || "";
  const audiences = Array.isArray(base.audiences)
    ? [...base.audiences]
    : base.audience
      ? [base.audience]
      : [];

  const parentsTeachers =
    /\bparents?\s*(and|&)\s*teachers?\b/.test(lower) ||
    /parents?\s+and\s+teachers?\s+topic/.test(lower);

  if (parentsTeachers && !audiences.includes(AUDIENCE_CHAT)) {
    audiences.push(AUDIENCE_CHAT);
  }
  if (
    /\b(for|to)\s+parents?\b/.test(lower) ||
    /\bremind\s+parents?\b/.test(lower) ||
    (/\bparents?\b/.test(lower) && /\b(dm|message|tell|notify)\b/.test(lower))
  ) {
    if (!audiences.includes(AUDIENCE_ROLE)) audiences.push(AUDIENCE_ROLE);
  }
  if (!audiences.length && wantsBroadcast) {
    audiences.push(AUDIENCE_CHAT);
  }

  let telegramChat =
    String(base.telegram_chat || base.telegram_chat_id || "").trim() ||
    (parentsTeachers ? "Parents & Teachers" : "");
  let topicId =
    base.topic_id != null && base.topic_id !== ""
      ? String(base.topic_id)
      : "";
  if (!topicId && parentsTeachers) {
    const fromAlias = resolveTopicIdFromField("Parents & Teachers", topicAliases);
    if (fromAlias) topicId = String(fromAlias);
  }
  if (!topicId && context.messageThreadId) {
    topicId = String(context.messageThreadId);
  }

  let memberRole = String(base.member_role || "").trim();
  if (!memberRole && audiences.includes(AUDIENCE_ROLE) && /\bparents?\b/.test(lower)) {
    memberRole = "Parent";
  }

  const resolvedChatId = audiences.includes(AUDIENCE_CHAT)
    ? resolveChatIdFromField(telegramChat, chatAliases)
    : "";

  const broadcast = {
    postToTelegram: true,
    recurrence,
    audiences: [...new Set(audiences)],
    telegramChat,
    chatId: resolvedChatId,
    topicId: topicId ? Number(topicId) : null,
    memberRole,
  };

  return { reminder: base, broadcast };
}

/**
 * Send to every audience on the row. Returns true when all selected channels succeeded.
 */
async function deliverBroadcastReminder(row, text, { sendMessage, mainChatId, roleMembers }) {
  const audiences = row.audiences?.length ? row.audiences : [AUDIENCE_MAIN];
  const unique = [...new Set(audiences)];
  let allOk = true;
  const sentTo = [];

  for (const aud of unique) {
    if (aud === AUDIENCE_ROLE) {
      if (!row.memberRole) {
        allOk = false;
        continue;
      }
      const targets = membersMatchingRole(roleMembers, row.memberRole);
      if (!targets.length) continue;
      let any = false;
      for (const member of targets) {
        if (await sendMessage(member.userId, text)) any = true;
      }
      if (!any) allOk = false;
      else sentTo.push(AUDIENCE_ROLE);
      continue;
    }

    if (aud === AUDIENCE_CHAT) {
      if (!row.chatId) {
        allOk = false;
        continue;
      }
      const extra = row.topicId ? { message_thread_id: row.topicId } : {};
      if (await sendMessage(row.chatId, text, extra)) sentTo.push(AUDIENCE_CHAT);
      else allOk = false;
      continue;
    }

    if (aud === AUDIENCE_MAIN) {
      if (!mainChatId) {
        allOk = false;
        continue;
      }
      if (await sendMessage(mainChatId, text)) sentTo.push(AUDIENCE_MAIN);
      else allOk = false;
    }
  }

  return { ok: allOk && sentTo.length > 0, sentTo };
}

module.exports = {
  REMINDERS_DB_NAME,
  AUDIENCE_MAIN,
  AUDIENCE_CHAT,
  AUDIENCE_ROLE,
  ensureBroadcastReminderSchema,
  fetchActiveBroadcastReminders,
  fetchMembersForRoleDm,
  membersMatchingRole,
  reminderDueOnDate,
  parseBroadcastPage,
  pageAudiences,
  deliverBroadcastReminder,
  pageCheckbox,
  parseSendTime,
  parseChatAliases,
  resolveChatIdFromField,
  parseTopicAliases,
  resolveTopicIdFromField,
  inferMonthDaysRecurrence,
  inferWeekdaysOnlyRecurrence,
  looksLikeCommunityBroadcastReminder,
  enrichBroadcastReminder,
  inferReminderTitleFromText,
};
