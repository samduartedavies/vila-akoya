/**
 * Conversational layer for VAVA.
 *
 * Addressed messages are read with the recent thread (including VAVA's own
 * replies). Clear village jobs still go to the existing action router.
 * Anything else can be answered in conversation, or — when the person is
 * asking for something the fixed action list doesn't cover — read or written
 * on a Notion database from its live schema.
 */

"use strict";

const notion = require("./notion");
const openai = require("./openai");

const WRITE_DATABASES = [
  "Tasks",
  "Jobs",
  "Recurring Duties",
  "Internal Events",
  "External Events",
  "Reminders",
  "Projects",
  "Partnerships",
  "Members",
  "Documentation",
  "Where things are",
  "FAQs",
  "Guest Stays",
  "Kids Programme Shifts",
];

const BLOCKED_PROP = /telegram|phone|allerg|password|token|secret|nif|iban|email/i;

const SCHEMA_TTL_MS = 10 * 60 * 1000;
const schemaCache = new Map();

function formatTranscript(entries, { limit = 18 } = {}) {
  return (entries || [])
    .slice(-limit)
    .map((entry) => {
      const who =
        entry.isBot || entry.role === "assistant"
          ? "VAVA"
          : entry.name || "Someone";
      const text = String(entry.text || "")
        .replace(/\s+/g, " ")
        .trim();
      if (!text) return "";
      return `${who}: ${text.slice(0, 500)}`;
    })
    .filter(Boolean)
    .join("\n");
}

function resolveDatabaseName(name) {
  const raw = String(name || "").trim();
  if (!raw) return "";
  const exact = WRITE_DATABASES.find((db) => db.toLowerCase() === raw.toLowerCase());
  if (exact) return exact;
  const wanted = norm(raw);
  if (!wanted) return "";
  const hits = WRITE_DATABASES.filter((db) => {
    const title = norm(db);
    return title === wanted || title.includes(wanted) || wanted.includes(title);
  });
  return hits.length === 1 ? hits[0] : "";
}

function normalizeThought(raw) {
  const src = raw && typeof raw === "object" ? raw : {};
  const modeAlias = {
    chat: "reply",
    reply: "reply",
    handled: "action",
    action: "action",
    notion: "notion",
  };
  let mode = modeAlias[src.mode] || "reply";
  const reply = String(src.reply || "").trim().slice(0, 1200);
  const resolved = String(src.resolved_message || "").trim().slice(0, 1500);
  const understanding = String(src.understanding || "").trim().slice(0, 400);
  const notionRequest = String(src.notion_request || understanding || "").trim().slice(0, 800);
  const n = src.notion && typeof src.notion === "object" ? src.notion : null;
  const op = ["create", "update", "note", "lookup"].includes(n?.op) ? n.op : "";
  const database = resolveDatabaseName(n?.database) || resolveDatabaseName(src.database);
  const properties = {};
  if (n?.properties && typeof n.properties === "object" && !Array.isArray(n.properties)) {
    for (const [key, value] of Object.entries(n.properties)) {
      if (!key || BLOCKED_PROP.test(key)) continue;
      if (value == null || typeof value === "object") continue;
      const text = String(value).trim();
      if (!text) continue;
      properties[key] = text.slice(0, 1800);
    }
  }
  const notionPlan =
    op && database
      ? {
          op,
          database,
          title: String(n.title || "").trim().slice(0, 200),
          match: String(n.match || n.title || "").trim().slice(0, 200),
          properties,
          body: String(n.body || "").trim().slice(0, 4000),
          question: String(n.question || src.understanding || "").trim().slice(0, 500),
        }
      : null;

  if (mode === "notion" && !database) {
    mode = "reply";
  }

  return {
    understanding,
    mode,
    resolved_message: resolved,
    reply,
    database,
    notion_request: notionRequest,
    notion: mode === "notion" ? notionPlan : null,
  };
}

function schemaLine(db) {
  const bits = [];
  for (const [name, prop] of Object.entries(db.properties || {})) {
    if (bits.length >= 22) break;
    if (BLOCKED_PROP.test(name)) continue;
    let extra = "";
    const options =
      prop.select?.options || prop.status?.options || prop.multi_select?.options || [];
    if (options.length) {
      extra = ` [${options
        .slice(0, 8)
        .map((o) => o.name)
        .join("|")}]`;
    }
    bits.push(`${name} (${prop.type}${extra})`);
  }
  return `${db.name || ""}: ${bits.join("; ")}`;
}

