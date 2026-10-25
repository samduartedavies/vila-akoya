/**
 * Regional market listings (Algarve + Odemira poster).
 * Source: https://www.myguidealgarve.com/usefulinfo/markets-on-the-algarve
 */

"use strict";

const { normalizeRecurrenceField } = require("./event-recurrence");

const MARKET_KIND = {
  FLEA: "flea",
  FARMERS: "farmers",
};

const SOURCE_ALGARVE = "My Guide Algarve";
const SOURCE_ODEMIRA = "Odemira Markets poster";

/** @typedef {{ place: string, kind: 'flea'|'farmers', recurrence: string, locale?: string, municipality?: string, hours?: string, source?: string }} MarketRow */

function foldAccents(text) {
  return String(text || "")
    .normalize("NFD")
    .replace(/\p{M}/gu, "")
    .toLowerCase();
}

function marketKindLabel(kind) {
  if (kind === MARKET_KIND.FLEA) return "Flea markets";
  return "Farmers & gypsy markets";
}

function marketKindHeading(kind) {
  if (kind === MARKET_KIND.FLEA) return "Flea markets";
  return "Farmers & gypsy markets";
}

function parseKindFromText(text) {
  const t = foldAccents(text);
  if (/\bflea\b|\bcar\s+boot\b/.test(t)) return MARKET_KIND.FLEA;
  if (/\bfarmers?\b|\bgypsy\b|\bmercado\b/.test(t)) return MARKET_KIND.FARMERS;
  return null;
}

function parseKindFromNotionPage({ title, notes, eventType }) {
  const fromNotes = String(notes || "").match(/Market kind:\s*(flea|farmers)/i);
  if (fromNotes) return fromNotes[1].toLowerCase() === "flea" ? MARKET_KIND.FLEA : MARKET_KIND.FARMERS;
  const fromTitle = parseKindFromText(title);
  if (fromTitle) return fromTitle;
  if (/^market\b/i.test(String(eventType || ""))) return MARKET_KIND.FARMERS;
  return MARKET_KIND.FARMERS;
}

function parseLocaleFromNotionNotes(notes) {
  const m = String(notes || "").match(/Venue:\s*(.+?)(?:\n|$)/i);
  return m ? m[1].trim() : "";
}

/** Strip legacy Notion titles ("Market — …", "Flea market — …") for matching. */
function normalizePlaceForDedupe(place) {
  let p = String(place || "");
  p = p.replace(/^(flea|farmers?)\s+market\s*[—–-]\s*/i, "");
  p = p.replace(/^market\s*[—–-]\s*/i, "");
  return foldAccents(p).replace(/\s+/g, " ").trim();
}

function canonicalRecurrence(entry) {
  const info = normalizeRecurrenceField(entry.recurrenceText || entry.recurrence || "");
  return info.canonical || String(entry.recurrenceText || entry.recurrence || "").trim();
}

function catalogDedupeKey(entry) {
  return `${normalizePlaceForDedupe(entry.place)}|${canonicalRecurrence(entry)}`;
}

function isRegionalCatalogEntry(entry) {
  return entry.source === SOURCE_ALGARVE || entry.source === SOURCE_ODEMIRA;
}

function entryRichness(entry) {
  let score = 0;
  if (entry.locale) score += 10;
  if (entry.hours) score += 2;
  if (entry.municipality) score += 1;
  if (isRegionalCatalogEntry(entry)) score += 20;
  return score;
}

function marketEntriesEquivalent(a, b) {
  const ra = canonicalRecurrence(a);
  const rb = canonicalRecurrence(b);
  if (!ra || !rb || ra !== rb) return false;
  return normalizePlaceForDedupe(a.place) === normalizePlaceForDedupe(b.place);
}

/** Prefer the regional catalog row (venue, kind) over legacy Notion seeds. */
function mergeMarketEntries(regional, notionLike) {
  const primary =
    isRegionalCatalogEntry(regional) && !isRegionalCatalogEntry(notionLike)
      ? regional
      : isRegionalCatalogEntry(notionLike) && !isRegionalCatalogEntry(regional)
        ? notionLike
        : entryRichness(regional) >= entryRichness(notionLike)
          ? regional
          : notionLike;
  const secondary = primary === regional ? notionLike : regional;
  return {
    ...secondary,
    ...primary,
    place: primary.place || secondary.place,
    kind: primary.kind || secondary.kind,
    locale: primary.locale || secondary.locale,
    hours: primary.hours || secondary.hours,
    municipality: primary.municipality || secondary.municipality,
    recurrenceText: primary.recurrenceText || secondary.recurrenceText,
    date: secondary.date || primary.date,
    title: secondary.title || primary.title,
    source: primary.source || secondary.source,
  };
}

