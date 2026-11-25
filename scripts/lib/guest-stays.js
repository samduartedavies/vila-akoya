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
const PAYMENT_STATUSES = ["Unpaid", "Paid", "Deposit", "Waived"];
const CHECKIN_DETAILS_STATUSES = ["Pending", "Received"];

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
  Plot: { number: { format: "number" } },
  Payment: selectProp(PAYMENT_STATUSES),
  "Check-in details": selectProp(CHECKIN_DETAILS_STATUSES),
  Notes: { rich_text: {} },
  "Logged by": { rich_text: {} },
  "Last updated by": { rich_text: {} },
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

function normalizeText(s) {
  return String(s || "")
    .toLowerCase()
    .normalize("NFD")
    .replace(/\p{M}/gu, "")
    .replace(/[^a-z0-9]+/g, " ")
    .trim();
}

function words(s) {
  return normalizeText(s).split(/\s+/).filter((w) => w.length > 1);
}

function parsePlotNumber(raw) {
  if (raw == null || raw === "") return null;
  if (typeof raw === "number" && Number.isFinite(raw) && raw > 0) return Math.round(raw);
  const s = String(raw).trim();
  const direct = Number(s.replace(",", "."));
  if (Number.isFinite(direct) && direct > 0 && /^\d+([.,]\d+)?$/.test(s)) {
    return Math.round(direct);
  }
  const m =
    s.match(/\b(?:plot|pitch|parcela|lote)\s*#?\s*(\d{1,3})\b/i) ||
    s.match(/^#?(\d{1,3})$/);
  return m ? Number(m[1]) : null;
}

function parsePaymentStatus(raw, text = "") {
  if (PAYMENT_STATUSES.includes(raw)) return raw;
  const blob = `${String(raw || "")} ${String(text || "")}`.toLowerCase();
  if (/\b(unpaid|not paid|hasn'?t paid|haven'?t paid|nao pagou|não pagou|por pagar)\b/.test(blob)) {
    return "Unpaid";
  }
  if (/\b(deposit|sinal|entrada)\b/.test(blob)) return "Deposit";
  if (/\b(waived|free stay|complimentary|comped|oferta|gratis|grátis)\b/.test(blob)) return "Waived";
  if (
    /\b(paid|has paid|payment received|pagou|pago|transfer|multibanco)\b/.test(blob) ||
    raw === true ||
    raw === "true"
  ) {
    return "Paid";
  }
  if (raw === false || raw === "false") return "Unpaid";
  return null;
}

function parseCheckInDetailsStatus(raw, text = "") {
  if (CHECKIN_DETAILS_STATUSES.includes(raw)) return raw;
  const blob = `${String(raw || "")} ${String(text || "")}`.toLowerCase();
  if (
    /\b(pending|not (?:yet )?(?:submitted|received|done)|hasn'?t|haven'?t|missing|awaiting|sem registo|ainda não)\b/.test(
      blob
    ) &&
    /\b(check[- ]?in|registration|registo|details|dados|guest form)\b/.test(blob)
  ) {
    return "Pending";
  }
  if (
    /\b(received|submitted|filled in|completed|done|has check[- ]?in|registo feito|enviou)\b/.test(
      blob
    ) &&
    /\b(check[- ]?in|registration|registo|details|dados|guest form)\b/.test(blob)
  ) {
    return "Received";
  }
  if (raw === true || raw === "true" || /^yes$/i.test(String(raw || ""))) return "Received";
  if (raw === false || raw === "false" || /^no$/i.test(String(raw || ""))) return "Pending";
  return null;
}

function plotDisplay(plot) {
  const n = Number(plot);
  return Number.isFinite(n) && n > 0 ? `Plot ${n}` : "";
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
  const plotLabel = plotDisplay(stay.plot);
  if (plotLabel) bits.push(plotLabel);
  else if (stay.unit && !isPlotLikeUnit(stay.unit)) bits.push(stay.unit);
  else if (stay.unit && isPlotLikeUnit(stay.unit)) bits.push(stay.unit);
  const party = partyLabel(stay);
  if (party) bits.push(party);
  if (stay.payment && stay.payment !== "Unpaid") bits.push(stay.payment.toLowerCase());
  if (stay.check_in_details === "Pending") bits.push("check-in pending");
  else if (stay.check_in_details === "Received") bits.push("check-in received");
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
    plot: Number(notion.pagePropText(page, "Plot")) || null,
    payment: notion.pagePropText(page, "Payment") || null,
    check_in_details:
      notion.pagePropText(page, "Check-in details") || CHECKIN_DETAILS_STATUSES[0],
  };
}

function scoreGuestMatch(query, page, checkInISO) {
  const q = normalizeText(query);
  if (!q) return 0;
  const title = normalizeText(notion.pageTitle(page));
  if (title === q) return 100;
  if (title.includes(q) || q.includes(title)) return 82;
  const qWords = words(q);
  if (!qWords.length) return 0;
  const hits = qWords.filter((w) => title.includes(w));
  if (!hits.length) return 0;
  let score = 40 + Math.round((hits.length / qWords.length) * 35);
  if (checkInISO) {
    const cin = notion.pageDateISO(page, "Check-in");
    const cout = notion.pageDateISO(page, "Check-out");
    if (cin === checkInISO) score += 25;
    else if (cout === checkInISO) score += 12;
  }
  return score;
}

async function findStayMatches({ guestName, checkIn } = {}) {
  const guest = String(guestName || "").trim();
  if (!guest) return [];
  const checkInISO = isoDate(checkIn);

  const { pages } = await notion.queryDatabase(GUEST_STAYS_DB_NAME).catch(() => ({ pages: [] }));
  const scored = pages
    .map((page) => {
      const mapped = pageToStay(page);
      if (!mapped) return null;
      return { stay: mapped, score: scoreGuestMatch(guest, page, checkInISO) };
    })
    .filter((row) => row && row.score >= 45)
    .sort((a, b) => b.score - a.score);

  if (!scored.length) return [];

  if (checkInISO) {
    const onDate = scored.filter((row) => row.stay.check_in === checkInISO);
    if (onDate.length === 1) return [onDate[0].stay];
    if (onDate.length > 1) return onDate.map((row) => row.stay);
  }

  if (scored.length === 1) return [scored[0].stay];
  if (scored[0].score >= 85 && (scored[1]?.score ?? 0) < 70) {
    return [scored[0].stay];
  }

  const best = scored[0].score;
  const close = scored.filter((row) => row.score >= best - 8 && row.score >= 60);
  return (close.length > 1 ? close : scored.slice(0, 3)).map((row) => row.stay);
}

function extractStayUpdate(text) {
  const raw = String(text || "").trim();
  if (!raw) return null;
  if (
    !/\b(plot|pitch|parcela|lote|paid|unpaid|payment|pagou|pago|por pagar|deposit|sinal|check[- ]?in details|guest registration|registo)\b/i.test(
      raw
    )
  ) {
    return null;
  }

  let guest = "";
  const patterns = [
    /(?:put|move|assign)\s+(.+?)\s+(?:on|to)\s+(?:plot|pitch)\s*#?\s*(\d{1,3})\b/i,
    /(.+?)\s+(?:on|→|->)\s+(?:plot|pitch)\s*#?\s*(\d{1,3})\b/i,
    /(?:plot|pitch)\s*#?\s*(\d{1,3})\s+for\s+(.+?)(?:\.|$)/i,
    /(?:mark|set)\s+(.+?)\s+(?:as\s+)?(paid|unpaid|deposit|waived)\b/i,
    /(.+?)\s+has(?:n't| not)?\s+paid\b/i,
    /(.+?)\s+(?:paid|pagou|pago)\b/i,
    /(.+?)\s+(?:submitted|sent|completed)\s+(?:their\s+)?(?:check[- ]?in(?:\s+details)?|registration|registo)\b/i,
    /(?:mark|set)\s+(.+?)\s+check[- ]?in\s+(?:details\s+)?(?:as\s+)?(received|pending)\b/i,
    /(.+?)\s+(?:hasn'?t|has not|haven'?t)\s+(?:submitted|done)\s+(?:their\s+)?(?:guest\s+)?(?:check[- ]?in(?:\s+details)?|registration|registo)\b/i,
  ];

  let plot = null;
  let payment = null;
  let checkInDetails = null;
  for (let i = 0; i < patterns.length; i++) {
    const m = raw.match(patterns[i]);
    if (!m) continue;
  if (i === 6) {
      guest = String(m[1] || "").trim();
      checkInDetails = "Received";
      break;
    }
    if (i === 7) {
      guest = String(m[1] || "").trim();
      checkInDetails = parseCheckInDetailsStatus(m[2], raw);
      break;
    }
    if (i === 8) {
      guest = String(m[1] || "").trim();
      checkInDetails = "Pending";
      break;
    }
    if (i === 0 || i === 1) {
      guest = String(m[1] || "").trim().replace(/^(the|family)\s+/i, "");
      plot = parsePlotNumber(m[2]);
      break;
    }
    if (i === 2) {
      plot = parsePlotNumber(m[1]);
      guest = String(m[2] || "").trim();
      break;
    }
    if (i === 3) {
      guest = String(m[1] || "").trim();
      payment = parsePaymentStatus(m[2], raw);
      break;
    }
    if (i === 4) {
      guest = String(m[1] || "").trim();
      payment = "Unpaid";
      break;
    }
    if (i === 5) {
      guest = String(m[1] || "")
        .trim()
        .replace(/\s+(family|familia|família)$/i, "");
      payment = parsePaymentStatus("paid", raw);
      break;
    }
  }

  guest = guest.replace(/[.,!?]+$/, "").trim();
  payment = payment || parsePaymentStatus(null, raw);
  plot = plot ?? parsePlotNumber(raw);
  checkInDetails =
    checkInDetails || parseCheckInDetailsStatus(null, raw);

  if (!guest || (plot == null && !payment && !checkInDetails)) return null;
  return {
    guest_name: guest.slice(0, 80),
    check_in: null,
    plot,
    payment,
    check_in_details: checkInDetails,
    unit: "",
    notes: "",
  };
}

function appendNote(existing, line) {
  const next = existing ? `${existing}\n${line}` : line;
  return next.slice(-1800);
}

async function updateStayRecord(
  fields,
  { updatedBy, sourceText, todayISO: today } = {}
) {
  await ensureGuestStaysDatabase();
  const guest = String(fields.guest_name || "").trim();
  if (!guest) return { ok: false, reason: "no_guest" };

  const plot = fields.plot != null ? parsePlotNumber(fields.plot) : null;
  const payment =
    fields.payment != null && fields.payment !== ""
      ? parsePaymentStatus(fields.payment, sourceText)
      : null;
  const unit = fields.unit ? String(fields.unit).trim().slice(0, 80) : "";
  const checkInDetails =
    fields.check_in_details != null && fields.check_in_details !== ""
      ? parseCheckInDetailsStatus(fields.check_in_details, sourceText)
      : null;
  if (plot == null && !payment && !unit && !checkInDetails) {
    return { ok: false, reason: "no_changes" };
  }

  const matches = await findStayMatches({
    guestName: guest,
    checkIn: fields.check_in,
  });
  if (!matches.length) return { ok: false, reason: "not_found", guest };
  if (matches.length > 1) {
    return {
      ok: false,
      reason: "ambiguous",
      matches: matches.map((s) => ({
        name: s.guest_name || s.name,
        check_in: s.check_in,
        check_out: s.check_out,
        url: s.url,
      })),
    };
  }

  const chosen = matches[0];
  const props = {
    "Last updated by": updatedBy || "",
  };
  const changed = [];
  if (plot != null) {
    props.Plot = plot;
    props.Unit = plotDisplay(plot);
    changed.push(plotDisplay(plot));
  } else if (unit) {
    props.Unit = unit;
    changed.push(unit);
  }
  if (payment) {
    props.Payment = payment;
    changed.push(`Payment: ${payment}`);
  }
  if (checkInDetails) {
    props["Check-in details"] = checkInDetails;
    changed.push(`Check-in details: ${checkInDetails}`);
  }
  if (fields.notes) {
    props.Notes = appendNote(chosen.notes, fields.notes);
  }
  const stamp = `[Telegram ${today || new Date().toISOString().slice(0, 10)}${updatedBy ? ` — ${updatedBy}` : ""}] ${changed.join(", ")}${sourceText ? `\n${String(sourceText).slice(0, 400)}` : ""}`;
  props.Notes = appendNote(props.Notes || chosen.notes, stamp);

  await notion.updatePageProps(GUEST_STAYS_DB_NAME, chosen.id, props);

  return {
    ok: true,
    stay: {
      ...chosen,
      plot: plot ?? chosen.plot,
      payment: payment || chosen.payment,
      check_in_details: checkInDetails || chosen.check_in_details,
      unit: props.Unit || chosen.unit,
    },
    changed,
    url: chosen.url,
    name: chosen.guest_name || chosen.name,
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
  const plotNum = parsePlotNumber(stay.plot ?? stay.unit);
  if (plotNum != null) {
    properties.Plot = plotNum;
    if (!stay.unit || isPlotLikeUnit(stay.unit)) properties.Unit = plotDisplay(plotNum);
  }
  const payment = parsePaymentStatus(stay.payment, stay.notes || "");
  if (payment) properties.Payment = payment;
  else properties.Payment = "Unpaid";
  const checkInDetails =
    parseCheckInDetailsStatus(stay.check_in_details, stay.notes || "") || "Pending";
  properties["Check-in details"] = checkInDetails;

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
  PAYMENT_STATUSES,
  CHECKIN_DETAILS_STATUSES,
  GUEST_STAYS_PROPERTIES,
  parsePlotNumber,
  parsePaymentStatus,
  parseCheckInDetailsStatus,
  plotDisplay,
  extractStayUpdate,
  findStayMatches,
  updateStayRecord,
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