function thinkSystemPrompt() {
  return [
    "You are VAVA, texting as a sharp, warm member of Vila Akoya (a campsite and small village in Portugal).",
    "Talk the way a good chat model would: read the whole CONVERSATION, infer what they mean,",
    "including 'yes', 'that one', 'do it', and references to your own last message.",
    "Use the same language they used. No slash commands, no menus, no 'certainly'.",
    "",
    "You can reason, brainstorm, explain, and give practical advice from general knowledge.",
    "Do not invent site records: who is on shift, guest names, bookings, allergies, phone numbers,",
    "or where a specific object was left. Those must go through action or notion.",
    "",
    "Pick ONE mode:",
    "",
    "reply — talk. Advice, opinions, greetings, working something out, or one clarifying question.",
    "Put the actual message in reply (1–6 sentences). Do not file a task because a message is unclear.",
    "",
    "action — a village operation the existing tools already do precisely. Fill resolved_message with a",
    "self-contained sentence (pronouns replaced). Use action for: one-off task or guest issue; recurring duty;",
    "create, cancel, or RSVP an event; reminder; note on an existing task or project; write documentation;",
    "FAQ ask/create; where something is or an update to that; receipt or expense; email/phone guest stay;",
    "childcare absence; kids programme shift; dinner booking; questions about the schedule, my tasks,",
    "docs, expenses, locations, or FAQs.",
    "",
    "notion — read or change a Notion board in a way action does not cover, or save an idea somewhere sensible.",
    "Set database to one of: " + WRITE_DATABASES.join(", ") + ".",
    "Set notion_request to a clear sentence of what to find or change.",
    "If they already agreed in the thread, do it. If they are only musing, use reply and ask first.",
    "Never touch Telegram ids, phones, allergies, emails, tokens, or payment details.",
    "",
    'Return JSON: { "understanding": string, "mode": "reply"|"action"|"notion", "reply": string,',
    '  "resolved_message": string, "database": string, "notion_request": string }',
  ].join("\n");
}

async function think({
  transcript = "",
  todayISO = "",
  speaker = "",
  isPrivate = false,
  missedStandardAction = false,
} = {}) {
  if (!openai.isEnabled()) return null;

  const user = [
    todayISO ? `TODAY: ${todayISO}` : "",
    speaker ? `SPEAKER: ${speaker}` : "",
    `CHAT: ${isPrivate ? "private DM" : "group"}`,
    missedStandardAction
      ? "The predefined village tools did NOT handle this. Use reply or notion. Do not turn it into a task unless they clearly asked for one."
      : "",
    "NOTION BOARDS: " + WRITE_DATABASES.join(", "),
    transcript ? `CONVERSATION:\n${transcript}` : "(no earlier messages)",
  ]
    .filter(Boolean)
    .join("\n\n");

  const raw = await openai.completeJSON(thinkSystemPrompt(), user, {
    temperature: missedStandardAction ? 0.4 : 0.2,
  });
  return normalizeThought(raw);
}