function findEquivalentDuplicatePairs(catalog) {
  const pairs = [];
  for (let i = 0; i < catalog.length; i++) {
    for (let j = i + 1; j < catalog.length; j++) {
      if (marketEntriesEquivalent(catalog[i], catalog[j])) pairs.push([catalog[i], catalog[j]]);
    }
  }
  return pairs;
}

/**
 * Expand compact rows (one row may map to several recurrence rules).
 * @param {Array<{ place: string, kind: string, locale?: string, municipality?: string, hours?: string, recurrences: string[] }>} rows
 */
function expandRows(rows, source) {
  const out = [];
  for (const row of rows) {
    for (const recurrence of row.recurrences) {
      const info = normalizeRecurrenceField(recurrence);
      if (!info.rule) throw new Error(`Bad recurrence for ${row.place}: ${recurrence}`);
      out.push({
        place: row.place,
        kind: row.kind,
        locale: row.locale || "",
        municipality: row.municipality || "",
        hours: row.hours || "",
        recurrence: info.canonical,
        recurrenceText: info.canonical,
        source,
      });
    }
  }
  return out;
}

const ALGARVE_FLEA_ROWS = [
  {
    place: "Caliços, Albufeira",
    municipality: "Albufeira",
    locale: "In front of the municipal market",
    recurrences: ["monthly:2nd-Sat", "monthly:3rd-Sat"],
  },
  {
    place: "São João, Albufeira",
    municipality: "Albufeira",
    locale: "Near the municipal market",
    recurrences: ["monthly:4th-Sat"],
  },
  {
    place: "Olhos de Água",
    municipality: "Albufeira",
    locale: "Torre da Medronheira",
    recurrences: ["monthly:1st-Sun"],
  },
  {
    place: "Aljezur town",
    municipality: "Aljezur",
    locale: "Elementary School Primária dos Vales",
    recurrences: ["monthly:1st-Sun"],
  },
  {
    place: "Lagoa town",
    municipality: "Lagoa",
    locale: "FATACIL showground",
    hours: "8am–1pm",
    recurrences: ["monthly:4th-Sun"],
  },
  {
    place: "Ferragudo, Lagoa",
    municipality: "Lagoa",
    locale: "Ferragudo centre",
    hours: "8am–1pm",
    recurrences: ["monthly:2nd-Sun"],
  },
  {
    place: "Porches",
    municipality: "Lagoa",
    locale: "Associação Cultural Desportiva Recreativa",
    recurrences: ["monthly:3rd-Sun"],
  },
  {
    place: "Lagos city",
    municipality: "Lagos",
    locale: "Sports ground car park",
    recurrences: ["monthly:1st-Sun"],
  },
  {
    place: "Barão de S. João, Lagos",
    municipality: "Lagos",
    locale: "Next to the football field",
    recurrences: ["monthly:4th-Sun"],
  },
  {
    place: "Chinicato, Lagos",
    municipality: "Lagos",
    recurrences: ["monthly:2nd-Sun"],
  },
  {
    place: "Almancil, Loulé",
    municipality: "Loulé",
    locale: "Near the school (Escola C+S)",
    recurrences: ["monthly:2nd-Sun", "monthly:3rd-Sun", "monthly:5th-Sun"],
  },
  {
    place: "Fuzeta, Olhão",
    municipality: "Olhão",
    locale: "By the campsite",
    recurrences: ["monthly:1st-Sun"],
  },
  {
    place: "Quelfes, Olhão",
    municipality: "Olhão",
    locale: "In front of the school EB 1",
    recurrences: ["monthly:4th-Sun", "monthly:5th-Sun"],
  },
  {
    place: "Portimão city",
    municipality: "Portimão",
    locale: "Parque de Feiras e Exposições",
    recurrences: ["monthly:1st-Sun", "monthly:3rd-Sun"],
  },
  {
    place: "Silves city",
    municipality: "Silves",
    locale: "By the municipal pool",
    recurrences: ["monthly:3rd-Sat"],
  },
  {
    place: "Algoz, Silves",
    municipality: "Silves",
    locale: "Largo da Várzea",
    recurrences: ["monthly:1st-Sat"],
  },
  {
    place: "São Brás de Alportel town",
    municipality: "S. Brás de Alportel",
    locale: "Park Roberto Nobre",
    recurrences: ["monthly:3rd-Sun"],
  },
  {
    place: "Tavira city",
    municipality: "Tavira",
    locale: "Municipal market",
    recurrences: ["monthly:1st-Sat", "monthly:5th-Sat"],
  },
  {
    place: "Vila Real de Santo António town",
    municipality: "VRSA",
    locale: "Praça Marquês de Pombal",
    hours: "10am",
    recurrences: ["monthly:2nd-Sat"],
  },
  {
    place: "Monte Gordo, VRSA",
    municipality: "VRSA",
    locale: "By the tourist office",
    hours: "10am",
    recurrences: ["monthly:4th-Sat"],
  },
  {
    place: "Vila Nova de Cacela, VRSA",
    municipality: "VRSA",
    locale: "Campsite, Caliço",
    recurrences: ["monthly:1st-Sat"],
  },
].map((r) => ({ ...r, kind: MARKET_KIND.FLEA }));

