/**
 * Guest stays booked off-site (email, phone, walk-in) and logged via VAVA.
 * One Notion row per stay. Website WooCommerce bookings stay on Campsite Bookings;
 * this table is merged into VAVA's schedule / morning recap so both sources show.
 */

"use strict";

const notion = require("./notion");

function addDaysISO(iso, days) {
  const [y, m, d] = String(iso || "").split("-").map(Number);
  if (!y || !m || !d) return "";
  const dt = new Date(Date.UTC(y, m - 1, d + days, 12));
  return dt.toISOString().slice(0, 10);
}

const GUEST_STAYS_DB_NAME = process.env.GUEST_STAYS_DB_NAME || notion.DB.GUEST_STAYS;

const STAY_KINDS = ["Campsite", "Accommodation", "Other"];
const STAY_SOURCES = ["Email", "Phone", "Telegram", "Walk-in", "Other"];
const STAY_STATUSES = ["Confirmed", "Tentative", "Cancelled"];

function selectOpts(names) {
  const colors = ["blue", "green", "orange", "yellow", "purple", "pink", "gray", "brown"];
  return names.map((name, i) => ({ name, color: colors[i % colors.length] }));
}

function selectProp(options) {
  return { select: { options: selectOpts(options) } };
}

const GUEST_STAYS_PROPERTIES = {
  Name: { title: {} },
  "Check-in": { date: {} },
  "Check-out": { date: {} },
  Kind: selectProp(STAY_KINDS),
  Unit: { rich_text: {} },
  Party: { rich_text: {} },
  Adults: { number: { format: "number" } },
  Children: { number: { format: "number" } },
  Source: selectProp(STAY_SOURCES),
  Status: selectProp(STAY_STATUSES),
  Notes: { rich_text: {} },
  "Logged by": { rich_text: {} },
};

function isoDate(value) {
  const m = String(value || "").match(/^(\d{4}-\d{2}-\d{2})/);
  return m ? m[1] : "";
}

function guessKind(text) {
  const t = String(text || "").toLowerCase();
  if (/\b(cabin|cabana|room|house|casa|accommodation|alojamento|glamping|yurt|tiny house)\b/.test(t)) {
    return "Accommodation";
  }
  if (/\b(pitch|plot|tent|campsite|camping|van|motorhome|caravan)\b/.test(t)) {
    return "Campsite";
  }
  return "Campsite";
}

function guessSource(text) {
  const t = String(text || "").toLowerCase();
  if (/\b(email|e-mail|mail)\b/.test(t)) return "Email";
  if (/\b(phone|call|telefone|ligou)\b/.test(t)) return "Phone";
  if (/\b(walk-?in|showed up|apareceu)\b/.test(t)) return "Walk-in";
  return "Telegram";
}

function partyLabel({ adults, children, party }) {
  if (party) return String(party).trim();
  const bits = [];
  if (Number(adults) > 0) bits.push(`${adults} adult${Number(adults) === 1 ? "" : "s"}`);
  if (Number(children) > 0) bits.push(`${children} child${Number(children) === 1 ? "" : "ren"}`);
  return bits.join(", ");
}

function isPlotLikeUnit(unit) {
  const u = String(unit || "").trim();
  if (!u) return false;
  return /^(plot|pitch)\s*#?\s*\d+$/i.test(u) || /^#\d+$/.test(u);
}

function formatStayLine(stay) {
  const bits = [stay.guest_name || stay.name || "Guest"];
  if (stay.unit && !isPlotLikeUnit(stay.unit)) bits.push(stay.unit);
  const party = partyLabel(stay);
  if (party) bits.push(party);
  if (stay.kind && stay.kind !== "Other") bits.push(stay.kind.toLowerCase());
  if (stay.source && stay.source !== "Telegram") bits.push(stay.source.toLowerCase());
  return bits.join(" · ");
}

function stayOnDay(stay, dateISO) {
  const checkIn = isoDate(stay.check_in);
  const checkOut = isoDate(stay.check_out) || (checkIn ? addDaysISO(checkIn, 1) : "");
  if (!checkIn || !dateISO) return null;
  if (dateISO === checkIn) return "arrival";
  if (checkOut && dateISO === checkOut) return "departure";
  if (checkOut && dateISO > checkIn && dateISO < checkOut) return "staying";
  return null;
}

function normalizeStay(raw, { sourceText } = {}) {
  if (!raw || typeof raw !== "object") return null;
  const guest =
    String(raw.guest_name || raw.name || raw.title || "").trim().slice(0, 80);
  const checkIn = isoDate(raw.check_in || raw.date);
  if (!guest && !checkIn) return null;
  let checkOut = isoDate(raw.check_out);
  const nights = Number(raw.nights);
  if (!checkOut && checkIn && Number.isFinite(nights) && nights > 0) {
    checkOut = addDaysISO(checkIn, nights);
  }
  if (!checkOut && checkIn) checkOut = addDaysISO(checkIn, 1);
  const kind = STAY_KINDS.includes(raw.kind) ? raw.kind : guessKind(`${raw.unit || ""} ${sourceText || ""}`);
  const source = STAY_SOURCES.includes(raw.source)
    ? raw.source
    : guessSource(sourceText || "");
  const adults = Number(raw.adults);
  const children = Number(raw.children);
  return {
    guest_name: guest || "Guest",
    check_in: checkIn || null,
    check_out: checkOut || null,
    kind,
    unit: raw.unit ? String(raw.unit).trim().slice(0, 80) : "",
    party: partyLabel({
      adults: Number.isFinite(adults) ? adults : null,
      children: Number.isFinite(children) ? children : null,
      party: raw.party,
    }),
    adults: Number.isFinite(adults) ? adults : null,
    children: Number.isFinite(children) ? children : null,
    source,
    notes: raw.notes ? String(raw.notes).slice(0, 800) : "",
    status: STAY_STATUSES.includes(raw.status) ? raw.status : "Confirmed",
  };
}