function norm(value) {
  return String(value || "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, " ")
    .trim();
}

function titleScore(query, title) {
  const q = norm(query);
  const t = norm(title);
  if (!q || !t) return 0;
  if (q === t) return 1;
  if (t.includes(q) || q.includes(t)) return 0.86;
  const qt = q.split(" ").filter((w) => w.length > 2);
  if (!qt.length) return 0;
  const tt = new Set(t.split(" ").filter((w) => w.length > 2));
  let hit = 0;
  for (const word of qt) if (tt.has(word)) hit += 1;
  return (hit / qt.length) * 0.75;
}

function optionNames(prop) {
  const options = prop?.select?.options || prop?.status?.options || prop?.multi_select?.options || [];
  return options.map((o) => o.name).filter(Boolean);
}

function canonicalOption(prop, value) {
  const want = String(value || "").trim().toLowerCase();
  if (!want) return "";
  const names = optionNames(prop);
  const exact = names.find((name) => name.toLowerCase() === want);
  if (exact) return exact;
  return (
    names.find((name) => {
      const option = name.toLowerCase();
      if (want.length >= 4 && option.includes(want)) return true;
      if (option.length >= 4 && want.includes(option) && !/^(not|no|non)\b/.test(want)) return true;
      return false;
    }) || ""
  );
}

function sanitizeProperties(db, properties) {
  const kept = {};
  const dropped = [];
  for (const [key, raw] of Object.entries(properties || {})) {
    if (BLOCKED_PROP.test(key)) continue;
    const schema = notion.prop(db, key);
    if (!schema || schema.type === "title") {
      if (raw && schema?.type !== "title") dropped.push(`${key}: ${raw}`);
      continue;
    }
    if (schema.type === "checkbox") {
      kept[key] = /^(1|true|yes|y|on)$/i.test(String(raw));
      continue;
    }
    if (schema.type === "number") {
      const num = Number(String(raw).replace(",", "."));
      if (Number.isFinite(num)) kept[key] = num;
      else dropped.push(`${key}: ${raw}`);
      continue;
    }
    if (schema.type === "select" || schema.type === "status") {
      const name = canonicalOption(schema, raw);
      if (!name) dropped.push(`${key}: ${raw}`);
      else kept[key] = name;
      continue;
    }
    if (schema.type === "multi_select") {
      const names = String(raw)
        .split(/[,/]| and /i)
        .map((part) => canonicalOption(schema, part))
        .filter(Boolean);
      if (!names.length) dropped.push(`${key}: ${raw}`);
      else kept[key] = names;
      continue;
    }
    if (schema.type === "date") {
      const iso = String(raw).slice(0, 10);
      if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) dropped.push(`${key}: ${raw}`);
      else kept[key] = iso;
      continue;
    }
    if (["rich_text", "url", "email", "phone_number"].includes(schema.type)) {
      kept[key] = String(raw);
      continue;
    }
    dropped.push(`${key}: ${raw}`);
  }
  return { kept, dropped };
}

function summarizePage(page) {
  const title = notion.pageTitle(page);
  const bits = [];
  for (const name of Object.keys(page.properties || {})) {
    if (BLOCKED_PROP.test(name)) continue;
    const prop = page.properties[name];
    if (prop?.type === "title") continue;
    const text = notion.pagePropText(page, name).replace(/\s+/g, " ").trim();
    if (!text || text === "false") continue;
    bits.push(`${name}: ${text.slice(0, 80)}`);
    if (bits.length >= 6) break;
  }
  return `- ${title}${bits.length ? ` — ${bits.join("; ")}` : ""}`;
}

async function findPages(database, query) {
  const { pages } = await notion.queryDatabase(database);
  return { ...rankPages(pages, query), all: pages };
}

function rankPages(pages, query) {
  const recent = [...pages].sort((a, b) =>
    String(b.last_edited_time || "").localeCompare(String(a.last_edited_time || ""))
  );
  if (!query) return { page: null, names: [], matches: recent, loose: false };
  const ranked = pages
    .map((page) => ({ page, score: titleScore(query, notion.pageTitle(page)) }))
    .filter((row) => row.score >= 0.45)
    .sort((a, b) => b.score - a.score);
  if (!ranked.length) return { page: null, names: [], matches: [], loose: false };
  const best = ranked[0];
  const second = ranked[1];
  if (second && best.score - second.score < 0.12 && second.score >= 0.55) {
    return {
      page: null,
      ambiguous: true,
      names: ranked.slice(0, 4).map((row) => notion.pageTitle(row.page)),
      matches: ranked.map((row) => row.page),
      loose: false,
    };
  }
  return {
    page: best.page,
    names: [notion.pageTitle(best.page)],
    matches: ranked.map((row) => row.page),
    loose: false,
  };
}