const ALGARVE_FARMERS_ROWS = [
  {
    place: "Albufeira city",
    municipality: "Albufeira",
    locale: "Rua das Escolas",
    hours: "9am–2pm",
    recurrences: ["monthly:1st-Tue", "monthly:3rd-Tue"],
  },
  {
    place: "Ferreiras, Albufeira",
    municipality: "Albufeira",
    locale: "Sítio do Tominhal, next to Auto Jardim",
    hours: "8am–3pm",
    recurrences: ["monthly:2nd-Tue", "monthly:4th-Tue"],
  },
  {
    place: "Guia, Albufeira",
    municipality: "Albufeira",
    hours: "9am–2pm",
    recurrences: ["monthly:3rd-Fri"],
  },
  {
    place: "Paderne, Albufeira",
    municipality: "Albufeira",
    locale: "Pé da Cruz",
    recurrences: ["monthly:1st-Sat"],
  },
  {
    place: "Pereiro, Alcoutim",
    municipality: "Alcoutim",
    locale: "In front of the church",
    hours: "9am–2pm",
    recurrences: ["monthly:4th-Sun"],
  },
  {
    place: "Vaqueiros, Alcoutim",
    municipality: "Alcoutim",
    locale: "Rua do Poço Novo",
    hours: "9am–2pm",
    recurrences: ["monthly:2nd-Thu"],
  },
  {
    place: "Aljezur town",
    municipality: "Aljezur",
    locale: "By the new church",
    hours: "8am–2pm",
    recurrences: ["monthly:3rd-Mon"],
  },
  {
    place: "Rogil, Aljezur",
    municipality: "Aljezur",
    locale: "By the church",
    hours: "8am–1pm",
    recurrences: ["monthly:4th-Sun"],
  },
  {
    place: "Castro Marim town",
    municipality: "Castro Marim",
    hours: "8am–1pm",
    recurrences: ["monthly:2nd-Sat"],
  },
  {
    place: "Faro city",
    municipality: "Faro",
    locale: "Near the municipal theatre",
    recurrences: ["monthly:2nd-Sun"],
  },
  {
    place: "Estói, Faro",
    municipality: "Faro",
    locale: "Junto ao Mercado Abastecedor",
    recurrences: ["monthly:2nd-Sun"],
  },
  {
    place: "Montenegro, Faro",
    municipality: "Faro",
    locale: "In front of the Junta da Freguesia",
    recurrences: ["monthly:1st-Sun"],
  },
  {
    place: "Lagoa town",
    municipality: "Lagoa",
    locale: "FATACIL Showground",
    recurrences: ["monthly:2nd-Sun"],
  },
  {
    place: "Lagos city (general market)",
    municipality: "Lagos",
    locale: "Bus Station",
    recurrences: ["weekly:Sat"],
  },
  {
    place: "Lagos city (gypsy market)",
    municipality: "Lagos",
    locale: "Near the municipal stadium",
    recurrences: ["monthly:1st-Sat"],
  },
  {
    place: "Odiáxere, Lagos",
    municipality: "Lagos",
    locale: "Largo do Moinho",
    recurrences: ["monthly:4th-Mon"],
  },
  {
    place: "Loulé city",
    municipality: "Loulé",
    locale: "In front of Convento de St. António",
    hours: "9am–2pm",
    recurrences: ["weekly:Sat"],
  },
  {
    place: "Almancil, Loulé",
    municipality: "Loulé",
    locale: "By the C+S school",
    recurrences: ["monthly:1st-Sun", "monthly:4th-Sun"],
  },
  {
    place: "Alte, Loulé",
    municipality: "Loulé",
    locale: "Largo José Cavaco Vieira",
    recurrences: ["monthly:3rd-Thu"],
  },
  {
    place: "Ameixial, Loulé",
    municipality: "Loulé",
    locale: "E.N. 2",
    recurrences: ["monthly:1st-Thu"],
  },
  {
    place: "Azinhal, Loulé",
    municipality: "Loulé",
    locale: "As you enter the village",
    hours: "8am–1pm",
    recurrences: ["monthly:1st-Sun"],
  },
  {
    place: "Benafim, Loulé",
    municipality: "Loulé",
    locale: "Rua 25 de Abril",
    recurrences: ["monthly:1st-Sat"],
  },
  {
    place: "Boliqueime, Loulé",
    municipality: "Loulé",
    locale: "Largo de Igreja",
    hours: "8am–2pm",
    recurrences: ["monthly:last-Thu"],
  },
  {
    place: "Cortelha, Loulé",
    municipality: "Loulé",
    recurrences: ["monthly:2nd-Sat"],
  },
  {
    place: "Quarteira, Loulé",
    municipality: "Loulé",
    locale: "Fonte Santa",
    hours: "9am–2.30pm",
    recurrences: ["weekly:Wed"],
  },
  {
    place: "Querença, Loulé",
    municipality: "Loulé",
    locale: "Largo da Igreja Matriz",
    recurrences: ["monthly:last-Sun"],
  },
  {
    place: "Monchique town",
    municipality: "Monchique",
    locale: "Largo do Mercado",
    recurrences: ["monthly:2nd-Fri"],
  },
  {
    place: "Fuzeta",
    municipality: "Olhão",
    locale: "Near the camp site",
    recurrences: ["monthly:1st-Thu"],
  },
  {
    place: "Moncarapacho",
    municipality: "Olhão",
    locale: "Torrinha — near the football ground",
    recurrences: ["monthly:1st-Sun"],
  },
  {
    place: "Quelfes",
    municipality: "Olhão",
    locale: "In front of the school EB1",
    recurrences: ["monthly:4th-Sun", "monthly:5th-Sun"],
  },
  {
    place: "Portimão city",
    municipality: "Portimão",
    locale: "Parque de Feiras e Exposições de Portimão – Caldeira do Moinho",
    recurrences: ["monthly:1st-Mon"],
  },
  {
    place: "Alvor, Portimão",
    municipality: "Portimão",
    locale: "By the fisherman's huts",
    hours: "8am–1pm",
    recurrences: ["weekly:Sun"],
  },
  {
    place: "S. Brás de Alportel town",
    municipality: "S. Brás de Alportel",
    locale: "Rua Boaventura Passos",
    hours: "8am–1pm",
    recurrences: ["weekly:Sat"],
  },
  {
    place: "Silves city",
    municipality: "Silves",
    locale: "Near the cemetery",
    recurrences: ["monthly:3rd-Mon"],
  },
  {
    place: "Silves city (fruit & veg)",
    municipality: "Silves",
    locale: "Around the municipal market",
    recurrences: ["weekly:Sat"],
  },
  {
    place: "Alcantarilha, Silves",
    municipality: "Silves",
    locale: "Village centre",
    recurrences: ["monthly:1st-Fri"],
  },
  {
    place: "Algoz, Silves",
    municipality: "Silves",
    locale: "Largo da Várzea",
    hours: "8am–1pm",
    recurrences: ["monthly:2nd-Mon"],
  },
  {
    place: "São Bartolomeu de Messines, Silves",
    municipality: "Silves",
    locale: "Largo da Feira",
    recurrences: ["monthly:4th-Mon"],
  },
  {
    place: "São Marcos da Serra, Silves",
    municipality: "Silves",
    locale: "Near the cemetery",
    recurrences: ["monthly:1st-Sat"],
  },
  {
    place: "Tunes, Silves",
    municipality: "Silves",
    locale: "Rua 1.º de Maio",
    recurrences: ["monthly:4th-Sat"],
  },
  {
    place: "Tavira city",
    municipality: "Tavira",
    locale: "Ria Formosa Parque de Feiras e Exposições",
    recurrences: ["monthly:3rd-Sat"],
  },
  {
    place: "Santa Catarina, Tavira",
    municipality: "Tavira",
    locale: "Rua 1.º de Maio",
    recurrences: ["monthly:4th-Sun"],
  },
  {
    place: "Vila Real de Santo António town",
    municipality: "VRSA",
    locale: "Praça Marquês de Pombal",
    recurrences: ["monthly:1st-Sun"],
  },
  {
    place: "Vila Nova de Cacela, VRSA",
    municipality: "VRSA",
    locale: "Parque de Feiras",
    recurrences: ["monthly:3rd-Sun"],
  },
  {
    place: "Vila do Bispo town",
    municipality: "Vila do Bispo",
    locale: "In front of the municipal market",
    recurrences: ["monthly:1st-Thu"],
  },
  {
    place: "Barão de São Miguel, VdB",
    municipality: "Vila do Bispo",
    locale: "Rua das Flores",
    recurrences: ["monthly:1st-Mon"],
  },
  {
    place: "Budens, VdB",
    municipality: "Vila do Bispo",
    locale: "Centre of village",
    recurrences: ["monthly:1st-Tue"],
  },
  {
    place: "Raposeira, VdB",
    municipality: "Vila do Bispo",
    locale: "In front of the school",
    recurrences: ["monthly:1st-Wed"],
  },
  {
    place: "Sagres, VdB",
    municipality: "Vila do Bispo",
    locale: "In front of the municipal market",
    recurrences: ["monthly:1st-Fri"],
  },
].map((r) => ({ ...r, kind: MARKET_KIND.FARMERS }));