function pageToStay(page) {
  const status = notion.pagePropText(page, "Status") || "Confirmed";
  if (status === "Cancelled") return null;
  const adults = Number(notion.pagePropText(page, "Adults"));
  const children = Number(notion.pagePropText(page, "Children"));
  return {
    id: page.id,
    url: page.url,
    name: notion.pageTitle(page),
    guest_name: notion.pageTitle(page),
    check_in: notion.pageDateISO(page, "Check-in"),
    check_out: notion.pageDateISO(page, "Check-out"),
    kind: notion.pagePropText(page, "Kind") || "Campsite",
    unit: notion.pagePropText(page, "Unit"),
    party: notion.pagePropText(page, "Party"),
    adults: Number.isFinite(adults) ? adults : null,
    children: Number.isFinite(children) ? children : null,
    source: notion.pagePropText(page, "Source") || "Telegram",
    status,
    notes: notion.pagePropText(page, "Notes"),
  };
}

async function listStays() {
  try {
    const { pages } = await notion.queryDatabase(GUEST_STAYS_DB_NAME);
    return pages.map(pageToStay).filter(Boolean);
  } catch (err) {
    if (/not found/i.test(err.message)) return [];
    throw err;
  }
}

function activityFromStays(stays, dateISO) {
  const arrivals = [];
  const departures = [];
  const staying = [];
  for (const stay of stays) {
    const role = stayOnDay(stay, dateISO);
    if (!role) continue;
    const row = {
      line: formatStayLine(stay),
      name: stay.guest_name || stay.name,
      source: "guest_stay",
    };
    if (role === "arrival") arrivals.push(row);
    else if (role === "departure") departures.push(row);
    else staying.push(row);
  }
  return { arrivals, departures, staying };
}

async function activityForDay(dateISO) {
  const stays = await listStays();
  return activityFromStays(stays, dateISO);
}

function mergeActivity(base, extra) {
  const seen = new Set(
    [...(base.arrivals || []), ...(base.departures || []), ...(base.staying || [])].map((r) =>
      String(r.name || r.line || "")
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, " ")
        .trim()
    )
  );
  const add = (list, rows) => {
    const out = [...(list || [])];
    for (const row of rows || []) {
      const key = String(row.name || row.line || "")
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, " ")
        .trim();
      if (key && seen.has(key)) continue;
      if (key) seen.add(key);
      out.push(row);
    }
    return out;
  };
  return {
    arrivals: add(base.arrivals, extra.arrivals),
    departures: add(base.departures, extra.departures),
    staying: add(base.staying, extra.staying),
  };
}

async function createStay(stay, { loggedBy, notes } = {}) {
  await ensureGuestStaysDatabase();
  const properties = {
    Kind: stay.kind || "Campsite",
    Source: stay.source || "Telegram",
    Status: stay.status || "Confirmed",
    "Logged by": loggedBy || "",
    Notes: [stay.notes, notes].filter(Boolean).join("\n\n"),
  };
  if (stay.check_in) properties["Check-in"] = stay.check_in;
  if (stay.check_out) properties["Check-out"] = stay.check_out;
  if (stay.unit) properties.Unit = stay.unit;
  if (stay.party) properties.Party = stay.party;
  if (stay.adults != null) properties.Adults = stay.adults;
  if (stay.children != null) properties.Children = stay.children;

  const page = await notion.createPage({
    databaseName: GUEST_STAYS_DB_NAME,
    title: stay.guest_name || "Guest",
    properties,
  });
  return { ...page, title: stay.guest_name || "Guest", stay };
}

async function ensureGuestStaysDatabase() {
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
    title: GUEST_STAYS_DB_NAME,
    properties: GUEST_STAYS_PROPERTIES,
  });
  catalog.databases[GUEST_STAYS_DB_NAME] = db.id;
  await notion.primeDatabase(GUEST_STAYS_DB_NAME, db.id);
  notion.saveCatalog(catalog);

  try {
    await notion.notion("PATCH", `/databases/${db.id}`, {
      properties: GUEST_STAYS_PROPERTIES,
    });
    notion.clearDatabaseCache(GUEST_STAYS_DB_NAME);
    await notion.primeDatabase(GUEST_STAYS_DB_NAME, db.id);
  } catch (err) {
    if (!/already exists/i.test(err.message)) {
      console.warn(`Guest stays property patch: ${err.message}`);
    }
  }

  try {
    await notion.ensureTableView(db.id, { name: "All stays" });
  } catch (err) {
    console.warn(`Guest stays table view skipped: ${err.message}`);
  }
  try {
    await notion.ensureCalendarView(db.id, {
      name: "Check-ins",
      dateProperty: "Check-in",
    });
  } catch (err) {
    console.warn(`Guest stays calendar view skipped: ${err.message}`);
  }

  notion.saveCatalog(catalog);
  return { db, url: db.url, wasCreated: db.wasCreated };
}

module.exports = {
  GUEST_STAYS_DB_NAME,
  STAY_KINDS,
  STAY_SOURCES,
  STAY_STATUSES,
  GUEST_STAYS_PROPERTIES,
  guessKind,
  guessSource,
  formatStayLine,
  stayOnDay,
  normalizeStay,
  listStays,
  activityForDay,
  activityFromStays,
  mergeActivity,
  createStay,
  ensureGuestStaysDatabase,
};