async function answerFromPages({ database, pages, question }) {
  const lines = pages.slice(0, 18).map(summarizePage);
  if (!lines.length) {
    return `I looked in ${database} and didn't find anything for that.`;
  }
  if (!openai.isEnabled()) {
    return lines.slice(0, 8).join("\n");
  }
  try {
    const result = await openai.completeJSON(
      [
        "You are VAVA. Answer using ONLY the Notion rows given. If they don't contain the answer, say so.",
        "Warm, brief, practical. Same language as the question. Do not invent rows.",
        'Return JSON: { "answer": string }',
      ].join("\n"),
      `Database: ${database}\nQuestion: ${question}\n\nRows:\n${lines.join("\n")}`,
      { temperature: 0.2 }
    );
    const answer = String(result.answer || "").trim();
    if (answer) return answer.slice(0, 1200);
  } catch {
    // Fall through to a plain list.
  }
  return lines.slice(0, 8).join("\n");
}

/**
 * Run a freeform Notion read or write.
 * Returns { ok, handled, reply, pageId, url, title, database, op }.
 * handled=false means the caller should keep going with the normal router.
 */
async function executeNotionPlan(plan) {
  const notionPlan = plan?.notion;
  if (!notionPlan?.database || !notionPlan.op) {
    return { ok: false, handled: false, reason: "incomplete" };
  }
  const { op, database, title, match, properties, body, question } = notionPlan;

  let db;
  try {
    db = await notion.resolveDatabase(database);
  } catch (err) {
    return { ok: false, handled: false, reason: "missing_db", detail: err.message };
  }

  if (op === "lookup") {
    const { pages } = await notion.queryDatabase(database);
    const ranked = rankPages(pages, match);
    const pool = ranked.matches.length ? ranked.matches : rankPages(pages, "").matches;
    const loose = !ranked.matches.length && Boolean(match);
    const reply = await answerFromPages({
      database,
      pages: pool.slice(0, 18),
      question:
        (loose ? `(No page title matched “${match}”. These are recent rows.) ` : "") +
        (question || plan.understanding || match || title),
    });
    return { ok: true, handled: true, op, database, reply };
  }

  const { kept, dropped } = sanitizeProperties(db, properties);
  let pageBody = body;
  if (dropped.length) {
    const note = `Also noted (couldn't set as fields): ${dropped.join("; ")}`;
    pageBody = pageBody ? `${pageBody}\n\n${note}` : note;
  }
  const children = pageBody ? notion.markdownToBlocks(pageBody).slice(0, 40) : [];

  if (op === "create") {
    if (!title) {
      return {
        ok: false,
        handled: true,
        reason: "need_title",
        reply: "I can put that in Notion — what should I call it?",
      };
    }
    const page = await notion.createPage({
      databaseName: database,
      title,
      properties: kept,
      children,
    });
    return {
      ok: true,
      handled: true,
      op,
      database,
      title,
      url: page.url,
      pageId: page.id,
      reply: `Done — I added “${title}” to ${database}.`,
    };
  }

  const query = match || title;
  if (!query) {
    return {
      ok: false,
      handled: true,
      reason: "need_match",
      reply: `Which ${database} page should I update?`,
    };
  }
  const found = await findPages(database, query);
  if (found.ambiguous) {
    return {
      ok: false,
      handled: true,
      reason: "ambiguous",
      reply: `Which one? I found: ${found.names.join(", ")}.`,
    };
  }
  if (!found.page) {
    return {
      ok: false,
      handled: true,
      reason: "not_found",
      reply: `I couldn't find “${query}” in ${database}. Say the word and I'll create it.`,
    };
  }

  const pageTitle = notion.pageTitle(found.page);
  if (Object.keys(kept).length) {
    await notion.updatePageProps(database, found.page.id, kept);
  }
  if (children.length) {
    await notion.appendBlocks(found.page.id, children);
  }
  if (!Object.keys(kept).length && !children.length) {
    return {
      ok: false,
      handled: true,
      reason: "nothing_to_write",
      reply: `I found “${pageTitle}” in ${database}, but I wasn't sure what to change. What should I write?`,
    };
  }
  return {
    ok: true,
    handled: true,
    op,
    database,
    title: pageTitle,
    url: found.page.url,
    pageId: found.page.id,
    reply:
      op === "note"
        ? `Added that to “${pageTitle}” in ${database}.`
        : `Updated “${pageTitle}” in ${database}.`,
  };
}