const ODEMIRA_ROWS = [
  { place: "São Teotónio", recurrences: ["monthly:1st-Mon"] },
  { place: "São Martinho das Amoreiras", recurrences: ["monthly:1st-Sat"] },
  { place: "Azula", recurrences: ["monthly:1st-Sun"] },
  { place: "Colos", recurrences: ["monthly:2nd-Sat"] },
  { place: "Brunheiras", recurrences: ["monthly:2nd-Sat", "monthly:4th-Sat"] },
  { place: "Aldeia à Vista", recurrences: ["monthly:2nd-Sat"] },
  { place: "Vale de Santiago (AMAR)", recurrences: ["monthly:2nd-Sun"] },
  { place: "Algoceira (Mira)", recurrences: ["monthly:3rd-Sat"] },
  { place: "Moitinhas", recurrences: ["monthly:3rd-Sat"] },
  { place: "São Luís", recurrences: ["monthly:3rd-Sun"] },
  { place: "Ourique", recurrences: ["monthly:4th-Sat"] },
  { place: "Bicos", recurrences: ["monthly:last-Sun"] },
  { place: "Milfontes (Benfica)", recurrences: ["weekly:Sun"] },
].map((r) => ({ ...r, kind: MARKET_KIND.FARMERS }));

const STATIC_ALGARVE_MARKETS = expandRows(ALGARVE_FLEA_ROWS, SOURCE_ALGARVE).concat(
  expandRows(ALGARVE_FARMERS_ROWS, SOURCE_ALGARVE)
);
const STATIC_ODEMIRA_MARKETS = expandRows(ODEMIRA_ROWS, SOURCE_ODEMIRA);
const STATIC_REGIONAL_MARKETS = STATIC_ALGARVE_MARKETS.concat(STATIC_ODEMIRA_MARKETS);

