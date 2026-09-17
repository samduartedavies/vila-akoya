/**
 * "Where things are" — portable village items (keys, tools, chargers…).
 * Notion is the source of truth; VAVA reads and writes the same table.
 */

"use strict";

const notion = require("./notion");

const LOCATIONS_DB_NAME = process.env.LOCATIONS_DB_NAME || notion.DB.LOCATIONS;
const HUB_PAGE_TITLE = "Where things are";

const ITEM_KINDS = [
  "Keys",
  "Tool",
  "Supplies",
  "Vehicle",
  "Document",
  "Furniture",
  "Other",
];

const LOCATION_AREAS = [
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

const STATUS_OPTIONS = ["Active", "Missing", "Archived"];
const UPDATE_KINDS = ["current", "usual", "both", "missing"];

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

function selectProp(options) {
  return { select: { options: selectOpts(options) } };
}

const LOCATIONS_PROPERTIES = {
  Name: { title: {} },
  "Also known as": { rich_text: {} },
  "Current location": { rich_text: {} },
  "Usual place": { rich_text: {} },
  Kind: selectProp(ITEM_KINDS),
  Area: selectProp(LOCATION_AREAS),
  Status: selectProp(STATUS_OPTIONS),
  "Last seen": { date: {} },
  "Last updated by": { rich_text: {} },
  Notes: { rich_text: {} },
};

const SAMPLE_ITEMS = [
  {
    title: "Bathroom keys",
    aliases: "toilet keys, wc keys, keys to the bathrooms, chave das casas de banho",
    kind: "Keys",
    area: "Compost & toilets",
  },
  {
    title: "Drill",
    aliases: "berbequim, power drill",
    kind: "Tool",
    area: "Tools / workshop",
  },
];

function normalize(s) {
  return String(s || "")
    .toLowerCase()
    .replace(/[’']/g, "")
    .replace(/[^a-z0-9àáâãçéêíóôõúüñ\s]+/gi, " ")
    .replace(/\s+/g, " ")
    .trim();
}

function words(s) {
  return normalize(s)
    .split(" ")
    .filter((w) => w.length > 1 && !["the", "a", "an", "our", "to", "of", "da", "do", "das", "de"].includes(w));
}

function guessItemKind(name) {
  const n = normalize(name);
  if (/\b(keys?|chave|chaves)\b/.test(n)) return "Keys";
  if (/\b(drill|berbequim|saw|hammer|ladder|charger|battery|tool)\b/.test(n)) return "Tool";
  if (/\b(car|van|bike|tractor|wheelbarrow)\b/.test(n)) return "Vehicle";
  if (/\b(first aid|sawdust|soap|paper|fuel|gas)\b/.test(n)) return "Supplies";
  if (/\b(folder|manual|book|papers?)\b/.test(n)) return "Document";
  return "Other";
}

function looksLikeLocationQuestion(text) {
  const t = String(text || "").toLowerCase();
  if (!t) return false;
  if (/\b(how does|how do|como funciona)\b/.test(t)) return false;
  if (
    /\b(happening|yoga|bbq|dinner|check-?in|rota|schedule|events?)\b/.test(t) &&
    !/\b(keys?|drill|tool|chaves?|berbequim)\b/.test(t)
  ) {
    return false;
  }
  return (
    /\bwhere(?:'s| is| are)\b/.test(t) ||
    /\bwhere (?:did|do|can) (?:i|we|you|someone)\b/.test(t) ||
    /\bwhere do we (?:keep|put|store|leave)\b/.test(t) ||
    /\bwhere did (?:the |our )?.{2,40} go\b/.test(t) ||
    /\bcan i find\b/.test(t) ||
    /\b(?:have you|has anyone|anyone) seen\b/.test(t) ||
    /\bknow where .{2,40} (?:is|are)\b/.test(t) ||
    /\bonde\s+(?:est[aá]|est[aã]o|fica|ficam|andei|deixei|pus)\b/u.test(t) ||
    /\bonde (?:est[aá]|est[aã]o|fica|ficam)/.test(t) ||
    /\bd[oó]nde est[aá]/.test(t) ||
    /\bv[ií]ste(?:s)? (?:as |os |a |o )?.{2,40}\b/.test(t) ||
    /\b(list|show) (?:all )?(?:the )?(?:locations|where things are)\b/.test(t) ||
    /\bwhere is everything\b/.test(t)
  );
}

function looksLikeLocationUpdate(text) {
  const t = String(text || "").toLowerCase();
  if (!t) return false;
  if (/\b(broken|leaking|not working|needs? fix|repair)\b/.test(t)) return false;
  return (
    /\b(?:i |we )?(?:just )?(?:left|put(?: them| it)?(?: back)?|moved|placed|returned|hung|stashed)\b/.test(t) ||
    /\b(?:lives?|belongs|goes|is kept|are kept|is stored|are stored)\s+(?:in|on|at|under|by|next)\b/.test(t) ||
    /\b(?:usual|normal|home) place\b/.test(t) ||
    /\b(?:est[aá]|est[aã]o|fica|ficam|vive)\s+(?:no|na|em|junto)/.test(t) ||
    /\b(?:the |our ).{2,40} (?:is|are) (?:in|on|under|by|next to|inside|behind)\b/.test(t) &&
      !/\b(dinner|bbq|yoga|meeting|event|check-?in)\b/.test(t) ||
    /\b(?:is|are) missing\b/.test(t) ||
    /\b(?:lost|can'?t find|cannot find)\s+(?:the |our |my )?.{2,40}\b/.test(t)
  );
}

function extractLocationUpdate(text) {
  const raw = String(text || "").trim();
  if (!raw) return null;
  const missing = raw.match(
    /(?:i |we )?(?:can'?t|cannot|couldn'?t) find (?:the |our |os |as |o |a )?(.+?)(?:[.!?]|$)|\b(?:the |our |os |as |o |a )?(.+?) (?:is|are) missing\b|\blost\s+(?:the |our |my |os |as |o |a )?(.+?)(?:[.!?]|$)/i
  );
  if (missing) {
    const item = (missing[1] || missing[2] || missing[3] || "").trim().replace(/[.,!?]+$/, "");
    if (item.length >= 2) {
      return { item, location: "", update_kind: "missing" };
    }
  }
  const patterns = [
    /(?:i |we )?(?:just )?(?:left|put(?: them| it)?(?: back)?|moved|placed|returned|hung|stashed)\s+(?:the |our |os |as |o |a )?(.+?)\s+(?:in|on|at|under|by|next to|inside|behind|beside)\s+(.+)/i,
    /(?:the |our |os |as |o |a )?(.+?)\s+(?:usual|normal|home) place is\s+(.+)/i,
    /(?:the |our |os |as |o |a )?(.+?)\s+(?:lives?|belongs|goes|is kept|are kept|is stored|are stored)\s+(?:in|on|at|under|by|next to)\s+(.+)/i,
    /(?:the |our |os |as |o |a )?(.+?)\s+(?:is|are)\s+(?:currently )?(?:in|on|at|under|by|next to|inside|behind|beside)\s+(.+)/i,
    /(?:deixei|pus|coloquei)\s+(?:as |os |a |o )?(.+?)\s+(?:no|na|em|junto(?: a)?|ao p[eé] d[eo])\s+(.+)/i,
  ];
  for (const pattern of patterns) {
    const match = raw.match(pattern);
    if (!match?.[1] || !match?.[2]) continue;
    const item = match[1].trim().replace(/[.,!?]+$/, "");
    const location = match[2].trim().replace(/[.,!?]+$/, "");
    if (item.length < 2 || location.length < 2) continue;
    if (item.length > 80 || location.length > 200) continue;
    const usual =
      /\b(lives?|belongs|goes|kept|stored|usual|normal|home place)\b/i.test(raw);
    const left = /\b(left|put|moved|placed|stashed|deixei|pus)\b/i.test(raw);
    return {
      item,
      location,
      update_kind: usual && !left ? "usual" : left ? "current" : "both",
    };
  }
  return null;
}

function extractLocationQuestionItem(text) {
  const raw = String(text || "").trim();
  if (!raw) return "";
  if (/\b(everything|all (?:the )?things|where things are|lista)\b/i.test(raw)) return "";
  const patterns = [
    /where(?:'s| is| are)\s+(?:the |our |os |as |o |a )?(.+?)(?:\?|$)/i,
    /where (?:did|do) (?:i|we|you|someone) (?:leave|put|keep|store)\s+(?:the |our )?(.+?)(?:\?|$)/i,
    /where do we (?:keep|put|store|leave)\s+(?:the |our )?(.+?)(?:\?|$)/i,
    /where did (?:the |our |os |as |o |a )?(.+?) go(?:\?|$)/i,
    /(?:have you|has anyone|anyone) seen\s+(?:the |our |os |as |o |a )?(.+?)(?:\?|$)/i,
    /know where (?:the |our |os |as |o |a )?(.+?) (?:is|are)(?:\?|$)/i,
    /(?:lost|can't find|cannot find)\s+(?:the |our |my |os |as |o |a )?(.+?)(?:\?|$)/i,
    /onde (?:est[aá]|est[aã]o|fica|ficam|andei|deixei|pus)\s+(?:as |os |a |o )?(.+?)(?:\?|$)/i,
    /v[ií]ste(?:s)?\s+(?:as |os |a |o )?(.+?)(?:\?|$)/i,
  ];
  for (const pattern of patterns) {
    const match = raw.match(pattern);
    if (match?.[1]) return match[1].trim().replace(/[.,!?]+$/, "");
  }
  return "";
}

function aliasesOf(page) {
  return notion
    .pagePropText(page, "Also known as")
    .split(/[,;/]|\band\b/i)
    .map((s) => s.trim())
    .filter(Boolean);
}

function scoreItem(query, page) {
  const q = normalize(query);
  if (!q) return 0;
  const name = normalize(notion.pageTitle(page));
  const aliasList = aliasesOf(page).map(normalize);
  if (name === q) return 100;
  if (aliasList.includes(q)) return 92;
  if (name.includes(q) || q.includes(name)) return 80;
  if (aliasList.some((a) => a && (a.includes(q) || q.includes(a)))) return 74;
  const qWords = words(q);
  if (!qWords.length) return 0;
  const hay = [name, ...aliasList].join(" ");
  const hits = qWords.filter((w) => hay.includes(w));
  if (!hits.length) return 0;
  const ratio = hits.length / qWords.length;
  if (ratio >= 1) return 68;
  if (ratio >= 0.5 && hits.length >= 1) return 45;
  return 0;
}

function pageToItem(page) {
  return {
    id: page.id,
    url: page.url,
    name: notion.pageTitle(page),
    aliases: notion.pagePropText(page, "Also known as"),
    current: notion.pagePropText(page, "Current location"),
    usual: notion.pagePropText(page, "Usual place"),
    kind: notion.pagePropText(page, "Kind") || "Other",
    area: notion.pagePropText(page, "Area") || "Other",
    status: notion.pagePropText(page, "Status") || "Active",
    lastSeen: notion.pageDateISO(page, "Last seen"),
    updatedBy: notion.pagePropText(page, "Last updated by"),
    notes: notion.pagePropText(page, "Notes"),
  };
}

async function loadLocationPages() {
  const { pages } = await notion.queryDatabase(LOCATIONS_DB_NAME);
  return pages.filter((p) => {
    if (p.archived) return false;
    return notion.pagePropText(p, "Status") !== "Archived";
  });
}

async function findLocationMatches(query) {
  const q = String(query || "").trim();
  if (!q) return [];
  const pages = await loadLocationPages();
  const scored = pages
    .map((page) => ({ page, score: scoreItem(q, page) }))
    .filter((row) => row.score >= 45)
    .sort((a, b) => b.score - a.score);
  if (!scored.length) return [];
  const best = scored[0].score;
  const close = scored.filter((row) => row.score >= best - 12 && row.score >= 60);
  return (close.length ? close : scored.slice(0, 3)).map((row) => ({
    ...pageToItem(row.page),
    score: row.score,
  }));
}

function inferUpdateKind(raw, text) {
  if (UPDATE_KINDS.includes(raw)) return raw;
  const t = String(text || "");
  if (/\b(missing|can'?t find|cannot find|lost)\b/i.test(t)) return "missing";
  if (/\b(usual|normal|home) place\b|\blives?\b|\bbelongs\b|\bis kept\b|\bis stored\b/i.test(t)) {
    return "usual";
  }
  if (/\b(left|put|moved|placed|stashed|returned)\b/i.test(t)) return "current";
  return "both";
}

function appendNote(existing, stamp) {
  const next = existing ? `${existing}\n${stamp}` : stamp;
  return next.slice(-1800);
}

async function upsertLocation({
  item,
  location,
  usualPlace,
  updateKind,
  itemKind,
  area,
  aliases,
  notes,
  fromName,
  todayISO,
  sourceText,
}) {
  const name = String(item || "").trim().slice(0, 80);
  if (!name) return { ok: false, reason: "no_item" };

  const kind = inferUpdateKind(updateKind, sourceText);
  const matches = await findLocationMatches(name);
  const exact = matches.filter((m) => normalize(m.name) === normalize(name));
  const chosen =
    exact.length === 1
      ? exact[0]
      : matches.length === 1
        ? matches[0]
        : matches.length > 1 && matches[0].score >= 80 && matches[1].score < 70
          ? matches[0]
          : null;

  if (matches.length > 1 && !chosen) {
    return { ok: false, reason: "ambiguous", matches };
  }

  const putBack = /^(back|its? place|the usual place|usual place|home)$/i.test(
    String(location || "").trim()
  );

  if (!chosen) {
    if (kind === "missing" && !location) {
      const current = "";
      const usual = usualPlace || "";
      const page = await notion.createPage({
        databaseName: LOCATIONS_DB_NAME,
        title: name,
        properties: {
          "Also known as": aliases || "",
          "Current location": current,
          "Usual place": usual,
          Kind: ITEM_KINDS.includes(itemKind) ? itemKind : guessItemKind(name),
          Area: LOCATION_AREAS.includes(area) ? area : "Other",
          Status: "Missing",
          "Last seen": todayISO,
          "Last updated by": fromName,
          Notes: notes || `Marked missing from Telegram (${fromName}, ${todayISO})`,
        },
      });
      return {
        ok: true,
        created: true,
        missing: true,
        name,
        url: page.url,
        current,
        usual,
      };
    }

    const current = kind === "usual" ? usualPlace || location || "" : location || "";
    const usual =
      kind === "current" ? usualPlace || current : usualPlace || location || current;
    const page = await notion.createPage({
      databaseName: LOCATIONS_DB_NAME,
      title: name,
      properties: {
        "Also known as": aliases || "",
        "Current location": current,
        "Usual place": usual,
        Kind: ITEM_KINDS.includes(itemKind) ? itemKind : guessItemKind(name),
        Area: LOCATION_AREAS.includes(area) ? area : "Other",
        Status: "Active",
        "Last seen": todayISO,
        "Last updated by": fromName,
        Notes: notes || `Added from Telegram (${fromName}, ${todayISO})`,
      },
    });
    return {
      ok: true,
      created: true,
      name,
      url: page.url,
      current,
      usual,
    };
  }

  let current = chosen.current;
  let usual = chosen.usual;
  let status = chosen.status === "Archived" ? "Active" : chosen.status;

  if (kind === "missing") {
    status = "Missing";
  } else {
    status = "Active";
    const loc = putBack ? chosen.usual || location : location;
    if (kind === "usual") {
      usual = loc || usualPlace || usual;
      if (!current) current = usual;
    } else if (kind === "both") {
      current = loc || current;
      usual = usualPlace || loc || usual;
    } else {
      current = loc || current;
      if (!usual) usual = current;
      if (usualPlace) usual = usualPlace;
    }
  }

  const stamp = `[${fromName}, ${todayISO}] ${
    kind === "missing"
      ? "marked missing"
      : kind === "usual"
        ? `usual place → ${usual}`
        : `last seen → ${current}`
  }`;
  const noteText = appendNote(chosen.notes, notes ? `${stamp}. ${notes}` : stamp);

  await notion.updatePageProps(LOCATIONS_DB_NAME, chosen.id, {
    "Current location": current,
    "Usual place": usual,
    Status: status,
    "Last seen": todayISO,
    "Last updated by": fromName,
    Notes: noteText,
    ...(aliases && !chosen.aliases ? { "Also known as": aliases } : {}),
    ...(ITEM_KINDS.includes(itemKind) && chosen.kind === "Other" ? { Kind: itemKind } : {}),
    ...(LOCATION_AREAS.includes(area) && chosen.area === "Other" ? { Area: area } : {}),
  });

  return {
    ok: true,
    created: false,
    missing: kind === "missing",
    name: chosen.name,
    url: chosen.url,
    current,
    usual,
    previousCurrent: chosen.current,
    previousUsual: chosen.usual,
  };
}

function formatItemLine(item) {
  const bits = [];
  if (item.status === "Missing") bits.push("missing");
  if (item.current) bits.push(item.current);
  else if (item.usual) bits.push(`usual: ${item.usual}`);
  else bits.push("location unknown");
  if (item.current && item.usual && item.usual !== item.current) {
    bits.push(`usual: ${item.usual}`);
  }
  if (item.updatedBy && item.lastSeen) bits.push(`${item.updatedBy}, ${item.lastSeen}`);
  else if (item.updatedBy) bits.push(item.updatedBy);
  return `• ${item.name} — ${bits.join(" · ")}`;
}

function formatLocationAnswer(item) {
  if (item.status === "Missing") {
    const usual = item.usual ? ` Usual place: ${item.usual}.` : "";
    const last = item.current
      ? ` Last seen: ${item.current}${item.updatedBy ? ` (${item.updatedBy})` : ""}.`
      : "";
    return `${item.name} is marked missing.${usual}${last} If you find it, tell me where you put it.`;
  }
  if (item.current && item.usual && item.current !== item.usual) {
    const who = item.updatedBy ? ` (${item.updatedBy}${item.lastSeen ? `, ${item.lastSeen}` : ""})` : "";
    return `${item.name} was last seen ${item.current}${who}. Usual place: ${item.usual}.`;
  }
  const place = item.current || item.usual;
  if (place) {
    const who = item.updatedBy ? ` — ${item.updatedBy}${item.lastSeen ? `, ${item.lastSeen}` : ""}` : "";
    return `${item.name} is ${place}.${who}`;
  }
  return `I have ${item.name} on the list, but nobody has said where it is yet. Tell me where it lives or where you left it.`;
}

function snapshotFromPages(pages) {
  const items = pages
    .map(pageToItem)
    .filter((item) => item.status !== "Archived")
    .sort((a, b) => a.name.localeCompare(b.name));
  if (!items.length) {
    return "No items have been logged yet. Tell VAVA where something lives or where you left it.";
  }
  const byArea = new Map();
  for (const item of items) {
    const key = item.area || "Other";
    if (!byArea.has(key)) byArea.set(key, []);
    byArea.get(key).push(item);
  }
  const lines = ["Where things are:"];
  for (const [area, list] of byArea) {
    lines.push("", area);
    for (const item of list) lines.push(formatItemLine(item));
  }
  return lines.join("\n");
}

async function listLocationsSnapshot({ area } = {}) {
  const pages = await loadLocationPages();
  const filtered = area
    ? pages.filter((p) => normalize(notion.pagePropText(p, "Area")).includes(normalize(area)))
    : pages;
  return snapshotFromPages(filtered);
}

async function answerLocationQuestion({ item, listAll, area, question }) {
  const wantsList =
    listAll === true ||
    /\b(everything|all (?:the )?things|where things are)\b/i.test(question || "");
  if (wantsList) {
    const snapshot = await listLocationsSnapshot({ area });
    return { ok: true, list: true, snapshot };
  }

  const query = item || extractLocationQuestionItem(question);
  if (!query) return { ok: false, reason: "no_item" };

  const matches = await findLocationMatches(query);
  if (!matches.length) return { ok: false, reason: "not_found", item: query };
  if (matches.length > 1 && !(matches[0].score >= 80 && matches[1].score < 70)) {
    return { ok: false, reason: "ambiguous", matches, item: query };
  }
  return { ok: true, item: matches[0] };
}

function hubBlocks() {
  return [
    {
      object: "block",
      type: "callout",
      callout: {
        icon: { type: "emoji", emoji: "📍" },
        rich_text: notion.richText(
          "Living list of where village things are — keys, tools, chargers, and anything people keep misplacing. VAVA in Telegram reads and updates this table."
        ),
      },
    },
    {
      object: "block",
      type: "paragraph",
      paragraph: {
        rich_text: notion.richText(
          "Ask VAVA: “where are the bathroom keys?” or “where is the drill?” Tell VAVA: “I left the drill in the kitchen” or “the bathroom keys live on the hook by reception.”"
        ),
      },
    },
    {
      object: "block",
      type: "paragraph",
      paragraph: {
        rich_text: notion.richText(
          "Current location = last place someone left it. Usual place = where it normally lives. If they differ, VAVA will say both."
        ),
      },
    },
  ];
}

async function ensureSection(parentId, catalog) {
  if (catalog.pages?.[HUB_PAGE_TITLE]) {
    return { id: catalog.pages[HUB_PAGE_TITLE], title: HUB_PAGE_TITLE };
  }
  const found = await notion.searchByTitle(HUB_PAGE_TITLE, "page");
  if (found) {
    catalog.pages[HUB_PAGE_TITLE] = found.id;
    return { id: found.id, title: HUB_PAGE_TITLE };
  }
  const page = await notion.createChildPage({
    parentPageId: parentId,
    title: HUB_PAGE_TITLE,
    children: hubBlocks(),
  });
  catalog.pages[HUB_PAGE_TITLE] = page.id;
  return page;
}

async function seedSamples() {
  const { pages } = await notion.queryDatabase(LOCATIONS_DB_NAME);
  if (pages.length > 0) return 0;
  let n = 0;
  for (const sample of SAMPLE_ITEMS) {
    await notion.createPage({
      databaseName: LOCATIONS_DB_NAME,
      title: sample.title,
      properties: {
        "Also known as": sample.aliases,
        Kind: sample.kind,
        Area: sample.area,
        Status: "Active",
        Notes: "Tell VAVA in Telegram where this lives or where you left it.",
      },
    });
    n += 1;
  }
  return n;
}

async function ensureLocationsDatabase() {
  const catalog = notion.loadCatalog();
  catalog.databases = catalog.databases || {};
  catalog.pages = catalog.pages || {};

  const parentId =
    catalog.pages?.["Documentation hub"] || catalog.parentPageId;
  if (!parentId) {
    throw new Error(
      "No Documentation hub / parent page in the Notion catalog. Run setup-vila-akoya-notion.js first."
    );
  }

  const section = await ensureSection(parentId, catalog);
  notion.saveCatalog(catalog);

  const db = await notion.createDatabase({
    parentPageId: section.id,
    title: LOCATIONS_DB_NAME,
    properties: LOCATIONS_PROPERTIES,
  });
  catalog.databases[LOCATIONS_DB_NAME] = db.id;
  await notion.primeDatabase(LOCATIONS_DB_NAME, db.id);
  notion.saveCatalog(catalog);

  try {
    await notion.notion("PATCH", `/databases/${db.id}`, {
      properties: LOCATIONS_PROPERTIES,
    });
    notion.clearDatabaseCache(LOCATIONS_DB_NAME);
    await notion.primeDatabase(LOCATIONS_DB_NAME, db.id);
  } catch (err) {
    // Adding select options on an existing DB can fail if names already exist.
    if (!/already exists/i.test(err.message)) {
      console.warn(`Locations property patch: ${err.message}`);
    }
  }

  try {
    await notion.ensureTableView(db.id, { name: "All items" });
  } catch (err) {
    console.warn(`Locations view skipped: ${err.message}`);
  }

  try {
    await notion.ensureTableView(db.id, {
      name: "Missing",
      filter: { property: "Status", select: { equals: "Missing" } },
    });
  } catch (err) {
    console.warn(`Missing view skipped: ${err.message}`);
  }

  const seeded = await seedSamples();
  notion.saveCatalog(catalog);

  return {
    db,
    pageId: section.id,
    url: db.url,
    seeded,
    wasCreated: db.wasCreated,
  };
}

module.exports = {
  LOCATIONS_DB_NAME,
  HUB_PAGE_TITLE,
  ITEM_KINDS,
  LOCATION_AREAS,
  UPDATE_KINDS,
  LOCATIONS_PROPERTIES,
  looksLikeLocationQuestion,
  looksLikeLocationUpdate,
  extractLocationUpdate,
  extractLocationQuestionItem,
  findLocationMatches,
  upsertLocation,
  answerLocationQuestion,
  listLocationsSnapshot,
  formatLocationAnswer,
  formatItemLine,
  ensureLocationsDatabase,
  guessItemKind,
};
