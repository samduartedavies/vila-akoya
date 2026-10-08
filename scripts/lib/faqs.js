/**
 * Village FAQs — short factual Q&A in Notion (delivery address, gate codes, etc.).
 * VAVA reads and writes the same table.
 */

"use strict";

const notion = require("./notion");

const FAQS_DB_NAME = process.env.FAQS_DB_NAME || notion.DB.FAQS;
const HUB_PAGE_TITLE = "FAQs";

const FAQ_CATEGORIES = [
  "Deliveries",
  "Campsite / guest",
  "Village",
  "Admin",
  "Other",
];

const STATUS_OPTIONS = ["Active", "Archived"];

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

const FAQS_PROPERTIES = {
  Question: { title: {} },
  Answer: { rich_text: {} },
  Category: selectProp(FAQ_CATEGORIES),
  "Also asked as": { rich_text: {} },
  Status: selectProp(STATUS_OPTIONS),
  "Last updated by": { rich_text: {} },
};

const SAMPLE_FAQS = [
  {
    question: "What is the address for deliveries?",
    alsoAskedAs:
      "delivery address, where do deliveries go, morada para entregas, shipping address",
    answer:
      "Set the real delivery address here in Notion (GPS + any gate instructions). VAVA reads this when people ask.",
    category: "Deliveries",
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
    .filter(
      (w) =>
        w.length > 1 &&
        !["the", "a", "an", "our", "to", "of", "for", "is", "what", "da", "do", "de"].includes(w)
    );
}

function aliasesOf(page) {
  return notion
    .pagePropText(page, "Also asked as")
    .split(/[,;/]|\band\b/i)
    .map((s) => s.trim())
    .filter(Boolean);
}

function stripQuestionMark(s) {
  return normalize(s).replace(/\?+$/, "").trim();
}

function normalizeFaqQuery(query) {
  return String(query || "")
    .replace(/\bwgat\b/gi, "what")
    .replace(/\baddres\b/gi, "address")
    .trim();
}

function isPlaceholderFaqAnswer(answer) {
  const a = String(answer || "").toLowerCase();
  return (
    /set the real delivery address here in notion/i.test(a) ||
    /vava reads this when people ask/i.test(a)
  );
}

function scoreFaq(query, page) {
  const q = stripQuestionMark(normalizeFaqQuery(query));
  if (!q) return 0;
  const question = stripQuestionMark(notion.pageTitle(page));
  const answerRaw = notion.pagePropText(page, "Answer");
  const answer = normalize(answerRaw);
  const aliasList = aliasesOf(page).map(stripQuestionMark);

  const adjust = (base) => {
    let score = base;
    if (isPlaceholderFaqAnswer(answerRaw)) score -= 30;
    else if (answerRaw.length > 25 && /\d{4}-\d{3}|\d{5,}/.test(answerRaw)) score += 12;
    return Math.max(0, score);
  };

  if (question === q) return adjust(100);
  if (aliasList.includes(q)) return adjust(92);
  if (question.startsWith(q) && q.length >= 10) return adjust(88);
  if (q.startsWith(question) && question.length >= 10) return adjust(86);
  if (question.includes(q) || q.includes(question)) return adjust(82);
  if (aliasList.some((a) => a && (a.includes(q) || q.includes(a)))) return adjust(76);
  const qWords = words(q);
  if (!qWords.length) return 0;
  const hay = [question, answer, ...aliasList].join(" ");
  const hits = qWords.filter((w) => hay.includes(w));
  if (!hits.length) return 0;
  const ratio = hits.length / qWords.length;
  if (ratio >= 0.85 && hits.length >= 2) return adjust(72);
  if (ratio >= 0.6 && hits.length >= 2) return adjust(58);
  if (ratio >= 0.5 && hits.length >= 1) return adjust(48);
  return 0;
}

function pageToFaq(page) {
  return {
    id: page.id,
    url: page.url,
    question: notion.pageTitle(page),
    answer: notion.pagePropText(page, "Answer"),
    category: notion.pagePropText(page, "Category") || "Other",
    aliases: notion.pagePropText(page, "Also asked as"),
    status: notion.pagePropText(page, "Status") || "Active",
    updatedBy: notion.pagePropText(page, "Last updated by"),
  };
}

async function loadFaqPages() {
  const { pages } = await notion.queryDatabase(FAQS_DB_NAME);
  return pages.filter((p) => {
    if (p.archived) return false;
    return notion.pagePropText(p, "Status") !== "Archived";
  });
}

async function findFaqMatches(query) {
  const q = String(query || "").trim();
  if (!q) return [];
  const pages = await loadFaqPages();
  const scored = pages
    .map((page) => ({ page, score: scoreFaq(q, page) }))
    .filter((row) => row.score >= 48)
    .sort((a, b) => b.score - a.score);
  if (!scored.length) return [];
  const best = scored[0].score;
  const close = scored.filter((row) => row.score >= best - 10 && row.score >= 55);
  return (close.length ? close : scored.slice(0, 3)).map((row) => ({
    ...pageToFaq(row.page),
    score: row.score,
  }));
}

function formatFaqAnswer(faq) {
  const lines = [`<b>${faq.question}</b>`, faq.answer || "(no answer yet)"];
  if (faq.category && faq.category !== "Other") {
    lines.push(`<i>(${faq.category})</i>`);
  }
  return lines.join("\n");
}

async function listFaqsSnapshot({ category } = {}) {
  const pages = await loadFaqPages();
  const filtered = category
    ? pages.filter((p) =>
        normalize(notion.pagePropText(p, "Category")).includes(normalize(category))
      )
    : pages;
  const lines = ["FAQs:"];
  for (const page of filtered) {
    const faq = pageToFaq(page);
    lines.push("", `• ${faq.question}`);
    if (faq.answer) lines.push(`  ${faq.answer.slice(0, 200)}${faq.answer.length > 200 ? "…" : ""}`);
  }
  return lines.join("\n");
}

function pickBestFaqMatch(matches, query) {
  if (!matches?.length) return null;
  const qNorm = stripQuestionMark(normalizeFaqQuery(query));
  const substantive = matches.filter((m) => !isPlaceholderFaqAnswer(m.answer));
  const pool = substantive.length ? substantive : matches;
  if (pool.length === 1) return pool[0];
  const ranked = [...pool].sort((a, b) => b.score - a.score);
  const best = ranked[0];
  const second = ranked[1];
  const bestQ = stripQuestionMark(best.question);
  if (bestQ === qNorm || best.score >= 95) return best;
  if (best.score - (second?.score || 0) >= 12) return best;
  if (best.score >= 80 && (second?.score || 0) < 65) return best;
  if (/\baddress\b|\bmorada\b/.test(qNorm) && substantive.length === 1) {
    return substantive[0];
  }
  return null;
}

async function answerFaqQuestion({ question, listAll, category }) {
  const wantsList =
    listAll === true ||
    /\b(list|show) (?:all )?(?:the )?faqs?\b/i.test(question || "") ||
    /\blist faqs\b/i.test(question || "");
  if (wantsList) {
    return { ok: true, list: true, snapshot: await listFaqsSnapshot({ category }) };
  }
  const query = normalizeFaqQuery(String(question || "").trim());
  if (!query) return { ok: false, reason: "no_question" };
  const matches = await findFaqMatches(query);
  if (!matches.length) return { ok: false, reason: "not_found", question: query };
  const chosen = pickBestFaqMatch(matches, query);
  if (chosen) return { ok: true, faq: chosen };
  return { ok: false, reason: "ambiguous", matches, question: query };
}

function looksLikeFaqQuestion(text) {
  const t = String(text || "").toLowerCase().trim();
  if (!t || looksLikeFaqUpsert(text)) return false;
  if (/\b(how does|how do|como funciona)\b/.test(t)) return false;
  return (
    /\b(what|whats|what's|qual)\b.{0,16}\baddress\b/.test(t) ||
    /\b(what is|what's|whats|qual [eé])\s+(?:the\s+)?address\b/.test(t) ||
    /\b(what is|what's|whats)\s+(?:the\s+)?(?:delivery|shipping|campsite|village|vila)\s+address\b/.test(t) ||
    /\b(?:delivery|shipping|campsite|village)\s+address\b/.test(t) ||
    /\bwhere (?:do|should) (?:deliver|courier|parcels|packages)\b/.test(t) ||
    /\bmorada\s+(?:para|de)\s+entreg/.test(t) ||
    /\b(?:qual|que)\s+(?:[eé]\s+)?a\s+morada\b/.test(t) ||
    /\b(gate code|door code|c[oó]digo da porta)\b/.test(t) ||
    /\b(reception|recepci[oã]o)\s+(?:phone|number|telefone)\b/.test(t)
  );
}

function looksLikeFaqUpsert(text) {
  const t = String(text || "").toLowerCase();
  if (!t) return false;
  return (
    /\b(add|create|new|update|set)\s+(an?\s+)?faq\b/.test(t) ||
    /\bfaq\s*[:=—–-]/.test(t) ||
    /\b(add|save)\s+(this|that)\s+(to|as)\s+(the\s+)?faqs?\b/.test(t)
  );
}

function extractFaqUpsert(text) {
  const raw = String(text || "").trim();
  if (!raw) return null;

  const addAnFaq = raw.match(/\badd\s+an?\s+faq\s*:\s*(.+)/is);
  if (addAnFaq?.[1]) {
    const body = addAnFaq[1].trim();
    const addressIs = body.match(/^the address is\s+(.+)/i);
    if (addressIs?.[1]) {
      return {
        question: "What is the address?",
        answer: addressIs[1].trim().replace(/[.!?]+$/, ""),
      };
    }
    if (body.length >= 8) {
      return {
        question: "What is the address?",
        answer: body.replace(/[.!?]+$/, ""),
      };
    }
  }

  const qaArrow = raw.match(
    /(?:add|new|create|update|set)\s+(?:an?\s+)?faq\s*[:—–-]\s*(.+?)\s*(?:→|->|—|–)\s*(.+)/i
  );
  if (qaArrow?.[1] && qaArrow?.[2]) {
    return {
      question: qaArrow[1].trim().replace(/[.?]+$/, ""),
      answer: qaArrow[2].trim().replace(/[.!?]+$/, ""),
    };
  }

  const faqPrefix = raw.match(/\bfaq\s*[:=]\s*(.+)/i);
  if (faqPrefix?.[1]) {
    const body = faqPrefix[1].trim();
    const split = body.match(/^(.+?\?)\s*(?:answer\s*[:=—-]\s*)?(.+)$/is);
    if (split?.[1] && split?.[2]) {
      return {
        question: split[1].trim(),
        answer: split[2].trim(),
      };
    }
    const dash = body.match(/^(.+?)\s*(?:→|->|—|–|-)\s*(.+)$/);
    if (dash?.[1] && dash?.[2]) {
      return {
        question: dash[1].trim().replace(/[.?]+$/, ""),
        answer: dash[2].trim(),
      };
    }
  }

  const qThenA = raw.match(
    /(?:add|new|create|update)\s+(?:an?\s+)?faq\s*[:,]?\s*(?:q(?:uestion)?\s*[:.]?\s*)?(.+?\?)\s*(?:a(?:nswer)?\s*[:.]?\s*)?(.+)/is
  );
  if (qThenA?.[1] && qThenA?.[2]) {
    return {
      question: qThenA[1].trim(),
      answer: qThenA[2].trim(),
    };
  }

  return null;
}

function guessCategory(question, answer) {
  const hay = normalize(`${question} ${answer}`);
  if (/\bdeliver(y|ies|y address|morada|entregas?|shipping|courier|mail)\b/.test(hay)) {
    return "Deliveries";
  }
  if (/\b(campsite|guest|pitch|check-?in|booking|camper)\b/.test(hay)) return "Campsite / guest";
  if (/\b(nif|invoice|tax|bank|admin|reception)\b/.test(hay)) return "Admin";
  if (/\b(village|community|villager)\b/.test(hay)) return "Village";
  return "Other";
}

async function upsertFaq({
  question,
  answer,
  category,
  aliases,
  fromName,
  sourceText,
}) {
  const q = String(question || "").trim().slice(0, 200);
  const a = String(answer || "").trim().slice(0, 1800);
  if (!q) return { ok: false, reason: "no_question" };
  if (!a) return { ok: false, reason: "no_answer" };

  const matches = await findFaqMatches(q);
  const exact = matches.find(
    (m) => normalize(m.question) === normalize(q) || m.score >= 95
  );
  const cat =
    FAQ_CATEGORIES.includes(category) ? category : guessCategory(q, a);

  const autoAliases = [];
  if (/\baddress\b|\bmorada\b/i.test(q)) {
    autoAliases.push(
      "what is the address",
      "what's the address",
      "address",
      "delivery address",
      "morada",
      "morada para entregas"
    );
  }
  const aliasText = [aliases, ...autoAliases].filter(Boolean).join(", ");

  const props = {
    Answer: a,
    Category: cat,
    Status: "Active",
    "Last updated by": fromName || "",
  };
  if (aliasText) props["Also asked as"] = aliasText.slice(0, 500);

  if (exact) {
    await notion.updatePageProps(FAQS_DB_NAME, exact.id, props);
    if (normalize(exact.question) !== normalize(q)) {
      const db = await notion.resolveDatabase(FAQS_DB_NAME);
      const titleKey = notion.titlePropName(db);
      await notion.updatePage(exact.id, {
        properties: {
          ...notion.buildProps(db, props),
          [titleKey]: notion.titleValue(q),
        },
      });
    }
    return {
      ok: true,
      created: false,
      name: q,
      answer: a,
      url: exact.url,
      id: exact.id,
    };
  }

  if (matches.length > 1 && matches[0].score >= 70 && !exact) {
    return { ok: false, reason: "ambiguous", matches, question: q };
  }

  const created = await notion.createPage({
    databaseName: FAQS_DB_NAME,
    title: q,
    properties: props,
  });
  return {
    ok: true,
    created: true,
    name: q,
    answer: a,
    url: created.url,
    id: created.id,
  };
}

function hubBlocks() {
  return [
    {
      object: "block",
      type: "callout",
      callout: {
        icon: { type: "emoji", emoji: "❓" },
        rich_text: notion.richText(
          "Short village FAQs — delivery address, guest info, and other quick facts. VAVA in Telegram answers from here and stewards can add entries by message."
        ),
      },
    },
    {
      object: "block",
      type: "paragraph",
      paragraph: {
        rich_text: notion.richText(
          'Ask VAVA: “what is the address for deliveries?” Edit rows here in Notion, or tell VAVA: “Add FAQ: What is the delivery address? → Prado …”'
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
  const { pages } = await notion.queryDatabase(FAQS_DB_NAME);
  if (pages.length > 0) return 0;
  let n = 0;
  for (const sample of SAMPLE_FAQS) {
    await notion.createPage({
      databaseName: FAQS_DB_NAME,
      title: sample.question,
      properties: {
        Answer: sample.answer,
        Category: sample.category,
        "Also asked as": sample.alsoAskedAs,
        Status: "Active",
      },
    });
    n += 1;
  }
  return n;
}

async function ensureFaqsDatabase() {
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
    title: FAQS_DB_NAME,
    properties: FAQS_PROPERTIES,
  });
  catalog.databases[FAQS_DB_NAME] = db.id;
  await notion.primeDatabase(FAQS_DB_NAME, db.id);
  notion.saveCatalog(catalog);

  try {
    await notion.notion("PATCH", `/databases/${db.id}`, {
      properties: FAQS_PROPERTIES,
    });
    notion.clearDatabaseCache(FAQS_DB_NAME);
    await notion.primeDatabase(FAQS_DB_NAME, db.id);
  } catch (err) {
    if (!/already exists/i.test(err.message)) {
      console.warn(`FAQs property patch: ${err.message}`);
    }
  }

  try {
    await notion.ensureTableView(db.id, { name: "All FAQs" });
  } catch (err) {
    console.warn(`FAQs view skipped: ${err.message}`);
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
  FAQS_DB_NAME,
  HUB_PAGE_TITLE,
  FAQ_CATEGORIES,
  FAQS_PROPERTIES,
  looksLikeFaqUpsert,
  looksLikeFaqQuestion,
  extractFaqUpsert,
  findFaqMatches,
  answerFaqQuestion,
  upsertFaq,
  formatFaqAnswer,
  listFaqsSnapshot,
  ensureFaqsDatabase,
};