function notionPageToMarketEntry(page, notionApi) {
  const title = notionApi.pageTitle(page);
  const notes = notionApi.pagePropText(page, "Notes");
  const eventType = notionApi.pagePropText(page, "Event type");
  const locationField = notionApi.pagePropText(page, "Location").trim();
  const fromTitle = String(title || "")
    .replace(/^(flea|farmers?)\s+market\s*[—–-]\s*/i, "")
    .replace(/^market\s*[—–-]\s*/i, "")
    .trim();
  const place = locationField || fromTitle;
  const venue = parseLocaleFromNotionNotes(notes);
  const locale = venue || (locationField && locationField !== place ? locationField : "");
  const recurrenceText =
    notionApi.pagePropText(page, "Recurrence").trim() ||
    (notes.match(/\b((?:daily|weekly|monthly):[A-Za-z0-9,_-]+)\b/i) || [])[1] ||
    "";
  return {
    title,
    place,
    kind: parseKindFromNotionPage({ title, notes, eventType }),
    locale,
    recurrenceText,
    date: notionApi.pageDateISO(page, "Date"),
    source: notionApi.pagePropText(page, "Source") || "Notion",
  };
}

function mergeMarketCatalogs(notionEntries) {
  const catalog = STATIC_REGIONAL_MARKETS.map((entry) => ({ ...entry }));

  for (const notionEntry of notionEntries) {
    const idx = catalog.findIndex((e) => marketEntriesEquivalent(e, notionEntry));
    if (idx >= 0) {
      catalog[idx] = mergeMarketEntries(catalog[idx], notionEntry);
    } else {
      catalog.push({ ...notionEntry });
    }
  }

  return catalog.sort(
    (a, b) =>
      a.place.localeCompare(b.place) || (a.recurrenceText || "").localeCompare(b.recurrenceText || "")
  );
}

