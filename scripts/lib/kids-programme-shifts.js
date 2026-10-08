/**
 * Kids programme / teacher–caregiver shifts (Notion → Telegram).
 */

"use strict";

const notion = require("./notion");
const { nameMatchesPerson, addDaysISO } = require("./duty-schedule");
const broadcastReminders = require("./broadcast-reminders");

const DB_NAME =
  process.env.KIDS_PROGRAMME_DB || notion.DB.KIDS_PROGRAMME_SHIFTS;

const STATUS_CANCELLED = "Cancelled";
const STATUS_TBC = "TBC";
const STATUS_CONFIRMED = "Confirmed";

/** Default time bands when the Time property is empty. */
const PROGRAMME_TIMES = {
  Coddiwomplers: "10:00–13:00",
  "Early Readers": "11:00–13:00",
  Arete: "11:00–13:00",
  "Home Schooling Hub": "14:00–15:30",
  "Parent / villager activity": "17:00–18:00",
  "Day note": "",
};

const PROGRAMME_ORDER = [
  "Coddiwomplers",
  "Early Readers",
  "Arete",
  "Home Schooling Hub",
  "Parent / villager activity",
  "Day note",
];

function pageMatchesDay(page, dateISO) {
  const date = notion.pageDateISO(page, "Date");
  return date === dateISO;
}

function postToTelegram(page) {
  const raw = notion.pagePropText(page, "Post to Telegram").toLowerCase();
  if (raw === "false") return false;
  return true;
}

function parseShiftPage(page) {
  const programme = notion.pagePropText(page, "Programme").trim();
  const status = notion.pagePropText(page, "Status").trim() || STATUS_CONFIRMED;
  const time =
    notion.pagePropText(page, "Time").trim() ||
    PROGRAMME_TIMES[programme] ||
    "";
  const lead = notion.pagePropText(page, "Lead").trim();
  const support = notion.pagePropText(page, "Support").trim();
  const notes = notion.pagePropText(page, "Notes").trim();
  const date = notion.pageDateISO(page, "Date");
  return {
    id: page.id,
    title: notion.pageTitle(page),
    programme,
    status,
    time,
    lead,
    support,
    notes,
    date,
    postToTelegram: postToTelegram(page),
  };
}

function compareShifts(a, b) {
  const ia = PROGRAMME_ORDER.indexOf(a.programme);
  const ib = PROGRAMME_ORDER.indexOf(b.programme);
  const oa = ia >= 0 ? ia : 99;
  const ob = ib >= 0 ? ib : 99;
  if (oa !== ob) return oa - ob;
  return String(a.time).localeCompare(String(b.time));
}

async function fetchShiftsForDay(dateISO, { dbName = DB_NAME } = {}) {
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(dbName));
  } catch (err) {
    err.message = `Kids programme DB (${dbName}): ${err.message}`;
    throw err;
  }

  return pages
    .filter((p) => pageMatchesDay(p, dateISO) && postToTelegram(p))
    .map(parseShiftPage)
    .filter((s) => s.status !== STATUS_CANCELLED)
    .sort(compareShifts);
}

async function fetchShiftsBetween(startISO, endISO, { dbName = DB_NAME } = {}) {
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(dbName));
  } catch {
    return [];
  }
  const out = [];
  for (const page of pages) {
    const date = notion.pageDateISO(page, "Date");
    if (!date || date < startISO || date > endISO) continue;
    if (!postToTelegram(page)) continue;
    const shift = parseShiftPage(page);
    if (shift.status === STATUS_CANCELLED) continue;
    out.push(shift);
  }
  out.sort((a, b) => {
    if (a.date !== b.date) return a.date < b.date ? -1 : 1;
    return compareShifts(a, b);
  });
  return out;
}

function formatLeadLine(shift) {
  if (shift.programme === "Day note") {
    return shift.notes || shift.lead || shift.title;
  }
  const people = [shift.lead, shift.support].filter(Boolean).join(" + ");
  if (!people && shift.status === STATUS_TBC) return "TBC — needs cover";
  if (!people) return "TBC";
  if (shift.status === STATUS_TBC) return `${people} (TBC)`;
  return people;
}

function formatShiftBullet(shift, { html = false } = {}) {
  if (shift.programme === "Day note") {
    const text = formatLeadLine(shift);
    return html ? `• <i>Note:</i> ${text}` : `• Note: ${text}`;
  }
  const band = shift.time ? `${shift.time} ` : "";
  const line = `• ${band}${shift.programme} — ${formatLeadLine(shift)}`;
  if (shift.notes) {
    return html
      ? `${line} <i>(${shift.notes})</i>`
      : `${line} (${shift.notes})`;
  }
  return line;
}

function formatKidsProgrammeBlock(shifts, { html = false, heading = "" } = {}) {
  const sessions = shifts.filter((s) => s.programme !== "Day note");
  const notes = shifts.filter((s) => s.programme === "Day note");
  if (!sessions.length && !notes.length) return "";

  const lines = [];
  if (heading) lines.push(heading, "");
  for (const s of sessions) lines.push(formatShiftBullet(s, { html }));
  for (const n of notes) lines.push(formatShiftBullet(n, { html }));
  return lines.join("\n");
}

