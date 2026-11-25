/**
 * Day managers — who is on management duty for a given calendar day (Notion → Telegram).
 */

"use strict";

const notion = require("./notion");
const { parseShiftDate } = require("./kids-programme-shifts");

const DB_NAME =
  process.env.DAY_MANAGERS_DB || notion.DB.DAY_MANAGERS || "Day Managers";

const STATUS_CANCELLED = "Cancelled";
const STATUS_TBC = "TBC";
const STATUS_CONFIRMED = "Confirmed";

const DAY_MANAGER_PROPERTIES = {
  Name: { title: {} },
  Date: { date: {} },
  Manager: { rich_text: {} },
  Backup: { rich_text: {} },
  Status: {
    select: {
      options: [
        { name: STATUS_CONFIRMED, color: "green" },
        { name: STATUS_TBC, color: "yellow" },
        { name: STATUS_CANCELLED, color: "gray" },
      ],
    },
  },
  Notes: { rich_text: {} },
  "Post to Telegram": { checkbox: {} },
  Source: {
    select: {
      options: [
        { name: "Telegram", color: "blue" },
        { name: "Manual", color: "gray" },
        { name: "Import", color: "brown" },
      ],
    },
  },
};

function pageMatchesDay(page, dateISO) {
  const date = notion.pageDateISO(page, "Date");
  return date === dateISO;
}

function postToTelegram(page) {
  const raw = notion.pagePropText(page, "Post to Telegram").toLowerCase();
  if (raw === "false") return false;
  return true;
}

function parseDayManagerPage(page) {
  const status = notion.pagePropText(page, "Status").trim() || STATUS_CONFIRMED;
  const manager = notion.pagePropText(page, "Manager").trim();
  const backup = notion.pagePropText(page, "Backup").trim();
  const notes = notion.pagePropText(page, "Notes").trim();
  const date = notion.pageDateISO(page, "Date");
  return {
    id: page.id,
    title: notion.pageTitle(page),
    status,
    manager,
    backup,
    notes,
    date,
    postToTelegram: postToTelegram(page),
    url: notion.pageUrl(page),
  };
}

function normalizeStatus(raw) {
  const s = String(raw || "").trim().toLowerCase();
  if (s === "cancelled" || s === "canceled" || s === "clear" || s === "off")
    return STATUS_CANCELLED;
  if (s === "tbc" || s === "?" || s === "unsure") return STATUS_TBC;
  return STATUS_CONFIRMED;
}

function formatManagerLine(row, { html = false } = {}) {
  if (!row) return "";
  if (row.status === STATUS_CANCELLED) return "";
  const people = [row.manager, row.backup].filter(Boolean).join(" + ");
  const tbc =
    row.status === STATUS_TBC || (!row.manager && !row.backup)
      ? " (TBC)"
      : "";
  const text = people || "TBC";
  let line = html
    ? `• <b>Day manager:</b> ${text}${tbc}`
    : `• Day manager: ${text}${tbc}`;
  if (row.notes) {
    line = html
      ? `${line} <i>(${row.notes})</i>`
      : `${line} (${row.notes})`;
  }
  return line;
}

function formatManagersForRange(rows, { html = false, heading = "" } = {}) {
  const active = rows.filter((r) => r.status !== STATUS_CANCELLED);
  if (!active.length) return "";
  const lines = [];
  if (heading) lines.push(heading, "");
  for (const row of active) {
    const dayLabel = row.date || "";
    const people = [row.manager, row.backup].filter(Boolean).join(" + ") || "TBC";
    const tbc = row.status === STATUS_TBC ? " (TBC)" : "";
    const bullet = html
      ? `• ${dayLabel}: ${people}${tbc}`
      : `• ${dayLabel}: ${people}${tbc}`;
    lines.push(bullet);
  }
  return lines.join("\n");
}

async function fetchManagerForDay(dateISO, { dbName = DB_NAME } = {}) {
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(dbName));
  } catch (err) {
    err.message = `Day managers DB (${dbName}): ${err.message}`;
    throw err;
  }
  const matches = pages
    .filter((p) => pageMatchesDay(p, dateISO) && postToTelegram(p))
    .map(parseDayManagerPage)
    .filter((r) => r.status !== STATUS_CANCELLED);
  if (!matches.length) return null;
  return matches[0];
}

