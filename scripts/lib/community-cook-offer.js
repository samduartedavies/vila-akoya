/**
 * One-off community cooking offers (villager making dinner on a specific day).
 * Stored on Internal Events (Shared meal) with a [vava_cook] block in Notes — not recurring.
 */

"use strict";

const MARKER = "[vava_cook]";
const DEFAULT_TIME = "19:30";
const DIETS = ["meat", "veggie", "vegan"];

function addDaysISO(iso, days) {
  const [y, m, d] = String(iso || "").slice(0, 10).split("-").map(Number);
  if (!y || !m || !d) return "";
  const dt = new Date(Date.UTC(y, m - 1, d + days, 12));
  return dt.toISOString().slice(0, 10);
}

function parseMealDate(raw, todayISO, tz = "Europe/Lisbon") {
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

function capitalizeDish(name) {
  const s = String(name || "").trim();
  if (!s) return "Meal";
  return s.charAt(0).toUpperCase() + s.slice(1);
}

function looksLikeCommunityCookOffer(text) {
  const t = String(text || "").toLowerCase();
  if (!t) return false;
  if (/\b(book|sign\s*up|reserve|marcar|reservar)\b/.test(t) && /\b(dinner|meal|jantar)\b/.test(t)) {
    return false;
  }
  const cooks =
    /\b(i'?m|i am|i'll|ill|we'?re|we are)\s+(cooking|making|doing)\b/.test(t) ||
    /\b[a-z][a-z'-]+\s+is\s+(cooking|making|doing)\b/.test(t) ||
    /\b(cooking|making)\s+(a\s+)?[a-z]/.test(t) ||
    /\b(vou|vamos)\s+(cozinhar|fazer|preparar)\b/.test(t);
  const hasDay =
    /\b(today|tomorrow|hoje|amanh[aã])\b/.test(t) ||
    /\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday|segunda|ter[cç]a|quarta|quinta|sexta|s[aá]bado|domingo)\b/.test(
      t
    ) ||
    /\b\d{4}-\d{2}-\d{2}\b/.test(t) ||
    /\bon\s+(mon|tue|wed|thu|fri|sat|sun|monday|tuesday|wednesday)\b/.test(t);
  return cooks && hasDay;
}

function extractDateToken(text) {
  const t = String(text || "");
  const iso = t.match(/\b(\d{4}-\d{2}-\d{2})\b/);
  if (iso) return iso[1];
  const onDay = t.match(
    /\bon\s+(today|tomorrow|monday|tuesday|wednesday|thursday|friday|saturday|sunday|hoje|amanh[aã])\b/i
  );
  if (onDay) return onDay[1];
  const forDay = t.match(
    /\bfor\s+(today|tomorrow|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i
  );
  if (forDay) return forDay[1];
  const weekday = t.match(
    /\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday|segunda|ter[cç]a|quarta|quinta|sexta|s[aá]bado|domingo)\b/i
  );
  if (weekday) return weekday[1];
  if (/\btoday\b/i.test(t)) return "today";
  if (/\btomorrow\b/i.test(t)) return "tomorrow";
  return null;
}

function capitalizeName(name) {
  const s = String(name || "").trim();
  if (!s) return "";
  return s
    .split(/\s+/)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
    .join(" ");
}

/** Someone else cooking: "Hans is cooking a bolognese today". */
function extractCookFromText(text) {
  const t = String(text || "").trim();
  const patterns = [
    /^([A-Za-z][A-Za-z'\-]+(?:\s+[A-Za-z][A-Za-z'\-]+)?)\s+is\s+(?:cooking|making|doing)\b/i,
    /\b([A-Za-z][A-Za-z'\-]+(?:\s+[A-Za-z][A-Za-z'\-]+)?)'s\s+(?:cooking|making)\b/i,
  ];
  for (const re of patterns) {
    const m = t.match(re);
    if (m?.[1]) {
      const who = capitalizeName(m[1]);
      if (!/^(i|we|me|my)$/i.test(who)) return who;
    }
  }
  return null;
}