function splitPeopleNames(value) {
  return String(value || "")
    .split(/[,;&+]|\band\b/i)
    .map((s) => s.trim())
    .filter(Boolean);
}

function memberMatchesName(member, name) {
  const handle = String(member.username || "").replace(/^@/, "");
  if (nameMatchesPerson(name, member.displayName, handle)) return true;
  if (nameMatchesPerson(member.displayName, name)) return true;
  return false;
}

function shiftsForMember(shifts, member) {
  const mine = [];
  for (const shift of shifts) {
    if (shift.programme === "Day note") continue;
    const names = [...splitPeopleNames(shift.lead), ...splitPeopleNames(shift.support)];
    if (names.some((n) => memberMatchesName(member, n))) mine.push(shift);
  }
  return mine.sort(compareShifts);
}

function groupDmTargets(shifts, members) {
  const byUserId = new Map();
  for (const member of members) {
    const mine = shiftsForMember(shifts, member);
    if (mine.length) byUserId.set(member.userId, { member, shifts: mine });
  }
  return [...byUserId.values()];
}

function countTbcShifts(shifts) {
  return shifts.filter(
    (s) =>
      s.programme !== "Day note" &&
      (s.status === STATUS_TBC || (!s.lead && !s.support))
  ).length;
}

/**
 * @param {{ fallbackChatId?: string, topicAliases?: Record<string, number> }} opts
 */
function resolveParentsTeachersDestination(opts = {}) {
  const chatAliases = broadcastReminders.parseChatAliases();
  const topicAliases = {
    ...broadcastReminders.parseTopicAliases(),
    ...(opts.topicAliases || {}),
  };

  const chatRaw =
    process.env.KIDS_PROGRAMME_TELEGRAM_CHAT_ID ||
    process.env.KIDS_PROGRAMME_TELEGRAM_CHAT ||
    "Parents & Teachers";
  let chatId = broadcastReminders.resolveChatIdFromField(chatRaw, chatAliases);
  if (!chatId || !/^-?\d+$/.test(String(chatId))) {
    const fallback = String(
      opts.fallbackChatId || process.env.TELEGRAM_CHAT_ID || ""
    ).trim();
    if (/^-?\d+$/.test(fallback)) chatId = fallback;
  }
  if (!chatId || !/^-?\d+$/.test(String(chatId))) return null;

  const topicRaw =
    process.env.KIDS_PROGRAMME_TOPIC_ID ||
    process.env.KIDS_PROGRAMME_TOPIC ||
    "Parents & Teachers";
  let topicId = broadcastReminders.resolveTopicIdFromField(topicRaw, topicAliases);
  if (topicId == null) {
    for (const key of [
      "Parents & Teachers",
      "Parents and Teachers",
      "parents & teachers",
      "parents and teachers",
    ]) {
      if (topicAliases[key] != null) {
        topicId = Number(topicAliases[key]);
        break;
      }
      const lower = key.toLowerCase();
      for (const [name, id] of Object.entries(topicAliases)) {
        if (name.toLowerCase() === lower || name.toLowerCase().includes("parent")) {
          topicId = Number(id);
          break;
        }
      }
      if (topicId != null) break;
    }
  }
  return { chatId: String(chatId), topicId };
}

function membersWithKidsRole(members, roleHint) {
  const wanted =
    roleHint ||
    process.env.KIDS_PROGRAMME_COORDINATOR_ROLE ||
    "Kids / education";
  const w = String(wanted).toLowerCase();
  return members.filter((m) => {
    const r = String(m.role || "").toLowerCase();
    return r && (r === w || r.includes(w) || w.includes(r));
  });
}

/** Map free text to a canonical Programme select value. */
function normalizeProgrammeName(raw) {
  const t = String(raw || "").trim();
  if (!t) return null;
  const lower = t.toLowerCase().replace(/\s+/g, " ");
  if (lower.includes("coddiwompl")) return "Coddiwomplers";
  if (lower.includes("early reader")) return "Early Readers";
  if (/\barete\b/.test(lower)) return "Arete";
  if (lower.includes("home school") || lower === "hsh" || lower.includes("hsh "))
    return "Home Schooling Hub";
  if (
    (lower.includes("parent") && lower.includes("villager")) ||
    lower.includes("parent activity") ||
    lower.includes("parent/villager")
  ) {
    return "Parent / villager activity";
  }
  for (const p of PROGRAMME_ORDER) {
    if (p.toLowerCase() === lower) return p;
  }
  return null;
}

/**
 * Parse today / tomorrow / weekday name / YYYY-MM-DD.
 * @param {string} raw
 * @param {{ todayISO: string, tz?: string }} opts
 */