async function loadDatabaseContext(database) {
  const cached = schemaCache.get(database);
  const fresh = cached && Date.now() - cached.at < SCHEMA_TTL_MS;
  const db = fresh ? cached.db : await notion.resolveDatabase(database);
  if (!fresh) schemaCache.set(database, { at: Date.now(), db });
  const { pages } = await notion.queryDatabase(database);
  const titles = rankPages(pages, "")
    .matches.slice(0, 40)
    .map((page) => notion.pageTitle(page))
    .filter((title) => title && title !== "(untitled)");
  return { schema: schemaLine({ ...db, name: database }), titles };
}

async function planNotion({
  transcript = "",
  todayISO = "",
  speaker = "",
  database = "",
  notion_request = "",
  understanding = "",
} = {}) {
  const name = resolveDatabaseName(database);
  if (!name) {
    return normalizeThought({
      mode: "reply",
      reply:
        "I can save that in Notion. Which board fits — Projects, Tasks, Documentation, Members, or another one?",
      understanding,
    });
  }
  const { schema, titles } = await loadDatabaseContext(name);
  const raw = await openai.completeJSON(
    [
      "You turn one request into a single Notion change for Vila Akoya.",
      "Use only the property names and option lists in the schema. Do not invent options.",
      "op=lookup when they want to know what is already there.",
      "op=update or note when an existing title fits. op=create only when nothing matches and they want something new saved.",
      "match is the existing page title. title is the new or current title.",
      "properties values are plain strings. Longer writing goes in body.",
      "Never set Telegram ids, phones, allergies, emails, tokens, or payment details.",
      "If you cannot do it safely, set mode to reply and ask one question in reply.",
      'Return JSON: { "mode": "notion"|"reply", "reply": string, "understanding": string,',
      '  "notion": { "op": "create"|"update"|"note"|"lookup", "database": string, "title": string,',
      '    "match": string, "properties": object, "body": string, "question": string } }',
    ].join("\n"),
    [
      todayISO ? `TODAY: ${todayISO}` : "",
      speaker ? `SPEAKER: ${speaker}` : "",
      `DATABASE: ${name}`,
      `SCHEMA: ${schema}`,
      titles.length ? `EXISTING TITLES:\n${titles.map((title) => `- ${title}`).join("\n")}` : "EXISTING TITLES: (none)",
      transcript ? `CONVERSATION:\n${transcript}` : "",
      `REQUEST: ${notion_request || understanding}`,
    ]
      .filter(Boolean)
      .join("\n\n"),
    { temperature: 0.2 }
  );
  const thought = normalizeThought({ ...raw, database: raw?.notion?.database || name });
  if (thought.mode === "notion" && thought.notion) return thought;
  if (thought.reply) return { ...thought, mode: "reply" };
  return normalizeThought({
    mode: "reply",
    reply: `I looked at ${name} but I'm not sure what to change. What should I write?`,
    database: name,
  });
}

async function phraseReply({ transcript = "", facts = "", speaker = "", todayISO = "" } = {}) {
  if (!openai.isEnabled() || !facts) return "";
  try {
    const raw = await openai.completeJSON(
      [
        "You are VAVA, texting for Vila Akoya. Write the message the person should actually see.",
        "FACTS are what just happened or what Notion returned. Keep every concrete fact (names, dates, amounts, titles).",
        "Do not add site records that are not in FACTS or the conversation.",
        "Sound like a person, not a form. Same language as the person. 1–6 sentences.",
        "If FACTS say it failed or was ambiguous, say that and ask one question.",
        'Return JSON: { "reply": string }',
      ].join("\n"),
      [
        todayISO ? `TODAY: ${todayISO}` : "",
        speaker ? `SPEAKER: ${speaker}` : "",
        transcript ? `CONVERSATION:\n${transcript}` : "",
        `FACTS:\n${String(facts).slice(0, 2500)}`,
      ]
        .filter(Boolean)
        .join("\n\n"),
      { temperature: 0.5 }
    );
    return String(raw.reply || "").trim().slice(0, 1200);
  } catch (err) {
    console.error("phraseReply failed:", err.message);
    return "";
  }
}

module.exports = {
  WRITE_DATABASES,
  formatTranscript,
  normalizeThought,
  resolveDatabaseName,
  titleScore,
  think,
  planNotion,
  phraseReply,
  executeNotionPlan,
};