function extractDishFromText(text) {
  const t = String(text || "").trim();
  const namedCook = t.match(
    /^[A-Za-z][A-Za-z'\-]+(?:\s+[A-Za-z][A-Za-z'\-]+)?\s+is\s+(?:cooking|making|doing)\s+(?:a\s+)?(.+?)\s+(?:today|tomorrow|on\s+|for\s+|\d{4}-\d{2}-\d{2})/i
  );
  if (namedCook?.[1]) {
    let dish = namedCook[1].trim().replace(/\s+for\s+the\s+village.*$/i, "");
    dish = dish.replace(/^(some|a)\s+/i, "");
    if (dish.length >= 2) return capitalizeDish(dish);
  }
  const patterns = [
    /\b(?:i'?m|i am|i'll|ill|we'?re|we are)\s+(?:cooking|making|doing)\s+(?:a\s+)?(.+?)\s+(?:on|for|this)\s+/i,
    /\b(?:cooking|making)\s+(?:a\s+)?(.+?)\s+(?:on|for|this)\s+/i,
    /\b(?:cooking|making)\s+(?:a\s+)?(.+?)\s+today\b/i,
    /\b(?:vou|vamos)\s+(?:cozinhar|fazer|preparar)\s+(?:um[a]?\s+)?(.+?)\s+(?:na|no|para|em)\s+/i,
    /\bmake\s+(?:a\s+)?(.+?)\s+on\s+/i,
  ];
  for (const re of patterns) {
    const m = t.match(re);
    if (m?.[1]) {
      let dish = m[1].trim().replace(/\s+for\s+the\s+village.*$/i, "");
      dish = dish.replace(/^(some|a)\s+/i, "");
      if (dish.length >= 2) return capitalizeDish(dish);
    }
  }
  return null;
}

function parseTimeFromText(text) {
  const m = String(text || "").match(/\b(?:at\s+)?(\d{1,2}:\d{2})\b/);
  if (m) {
    const [h, min] = m[1].split(":").map(Number);
    if (h >= 0 && h <= 23 && min >= 0 && min <= 59) {
      return `${String(h).padStart(2, "0")}:${String(min).padStart(2, "0")}`;
    }
  }
  return DEFAULT_TIME;
}

function normalizeMealOffer(raw, { text, todayISO, tz } = {}) {
  if (!raw && !text) return null;
  const src = raw && typeof raw === "object" ? raw : {};
  const dish = capitalizeDish(src.dish || extractDishFromText(text));
  const dateToken = src.date || extractDateToken(text || "");
  const dateISO = dateToken ? parseMealDate(dateToken, todayISO, tz) : null;
  if (!dish || !dateISO) return null;

  let diet = String(src.diet || "").trim().toLowerCase();
  if (diet === "vegetarian") diet = "veggie";
  if (!DIETS.includes(diet)) diet = null;

  let priceEur = null;
  if (src.price_eur != null && src.price_eur !== "") {
    const n = Number(String(src.price_eur).replace(",", "."));
    if (Number.isFinite(n) && n >= 0) priceEur = n;
  }

  const time = String(src.time || parseTimeFromText(text || "")).trim() || DEFAULT_TIME;

  return {
    dish,
    dateISO,
    time,
    diet,
    priceEur,
    cook: src.cook
      ? capitalizeName(src.cook)
      : extractCookFromText(text) || null,
  };
}

function parseDietFromReply(text) {
  const t = String(text || "").trim().toLowerCase();
  if (/^3\b|vegan/.test(t)) return "vegan";
  if (/^2\b|veggie|vegetarian/.test(t)) return "veggie";
  if (/^1\b|meat|carniv|carne/.test(t)) return "meat";
  if (DIETS.includes(t)) return t;
  return null;
}

