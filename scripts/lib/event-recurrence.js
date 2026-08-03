/**
 * Event recurrence for Vila Akoya gatherings.
 *
 * Canonical Monday "Recurrence" text values:
 *   weekly:Sun
 *   weekly:Sat,Sun
 *   monthly:1st-Sat
 *   monthly:3rd-Sun
 *   monthly:last-Fri
 *   daily
 *
 * Also accepts common natural phrases when reading from Notion or chat.
 */

"use strict";

const DEFAULT_TZ = "Europe/Lisbon";

const WEEKDAY_ALIASES = {
  sun: "Sun",
  sunday: "Sun",
  domingo: "Sun",
  mon: "Mon",
  monday: "Mon",
  segunda: "Mon",
  "segunda-feira": "Mon",
  tue: "Tue",
  tuesday: "Tue",
  tues: "Tue",
  terça: "Tue",
  terca: "Tue",
  "terça-feira": "Tue",
  "terca-feira": "Tue",
  wed: "Wed",
  wednesday: "Wed",
  quarta: "Wed",
  "quarta-feira": "Wed",
  thu: "Thu",
  thursday: "Thu",
  thurs: "Thu",
  quinta: "Thu",
  "quinta-feira": "Thu",
  fri: "Fri",
  friday: "Fri",
  sexta: "Fri",
  "sexta-feira": "Fri",
  sat: "Sat",
  saturday: "Sat",
  sábado: "Sat",
  sabado: "Sat",
};

const ORDINAL_ALIASES = {
  "1": 1,
  "1st": 1,
  first: 1,
  primeiro: 1,
  primeira: 1,
  "2": 2,
  "2nd": 2,
  second: 2,
  segundo: 2,
  segunda: 2,
  "3": 3,
  "3rd": 3,
  third: 3,
  terceiro: 3,
  terceira: 3,
  "4": 4,
  "4th": 4,
  fourth: 4,
  quarto: 4,
  quarta: 4,
  last: -1,
  último: -1,
  ultimo: -1,
  última: -1,
  ultima: -1,
};

const WEEKDAY_ORDER = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

function addDaysISO(iso, days, tz = DEFAULT_TZ) {
  const [y, m, d] = iso.split("-").map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d + days, 12));
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: tz,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(dt);
}

function dateParts(iso, tz = DEFAULT_TZ) {
  const d = new Date(`${iso}T12:00:00`);
  const weekday = new Intl.DateTimeFormat("en-US", {
    timeZone: tz,
    weekday: "short",
  }).format(d);
  const day = Number(
    new Intl.DateTimeFormat("en-US", { timeZone: tz, day: "numeric" }).format(d)
  );
  const month = Number(
    new Intl.DateTimeFormat("en-US", { timeZone: tz, month: "numeric" }).format(d)
  );
  const year = Number(
    new Intl.DateTimeFormat("en-US", { timeZone: tz, year: "numeric" }).format(d)
  );
  return { weekday, day, month, year };
}