async function fetchManagersBetween(startISO, endISO, { dbName = DB_NAME } = {}) {
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
    const row = parseDayManagerPage(page);
    if (row.status === STATUS_CANCELLED) continue;
    out.push(row);
  }
  out.sort((a, b) => (a.date < b.date ? -1 : a.date > b.date ? 1 : 0));
  return out;
}

/**
 * Create or update the day manager row for one calendar day (at most one active row per date).
 */
async function upsertDayManager(
  { date, manager, backup, status, notes, clear },
  { dbName = DB_NAME, todayISO, tz = "Europe/Lisbon" } = {}
) {
  const dateISO = parseShiftDate(date, { todayISO, tz });
  let shiftStatus = clear ? STATUS_CANCELLED : normalizeStatus(status);

  let managerName = manager ? String(manager).trim().slice(0, 120) : "";
  let backupName = backup ? String(backup).trim().slice(0, 120) : "";
  const noteText = notes ? String(notes).trim().slice(0, 500) : "";

  const { pages } = await notion.queryDatabase(dbName);
  const existing = pages.find((p) => pageMatchesDay(p, dateISO));

  if (!clear && shiftStatus !== STATUS_CANCELLED && !managerName && !backupName) {
    shiftStatus = STATUS_TBC;
  }

  const props = {
    Date: dateISO,
    Manager: managerName,
    Backup: backupName,
    Status: shiftStatus,
    Notes: noteText,
    "Post to Telegram": shiftStatus !== STATUS_CANCELLED,
    Source: "Telegram",
  };

  if (existing) {
    const prevNotes = notion.pagePropText(existing, "Notes").trim();
    if (!noteText && prevNotes) props.Notes = prevNotes;
    if (!backupName) {
      const prevBackup = notion.pagePropText(existing, "Backup").trim();
      if (prevBackup) props.Backup = prevBackup;
    }
    if (!managerName && shiftStatus !== STATUS_CANCELLED) {
      const prevManager = notion.pagePropText(existing, "Manager").trim();
      if (prevManager) props.Manager = prevManager;
    }
    await notion.updatePageProps(dbName, existing.id, props);
    return {
      updated: true,
      id: existing.id,
      url: notion.pageUrl(existing),
      dateISO,
      manager: props.Manager || managerName,
      backup: props.Backup || backupName,
      status: shiftStatus,
    };
  }

  const title = `${dateISO} · ${managerName || backupName || "TBC"}`.slice(0, 200);
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
    manager: managerName,
    backup: backupName,
    status: shiftStatus,
  };
}

async function ensureDayManagersDatabase() {
  const catalog = notion.loadCatalog();
  catalog.databases = catalog.databases || {};
  catalog.pages = catalog.pages || {};

  const parentId =
    catalog.pages?.Calendars ||
    catalog.pages?.["Documentation hub"] ||
    catalog.parentPageId;
  if (!parentId) {
    throw new Error(
      "No Calendars / parent page in the Notion catalog. Run setup-vila-akoya-notion.js first."
    );
  }

  const db = await notion.createDatabase({
    parentPageId: parentId,
    title: DB_NAME,
    properties: DAY_MANAGER_PROPERTIES,
  });
  catalog.databases[DB_NAME] = db.id;
  await notion.primeDatabase(DB_NAME, db.id);
  notion.saveCatalog(catalog);

  try {
    await notion.notion("PATCH", `/databases/${db.id}`, {
      properties: DAY_MANAGER_PROPERTIES,
    });
    notion.clearDatabaseCache(DB_NAME);
    await notion.primeDatabase(DB_NAME, db.id);
  } catch (err) {
    if (!/already exists/i.test(err.message)) {
      console.warn(`Day managers property patch: ${err.message}`);
    }
  }

  try {
    await notion.ensureCalendarView(db.id, {
      name: "Calendar",
      dateProperty: "Date",
    });
  } catch (err) {
    console.warn(`Day managers calendar view skipped: ${err.message}`);
  }

  notion.saveCatalog(catalog);
  return { db, url: db.url, wasCreated: db.wasCreated };
}

module.exports = {
  DB_NAME,
  STATUS_TBC,
  STATUS_CONFIRMED,
  STATUS_CANCELLED,
  DAY_MANAGER_PROPERTIES,
  parseDayManagerPage,
  fetchManagerForDay,
  fetchManagersBetween,
  formatManagerLine,
  formatManagersForRange,
  upsertDayManager,
  ensureDayManagersDatabase,
};