function parseShiftDate(raw, { todayISO, tz = "Europe/Lisbon" }) {
  const base = String(todayISO || "").slice(0, 10);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(base)) return base;

  const t = String(raw || "").trim().toLowerCase();
  if (!t) return base;
  const isoMatch = t.match(/^(\d{4}-\d{2}-\d{2})/);
  if (isoMatch) return isoMatch[1];

  if (t === "today" || t === "hoje") return base;
  if (t === "tomorrow" || t === "amanha" || t === "amanhã") return addDaysISO(base, 1);

  const weekday = t.replace(/[.,]/g, "").trim();
  for (let i = 0; i < 8; i++) {
    const d = addDaysISO(base, i);
    const name = new Intl.DateTimeFormat("en-US", {
      timeZone: tz,
      weekday: "long",
    })
      .format(new Date(d + "T12:00:00"))
      .toLowerCase();
    if (name === weekday || name.startsWith(weekday) || weekday.startsWith(name.slice(0, 3))) {
      return d;
    }
  }
  return base;
}

function normalizeShiftStatus(raw) {
  const s = String(raw || "").trim().toLowerCase();
  if (s === "cancelled" || s === "canceled" || s === "clear" || s === "off")
    return STATUS_CANCELLED;
  if (s === "tbc" || s === "?" || s === "unsure") return STATUS_TBC;
  return STATUS_CONFIRMED;
}

/**
 * Create or update one kids programme row (one programme per day).
 */
async function upsertKidsProgrammeShift(
  {
    date,
    programme,
    lead,
    support,
    status,
    notes,
    assign_as,
    clear,
  },
  { dbName = DB_NAME, todayISO, tz = "Europe/Lisbon" } = {}
) {
  const programmeName = normalizeProgrammeName(programme);
  if (!programmeName) {
    throw new Error(
      `Unknown programme "${programme}". Use: ${PROGRAMME_ORDER.filter((p) => p !== "Day note").join(", ")}`
    );
  }

  const dateISO = parseShiftDate(date, { todayISO, tz });
  let shiftStatus = clear ? STATUS_CANCELLED : normalizeShiftStatus(status);
  const assignAs = String(assign_as || "lead").toLowerCase() === "support" ? "support" : "lead";

  let leadName = lead ? String(lead).trim().slice(0, 120) : "";
  let supportName = support ? String(support).trim().slice(0, 120) : "";
  const noteText = notes ? String(notes).trim().slice(0, 500) : "";

  const { pages } = await notion.queryDatabase(dbName);
  const existing = pages.find(
    (p) =>
      pageMatchesDay(p, dateISO) &&
      notion.pagePropText(p, "Programme") === programmeName
  );

  if (assignAs === "support" && leadName && !supportName) {
    supportName = leadName;
    leadName = existing ? notion.pagePropText(existing, "Lead").trim() : leadName;
  }

  if (!clear && shiftStatus !== STATUS_CANCELLED && !leadName && !supportName) {
    shiftStatus = STATUS_TBC;
  }

  const props = {
    Date: dateISO,
    Programme: programmeName,
    Time: PROGRAMME_TIMES[programmeName] || "",
    Lead: leadName,
    Support: supportName,
    Status: shiftStatus,
    Notes: noteText,
    "Post to Telegram": shiftStatus !== STATUS_CANCELLED,
    Source: "Telegram",
  };

  if (existing) {
    const prevNotes = notion.pagePropText(existing, "Notes").trim();
    if (!noteText && prevNotes) props.Notes = prevNotes;
    if (assignAs === "lead" && leadName && !supportName) {
      const prevSupport = notion.pagePropText(existing, "Support").trim();
      if (prevSupport) props.Support = prevSupport;
    }
    await notion.updatePageProps(dbName, existing.id, props);
    return {
      updated: true,
      id: existing.id,
      url: notion.pageUrl(existing),
      dateISO,
      programme: programmeName,
      lead: leadName,
      support: supportName,
      status: shiftStatus,
      time: props.Time,
    };
  }

  const title =
    `${dateISO} · ${programmeName}${leadName ? ` · ${leadName}` : " · TBC"}`.slice(
      0,
      200
    );
  const created = await notion.createPage({
    databaseName: dbName,
    title,
    properties: props,
  });
  return {
    updated: false,
    id: created.id,
    url: created.url,
    dateISO,
    programme: programmeName,
    lead: leadName,
    support: supportName,
    status: shiftStatus,
    time: props.Time,
  };
}

module.exports = {
  DB_NAME,
  PROGRAMME_TIMES,
  PROGRAMME_ORDER,
  STATUS_TBC,
  STATUS_CONFIRMED,
  STATUS_CANCELLED,
  parseShiftPage,
  fetchShiftsForDay,
  fetchShiftsBetween,
  formatKidsProgrammeBlock,
  formatShiftBullet,
  formatLeadLine,
  groupDmTargets,
  countTbcShifts,
  shiftsForMember,
  memberMatchesName,
  splitPeopleNames,
  resolveParentsTeachersDestination,
  membersWithKidsRole,
  normalizeProgrammeName,
  parseShiftDate,
  upsertKidsProgrammeShift,
};