function parsePriceFromReply(text) {
  const t = String(text || "").trim().toLowerCase();
  if (!t) return null;
  if (/^(0|free|gr[aá]tis|gratis|nothing|no charge)$/.test(t)) return 0;
  const m = t.match(/(\d+(?:[.,]\d{1,2})?)/);
  if (!m) return null;
  const n = Number(m[1].replace(",", "."));
  return Number.isFinite(n) && n >= 0 ? n : null;
}

function buildNotesBlock(offer) {
  const lines = [
    MARKER,
    `cook: ${offer.cook || "Someone"}`,
    `dish: ${offer.dish}`,
    `diet: ${offer.diet}`,
    `price_eur: ${offer.priceEur}`,
    `time: ${offer.time}`,
    `date: ${offer.dateISO}`,
    "",
    "One-off community meal (not recurring). Logged via VAVA.",
  ];
  if (offer.sourceText) {
    lines.push("", `Original message: ${String(offer.sourceText).slice(0, 400)}`);
  }
  return lines.join("\n");
}

function parseOfferFromNotes(notes) {
  if (!String(notes || "").includes(MARKER)) return null;
  const get = (key) => {
    const m = String(notes).match(new RegExp(`^${key}:\\s*(.+)$`, "im"));
    return m ? m[1].trim() : "";
  };
  const price = get("price_eur");
  return {
    cook: get("cook"),
    dish: get("dish"),
    diet: get("diet"),
    priceEur: price === "" ? null : Number(price),
    time: get("time") || DEFAULT_TIME,
    dateISO: get("date"),
  };
}

function formatPriceLabel(priceEur) {
  if (priceEur === 0 || priceEur === "0") return "free";
  if (priceEur == null || Number.isNaN(priceEur)) return "price TBC";
  const n = Number(priceEur);
  if (n === Math.floor(n)) return `€${n} per portion`;
  return `€${n.toFixed(2)} per portion`;
}

function isCompleteCookOffer(offer) {
  if (!offer || typeof offer !== "object") return false;
  const cook = String(offer.cook || "").trim();
  const dish = String(offer.dish || "").trim();
  if (!cook || !dish) return false;
  if (/\[|placeholder|tbc|unknown/i.test(cook) || /\[/i.test(dish)) return false;
  if (!DIETS.includes(String(offer.diet || "").toLowerCase())) return false;
  if (offer.priceEur == null || Number.isNaN(Number(offer.priceEur))) return false;
  return true;
}

function formatRecapLine(offer) {
  const diet =
    offer.diet && offer.diet !== "meat"
      ? ` (${offer.diet === "veggie" ? "veggie" : offer.diet})`
      : offer.diet === "meat"
        ? " (meat)"
        : "";
  const price = formatPriceLabel(offer.priceEur);
  const time = offer.time || DEFAULT_TIME;
  return `${offer.cook} is making ${offer.dish}${diet} today for ${price}, at ${time}`;
}

function dietPromptMessage() {
  return (
    "What kind of meal is it?\n" +
    "1 — meat\n" +
    "2 — veggie\n" +
    "3 — vegan"
  );
}

function pricePromptMessage() {
  return "How much per portion in euros? (Reply <b>0</b> or <b>free</b> if it’s free.)";
}

function confirmMessage(offer) {
  const diet =
    offer.diet === "veggie"
      ? "veggie"
      : offer.diet === "vegan"
        ? "vegan"
        : "meat";
  const price = formatPriceLabel(offer.priceEur);
  return (
    `✅ Got it — I’ll put this in the morning recap on <b>${offer.dateISO}</b>:\n` +
    `<i>${offer.cook} is making ${offer.dish} (${diet}) for ${price}, at ${offer.time}</i>`
  );
}

module.exports = {
  MARKER,
  DEFAULT_TIME,
  DIETS,
  looksLikeCommunityCookOffer,
  normalizeMealOffer,
  parseMealDate,
  parseDietFromReply,
  parsePriceFromReply,
  buildNotesBlock,
  parseOfferFromNotes,
  formatRecapLine,
  dietPromptMessage,
  pricePromptMessage,
  confirmMessage,
  capitalizeDish,
  isCompleteCookOffer,
};