function daysInMonth(year, month) {
  return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

function normalizeWeekday(raw) {
  if (!raw) return null;
  const key = String(raw).trim().toLowerCase();
  return WEEKDAY_ALIASES[key] || null;
}

function normalizeOrdinal(raw) {
  if (raw == null) return null;
  const key = String(raw).trim().toLowerCase();
  if (Object.prototype.hasOwnProperty.call(ORDINAL_ALIASES, key)) {
    return ORDINAL_ALIASES[key];
  }
  return null;
}

function canonicalWeekdays(list) {
  const seen = new Set();
  const out = [];
  for (const w of list) {
    const n = normalizeWeekday(w);
    if (n && !seen.has(n)) {
      seen.add(n);
      out.push(n);
    }
  }
  return out.sort((a, b) => WEEKDAY_ORDER.indexOf(a) - WEEKDAY_ORDER.indexOf(b));
}

/**
 * @returns {{ freq: 'daily'|'weekly'|'monthly', weekdays?: string[], nth?: number, weekday?: string } | null}
 */
function parseRecurrence(raw) {
  if (!raw || typeof raw !== "string") return null;
  const text = raw.trim();
  if (!text) return null;

  const compact = text.match(
    /^(daily|weekly|monthly)\s*:\s*(.+)$/i
  );
  if (compact) {
    const freq = compact[1].toLowerCase();
    const rest = compact[2].trim();
    if (freq === "daily") return { freq: "daily" };
    if (freq === "weekly") {
      const weekdays = canonicalWeekdays(rest.split(/[,+/|&]+|\s+and\s+/i));
      if (!weekdays.length) return null;
      return { freq: "weekly", weekdays };
    }
    if (freq === "monthly") {
      const m = rest.match(/^(1st|2nd|3rd|4th|last|first|second|third|fourth|\d+)\s*[- ]\s*([A-Za-zçÇáàãâéêíóôõúü]+)$/i);
      if (!m) return null;
      const nth = normalizeOrdinal(m[1]);
      const weekday = normalizeWeekday(m[2]);
      if (!nth || !weekday) return null;
      return { freq: "monthly", nth, weekday };
    }
  }

  const lower = text.toLowerCase().replace(/\s+/g, " ").trim();

  if (/^(every\s+)?day(s)?$/.test(lower) || lower === "daily") {
    return { freq: "daily" };
  }

  // monthly: "3rd Sunday of the month", "every first Saturday", "primeiro sábado do mês"
  const monthly = lower.match(
    /(?:every\s+)?(1st|2nd|3rd|4th|last|first|second|third|fourth|primeiro|primeira|segundo|segunda|terceiro|terceira|quarto|quarta|último|ultimo|última|ultima)\s+(sunday|monday|tuesday|wednesday|thursday|friday|saturday|domingo|segunda(?:-feira)?|ter[cç]a(?:-feira)?|quarta(?:-feira)?|quinta(?:-feira)?|sexta(?:-feira)?|s[aá]bado)(?:\s+(?:of\s+)?(?:the\s+)?month|do\s+m[eê]s)?/
  );
  if (monthly) {
    const nth = normalizeOrdinal(monthly[1]);
    const weekday = normalizeWeekday(monthly[2]);
    if (nth && weekday) return { freq: "monthly", nth, weekday };
  }

  // weekly: "every Sunday", "weekly on Sat", "todos os sábados"
  const weekly = lower.match(
    /(?:every|each|weekly(?:\s+on)?|todos?\s+os?)\s+(sunday|monday|tuesday|wednesday|thursday|friday|saturday|domingo|segunda(?:-feira)?s?|ter[cç]a(?:-feira)?s?|quarta(?:-feira)?s?|quinta(?:-feira)?s?|sexta(?:-feira)?s?|s[aá]bado)s?\b/
  );
  if (weekly) {
    const weekday = normalizeWeekday(weekly[1].replace(/s$/, ""));
    if (weekday) return { freq: "weekly", weekdays: [weekday] };
  }

  // bare weekday with "every" already handled; "Sundays" alone
  const bare = lower.match(
    /^(sunday|monday|tuesday|wednesday|thursday|friday|saturday)s$/
  );
  if (bare) {
    const weekday = normalizeWeekday(bare[1]);
    if (weekday) return { freq: "weekly", weekdays: [weekday] };
  }

  return null;
}

function formatRecurrence(rule) {
  if (!rule) return "";
  if (rule.freq === "daily") return "daily";
  if (rule.freq === "weekly") {
    return `weekly:${(rule.weekdays || []).join(",")}`;
  }
  if (rule.freq === "monthly") {
    const ord =
      rule.nth === -1
        ? "last"
        : rule.nth === 1
          ? "1st"
          : rule.nth === 2
            ? "2nd"
            : rule.nth === 3
              ? "3rd"
              : "4th";
    return `monthly:${ord}-${rule.weekday}`;
  }
  return "";
}

function describeRecurrence(rule) {
  if (!rule) return "";
  if (rule.freq === "daily") return "every day";
  if (rule.freq === "weekly") {
    const days = rule.weekdays || [];
    if (days.length === 1) return `every ${days[0]}`;
    if (days.length === 2) return `every ${days[0]} and ${days[1]}`;
    return `every ${days.slice(0, -1).join(", ")} and ${days[days.length - 1]}`;
  }
  if (rule.freq === "monthly") {
    const ord =
      rule.nth === -1
        ? "last"
        : rule.nth === 1
          ? "1st"
          : rule.nth === 2
            ? "2nd"
            : rule.nth === 3
              ? "3rd"
              : "4th";
    return `every ${ord} ${rule.weekday} of the month`;
  }
  return formatRecurrence(rule);
}

function weekdayOccurrenceInMonth(iso, tz = DEFAULT_TZ) {
  const { weekday, day, month, year } = dateParts(iso, tz);
  const nth = Math.ceil(day / 7);
  const isLast = day + 7 > daysInMonth(year, month);
  return { weekday, nth, isLast };
}

function occursOn(rule, dateISO, tz = DEFAULT_TZ) {
  if (!rule || !dateISO) return false;
  if (rule.freq === "daily") return true;

  const parts = dateParts(dateISO, tz);

  if (rule.freq === "weekly") {
    return (rule.weekdays || []).includes(parts.weekday);
  }

  if (rule.freq === "monthly") {
    if (parts.weekday !== rule.weekday) return false;
    const occ = weekdayOccurrenceInMonth(dateISO, tz);
    if (rule.nth === -1) return occ.isLast;
    return occ.nth === rule.nth;
  }

  return false;
}

function expandBetween(rule, startISO, endISO, tz = DEFAULT_TZ) {
  if (!rule || !startISO || !endISO || startISO > endISO) return [];
  const dates = [];
  let day = startISO;
  while (day <= endISO) {
    if (occursOn(rule, day, tz)) dates.push(day);
    day = addDaysISO(day, 1, tz);
  }
  return dates;
}

function nextOccurrence(rule, fromISO, tz = DEFAULT_TZ) {
  if (!rule || !fromISO) return null;
  // Search up to ~14 months for monthly/last patterns.
  let day = fromISO;
  for (let i = 0; i < 450; i++) {
    if (occursOn(rule, day, tz)) return day;
    day = addDaysISO(day, 1, tz);
  }
  return null;
}

function normalizeRecurrenceField(raw) {
  const rule = parseRecurrence(raw);
  if (!rule) return { rule: null, canonical: "", label: "" };
  return {
    rule,
    canonical: formatRecurrence(rule),
    label: describeRecurrence(rule),
  };
}

module.exports = {
  DEFAULT_TZ,
  parseRecurrence,
  formatRecurrence,
  describeRecurrence,
  occursOn,
  expandBetween,
  nextOccurrence,
  normalizeRecurrenceField,
  addDaysISO,
  dateParts,
  weekdayOccurrenceInMonth,
};

if (require.main === module) {
  const assert = (cond, msg) => {
    if (!cond) throw new Error(msg);
  };

  assert(formatRecurrence(parseRecurrence("3rd Sunday of the month")) === "monthly:3rd-Sun", "3rd Sunday");
  assert(formatRecurrence(parseRecurrence("first Saturday")) === "monthly:1st-Sat", "1st Sat");
  assert(formatRecurrence(parseRecurrence("every Sunday")) === "weekly:Sun", "weekly Sun");
  assert(formatRecurrence(parseRecurrence("monthly:last-Fri")) === "monthly:last-Fri", "last Fri");
  assert(occursOn(parseRecurrence("monthly:3rd-Sun"), "2026-07-19"), "Jul 19 2026 is 3rd Sun");
  assert(!occursOn(parseRecurrence("monthly:3rd-Sun"), "2026-07-12"), "Jul 12 is 2nd Sun");
  assert(occursOn(parseRecurrence("monthly:1st-Sat"), "2026-08-01"), "Aug 1 2026 is 1st Sat");
  assert(occursOn(parseRecurrence("weekly:Sat"), "2026-07-25"), "weekly Sat");
  assert(nextOccurrence(parseRecurrence("monthly:3rd-Sun"), "2026-07-20") === "2026-08-16", "next 3rd Sun");
  console.log("event-recurrence self-check OK");
}
