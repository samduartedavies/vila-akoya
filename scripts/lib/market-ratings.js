/**
 * Community market ratings (1–10) stored in Notion; averages synced to External Events when possible.
 */

"use strict";

const notion = require("./notion");
const {
  normalizePlaceForDedupe,
  findMarketsMatchingText,
  marketPlaceAliases,
  foldAccents,
} = require("./regional-markets");

const DB_NAME = process.env.MARKET_RATINGS_DB || "Market ratings";
const EXTERNAL_EVENTS = notion.DB.EXTERNAL_EVENTS;

const AVG_PROP = "Market avg (1-10)";
const COUNT_PROP = "Market ratings #";

const CACHE_MS = 2 * 60 * 1000;
let aggregateCache = { at: 0, byPlaceKey: new Map() };

const PLACE_HINT_SKIP = new Set([
  "i ll start",
  "ill start",
  "hey",
  "start",
  "markets",
  "market",
  "rating",
  "ratings",
  "vava",
  "so that",
]);

function todayISO(tz = "Europe/Lisbon") {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: tz,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}

function parseMarketRatingsFromText(text) {
  const cleaned = String(text || "")
    .replace(/@\w+/g, " ")
    .replace(/\s+/g, " ")
    .trim();
  if (!cleaned) return [];

  const ratings = [];
  const re = /([a-zA-ZÀ-ÿ][a-zA-ZÀ-ÿ0-9\s.'ºª-]{2,48}?)\s*:\s*(\d{1,2})(?:\s*\/\s*10)?(?=\s|$|[,.;!?])/gi;
  let match;
  while ((match = re.exec(cleaned)) !== null) {
    let hint = match[1].trim();
    const score = parseInt(match[2], 10);
    if (score < 1 || score > 10) continue;

    hint = hint.replace(/^.*\b(i\s*ll\s*start|i'll\s*start|start)\s*:?\s*/i, "").trim();
    hint = hint.replace(/^.*\bmarket\s+in\s+/i, "").trim();
    const foldedHint = foldAccents(hint);
    if (!hint || hint.length < 3) continue;
    if (PLACE_HINT_SKIP.has(foldedHint)) continue;
    if (/\b(shall we|so that|giving ratings)\b/i.test(hint)) continue;

    ratings.push({ hint, score });
  }
  return ratings;
}

function looksLikeMarketRatingMessage(text) {
  if (parseMarketRatingsFromText(text).length > 0) return true;
  const t = foldAccents(text);
  return (
    /\b(rate|rating|score|scored)\b/.test(t) &&
    /\b(market|mercado|feira)\b/.test(t) &&
    /\d/.test(text)
  );
}

function looksLikeMarketRatingQuery(text) {
  const t = foldAccents(text);
  return (
    /\b(rating|score|how good|how bad|worth it|recommend)\b/.test(t) &&
    /\b(market|mercado|feira)\b/.test(t)
  );
}

function normalizeHintForMatch(hint) {
  return foldAccents(hint)
    .replace(/\bs\s*\.\s*/g, "sao ")
    .replace(/\s+/g, " ")
    .trim();
}

function scoreMarketMatch(hint, market) {
  const h = normalizeHintForMatch(hint);
  let best = 0;
  for (const alias of marketPlaceAliases(market.place)) {
    const a = alias.replace(/\bs\s*\.\s*/g, "sao ");
    if (!a) continue;
    if (h === a) best = Math.max(best, 120);
    else if (a.includes(h) || h.includes(a)) best = Math.max(best, 60 + Math.min(a.length, h.length));
  }
  const hw = h.split(/\s+/).filter((w) => w.length > 2);
  const pw = foldAccents(market.place).replace(/\bs\s*\.\s*/g, "sao ").split(/\s+/);
  for (const w of hw) {
    if (pw.some((p) => p === w || p.includes(w) || w.includes(p))) best += 12;
  }
  if (/\bbarao\b/.test(h) && /\bbarao\b/.test(foldAccents(market.place))) best += 25;
  return best;
}

function resolveRatingTarget(hint, catalog) {
  const folded = foldAccents(hint);
  if (/\bfatacil\b/.test(folded)) {
    const hits = catalog.filter((m) =>
      foldAccents(`${m.place} ${m.locale}`).includes("fatacil")
    );
    if (hits.length) {
      return { place: hits[0].place, placeKey: normalizePlaceForDedupe(hits[0].place) };
    }
  }

  const pseudo = `market at ${hint}`;
  const matches = findMarketsMatchingText(pseudo, catalog);
  if (!matches.length) {
    return { place: hint.trim(), placeKey: normalizePlaceForDedupe(hint), unknownPlace: true };
  }

  const ranked = [...matches].sort((a, b) => scoreMarketMatch(hint, b) - scoreMarketMatch(hint, a));
  const place = ranked[0].place;
  return { place, placeKey: normalizePlaceForDedupe(place), unknownPlace: false };
}

function computeAggregate(scores) {
  if (!scores.length) return { avg: null, count: 0 };
  const sum = scores.reduce((a, b) => a + b, 0);
  return { avg: Math.round((sum / scores.length) * 10) / 10, count: scores.length };
}

function aggregatesFromPages(pages) {
  const byKey = new Map();
  for (const page of pages) {
    const placeKey =
      notion.pagePropText(page, "Market key").trim() ||
      normalizePlaceForDedupe(notion.pagePropText(page, "Market"));
    const scoreRaw = notion.pagePropText(page, "Score");
    const score = Number(scoreRaw);
    if (!placeKey || !Number.isFinite(score) || score < 1 || score > 10) continue;
    const display = notion.pagePropText(page, "Market").trim() || placeKey;
    if (!byKey.has(placeKey)) {
      byKey.set(placeKey, { place: display, scores: [] });
    }
    byKey.get(placeKey).scores.push(score);
  }
  const out = new Map();
  for (const [key, row] of byKey) {
    out.set(key, { ...computeAggregate(row.scores), place: row.place });
  }
  return out;
}

async function loadRatingAggregates({ force = false } = {}) {
  if (!force && Date.now() - aggregateCache.at < CACHE_MS) {
    return aggregateCache.byPlaceKey;
  }
  try {
    const { pages } = await notion.queryDatabase(DB_NAME);
    aggregateCache = { at: Date.now(), byPlaceKey: aggregatesFromPages(pages) };
  } catch {
    aggregateCache = { at: Date.now(), byPlaceKey: new Map() };
  }
  return aggregateCache.byPlaceKey;
}

function invalidateRatingCache() {
  aggregateCache.at = 0;
}

async function syncAggregateToExternalEvents(place, placeKey, agg) {
  if (!agg?.count) return;
  try {
    const { pages } = await notion.queryDatabase(EXTERNAL_EVENTS);
    for (const page of pages) {
      const loc = notion.pagePropText(page, "Location");
      const title = notion.pageTitle(page);
      const type = notion.pagePropText(page, "Event type");
      const isMarket =
        /^market\b/i.test(type) ||
        /^market\s*[—–-]/i.test(title) ||
        /^(flea|farmers?)\s+market/i.test(title);
      if (!isMarket) continue;
      const key = normalizePlaceForDedupe(loc || title);
      if (key !== placeKey) continue;
      const props = {};
      if (agg.avg != null) props[AVG_PROP] = agg.avg;
      props[COUNT_PROP] = agg.count;
      await notion.updatePageProps(EXTERNAL_EVENTS, page.id, props);
    }
  } catch {
    // Optional columns may be missing until setup script runs
  }
}

async function recordMarketRating({ place, placeKey, score, reviewer, comment = "" }) {
  const title = `${place} — ${reviewer}`.slice(0, 180);
  await notion.createPage({
    databaseName: DB_NAME,
    title,
    properties: {
      Market: place,
      "Market key": placeKey,
      Score: score,
      Reviewer: reviewer,
      Date: todayISO(),
      Source: "Telegram",
      ...(comment ? { Notes: comment } : {}),
    },
  });
  invalidateRatingCache();
  const aggregates = await loadRatingAggregates({ force: true });
  const agg = aggregates.get(placeKey) || computeAggregate([score]);
  await syncAggregateToExternalEvents(place, placeKey, agg);
  return agg;
}

async function recordMarketRatingsFromMessage({ text, reviewer, catalog }) {
  const parsed = parseMarketRatingsFromText(text);
  const results = [];
  for (const { hint, score } of parsed) {
    const target = resolveRatingTarget(hint, catalog);
    const agg = await recordMarketRating({
      place: target.place,
      placeKey: target.placeKey,
      score,
      reviewer,
    });
    results.push({
      hint,
      place: target.place,
      score,
      unknownPlace: target.unknownPlace,
      avg: agg.avg,
      count: agg.count,
    });
  }
  return results;
}

function formatRatingSummary(agg) {
  if (!agg || !agg.count) return "";
  return `★ ${agg.avg}/10 (${agg.count} review${agg.count === 1 ? "" : "s"})`;
}

function enrichCatalogWithRatings(catalog, aggregates) {
  if (!aggregates || !aggregates.size) return catalog;
  return catalog.map((entry) => {
    const key = normalizePlaceForDedupe(entry.place);
    const agg = aggregates.get(key);
    if (!agg) return entry;
    return { ...entry, ratingAvg: agg.avg, ratingCount: agg.count };
  });
}

module.exports = {
  DB_NAME,
  AVG_PROP,
  COUNT_PROP,
  parseMarketRatingsFromText,
  looksLikeMarketRatingMessage,
  looksLikeMarketRatingQuery,
  resolveRatingTarget,
  loadRatingAggregates,
  recordMarketRating,
  recordMarketRatingsFromMessage,
  formatRatingSummary,
  enrichCatalogWithRatings,
  invalidateRatingCache,
};