function formatMarketBullet(entry, { includeRecurrence = false } = {}) {
  const parts = [entry.place];
  if (entry.locale) parts.push(`— ${entry.locale}`);
  if (entry.hours) parts.push(`(${entry.hours})`);
  if (includeRecurrence && entry.recurrenceText) {
    const info = normalizeRecurrenceField(entry.recurrenceText);
    if (info.label) parts.push(`[${info.label}]`);
  }
  return parts.join(" ");
}

function groupMarketsByKind(markets) {
  const flea = [];
  const farmers = [];
  for (const m of markets) {
    if (m.kind === MARKET_KIND.FLEA) flea.push(m);
    else farmers.push(m);
  }
  return { flea, farmers };
}

function formatMarketsListAnswer(markets, { dayLabel, dateISO } = {}) {
  const { flea, farmers } = groupMarketsByKind(markets);
  const header = dayLabel
    ? `Markets on ${dayLabel} (${dateISO}):`
    : "Markets:";
  const sections = [];
  if (flea.length) {
    sections.push(`${marketKindHeading(MARKET_KIND.FLEA)}:\n${flea.map((m) => `• ${formatMarketBullet(m)}`).join("\n")}`);
  }
  if (farmers.length) {
    sections.push(
      `${marketKindHeading(MARKET_KIND.FARMERS)}:\n${farmers.map((m) => `• ${formatMarketBullet(m)}`).join("\n")}`
    );
  }
  if (!sections.length) return "";
  return `${header}\n\n${sections.join("\n\n")}`;
}

function looksLikeMarketLocationQuestion(text) {
  const t = foldAccents(text);
  return (
    /\b(where|location|located|address|venue|find it|how do i get|which street|what street)\b/.test(t) &&
    /\b(market|mercado|feira|farmers|flea)\b/.test(t)
  );
}

module.exports = {
  MARKET_KIND,
  STATIC_REGIONAL_MARKETS,
  marketKindLabel,
  marketKindHeading,
  notionPageToMarketEntry,
  mergeMarketCatalogs,
  mergeMarketEntries,
  marketEntriesEquivalent,
  findEquivalentDuplicatePairs,
  normalizePlaceForDedupe,
  formatMarketBullet,
  formatMarketsListAnswer,
  groupMarketsByKind,
  looksLikeMarketLocationQuestion,
  parseKindFromNotionPage,
  foldAccents: foldAccents,
};

if (require.main === module) {
  const assert = (cond, msg) => {
    if (!cond) throw new Error(msg);
  };

  const legacyOdemiraNotion = STATIC_REGIONAL_MARKETS
    .filter((m) => m.source === SOURCE_ODEMIRA)
    .map((m) => ({
      title: `Market — ${m.place}`,
      place: m.place,
      kind: m.kind,
      locale: "",
      recurrenceText: m.recurrenceText,
      source: "Seed",
    }));

  const legacyTitleOnly = legacyOdemiraNotion.map((m) => ({
    ...m,
    place: m.title,
    title: m.title,
  }));

  const merged = mergeMarketCatalogs(legacyOdemiraNotion.concat(legacyTitleOnly));
  assert(
    merged.length === STATIC_REGIONAL_MARKETS.length,
    `expected ${STATIC_REGIONAL_MARKETS.length} markets after dedupe, got ${merged.length}`
  );
  assert(
    findEquivalentDuplicatePairs(merged).length === 0,
    "duplicate equivalent markets remain in catalog"
  );

  const milfontes = merged.find((m) => normalizePlaceForDedupe(m.place).includes("milfontes"));
  assert(milfontes && merged.filter((m) => marketEntriesEquivalent(m, milfontes)).length === 1, "Milfontes deduped");

  console.log("regional-markets self-check OK");
}
