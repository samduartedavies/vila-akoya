/**
 * Shared Notion API client for Vila Akoya ops.
 * Zero npm deps — Node 18+ fetch only.
 */

"use strict";

const fs = require("fs");
const path = require("path");

const NOTION_API_KEY =
  process.env.NOTION_API_KEY ||
  process.env.NOTION_TOKEN ||
  "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";

const NOTION_VERSION = process.env.NOTION_VERSION || "2022-06-28";
const NOTION_API = "https://api.notion.com/v1";
const PACING_MS = Number(process.env.NOTION_PACING_MS || 350);

const CATALOG_FILE =
  process.env.NOTION_CATALOG_FILE ||
  path.join(__dirname, "..", ".vila-akoya-notion.json");

/** Canonical database titles used across bot / sync / seeds. */
const DB = {
  TASKS: "Tasks",
  JOBS: "Jobs",
  RECURRING: "Recurring Duties",
  BOOKINGS: "Campsite Bookings",
  MEALS: "Campsite Meals",
  CHILDCARE: "Childcare",
  PROJECTS: "Projects",
  DOCS: "Documentation",
  MEMBERS: "Members",
  INTERNAL_EVENTS: "Internal Events",
  EXTERNAL_EVENTS: "External Events",
  /** @deprecated use EXTERNAL_EVENTS */
  EVENTS: "External Events",
  REMINDERS: "Reminders",
  PARTNERSHIPS: "Partnerships",
  EXPENSES: "Expenses",
};

const WATCH_DB_NAMES = [
  DB.TASKS,
  DB.PROJECTS,
  DB.INTERNAL_EVENTS,
  DB.EXTERNAL_EVENTS,
  DB.REMINDERS,
];

const DONE_STAGES = new Set([
  "done",
  "logged",
  "decided",
  "past",
  "completed",
  "done / past",
  "done / logged",
  "rejected / parked",
  "rejected / not a task",
  "left / inactive",
]);

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function apiKey() {
  return NOTION_API_KEY;
}

function assertKey() {
  if (!apiKey()) throw new Error("Missing NOTION_API_KEY");
}

async function notion(method, endpoint, body, attempt = 1) {
  assertKey();
  const res = await fetch(`${NOTION_API}${endpoint}`, {
    method,
    headers: {
      Authorization: `Bearer ${apiKey()}`,
      "Notion-Version": NOTION_VERSION,
      "Content-Type": "application/json",
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  const text = await res.text();
  let json;
  try {
    json = text ? JSON.parse(text) : {};
  } catch {
    throw new Error(
      `Notion non-JSON (HTTP ${res.status}): ${text.replace(/\s+/g, " ").slice(0, 200)}`
    );
  }

  if (res.status === 429 && attempt <= 8) {
    const retry =
      Number(res.headers.get("retry-after") || 0) || Math.min(60, attempt * 2);
    await sleep((retry + 1) * 1000);
    return notion(method, endpoint, body, attempt + 1);
  }

  if (!res.ok) {
    const msg = json?.message || JSON.stringify(json);
    throw new Error(`Notion HTTP ${res.status}: ${msg}`);
  }

  if (PACING_MS > 0) await sleep(PACING_MS);
  return json;
}

function loadCatalog() {
  try {
    return JSON.parse(fs.readFileSync(CATALOG_FILE, "utf8"));
  } catch {
    return { databases: {}, pages: {}, parentPageId: null };
  }
}

function saveCatalog(catalog) {
  fs.writeFileSync(CATALOG_FILE, JSON.stringify(catalog, null, 2) + "\n");
}

const dbCache = new Map();

async function searchByTitle(title, filterType) {
  const body = {
    query: title,
    page_size: 25,
  };
  if (filterType) {
    body.filter = { property: "object", value: filterType };
  }
  const data = await notion("POST", "/search", body);
  const exact = (data.results || []).find((r) => {
    if (filterType === "database") {
      const t = (r.title || []).map((x) => x.plain_text).join("");
      return t === title;
    }
    if (r.object === "page") {
      const props = r.properties || {};
      const titleProp = Object.values(props).find((p) => p.type === "title");
      const t = (titleProp?.title || []).map((x) => x.plain_text).join("");
      return t === title;
    }
    return false;
  });
  return exact || null;
}

async function resolveDatabase(name) {
  if (dbCache.has(name)) return dbCache.get(name);

  const catalog = loadCatalog();
  let id = catalog.databases?.[name];
  if (!id) {
    const found = await searchByTitle(name, "database");
    if (!found) throw new Error(`Notion database not found: ${name}`);
    id = found.id;
    catalog.databases = catalog.databases || {};
    catalog.databases[name] = id;
    saveCatalog(catalog);
  }

  return primeDatabase(name, id);
}

/** Cache a known database id (used by setup before catalog is flushed). */
async function primeDatabase(name, id) {
  const db = await notion("GET", `/databases/${id}`);
  const entry = {
    id: db.id,
    name,
    url: db.url,
    properties: db.properties || {},
  };
  dbCache.set(name, entry);
  return entry;
}

function clearDatabaseCache(name) {
  if (name) dbCache.delete(name);
  else dbCache.clear();
}

function prop(db, title) {
  return db.properties?.[title] || null;
}

function titlePropName(db) {
  for (const [name, p] of Object.entries(db.properties || {})) {
    if (p.type === "title") return name;
  }
  return "Name";
}

function richText(content) {
  const text = String(content || "");
  if (!text) return [];
  // Notion rich_text items max 2000 chars.
  const chunks = [];
  for (let i = 0; i < text.length; i += 1900) {
    chunks.push({
      type: "text",
      text: { content: text.slice(i, i + 1900) },
    });
  }
  return chunks;
}

function titleValue(name) {
  return { title: richText(String(name || "").slice(0, 2000)) };
}

function richTextValue(text) {
  return { rich_text: richText(text) };
}

function selectValue(name) {
  if (!name) return { select: null };
  return { select: { name: String(name) } };
}

function statusValue(name) {
  if (!name) return { status: null };
  return { status: { name: String(name) } };
}

function checkboxValue(checked) {
  return { checkbox: Boolean(checked) };
}

function numberValue(n) {
  const num = Number(n);
  return { number: Number.isFinite(num) ? num : null };
}

function dateValue(start, end) {
  if (!start) return { date: null };
  const d = { start: String(start).slice(0, 10) };
  if (end) d.end = String(end).slice(0, 10);
  return { date: d };
}

function urlValue(url) {
  return { url: url || null };
}

/** Build a property payload using the live schema types. */
function buildProps(db, map) {
  const out = {};
  for (const [title, value] of Object.entries(map || {})) {
    if (value === undefined || value === null || value === "") continue;
    const schema = prop(db, title);
    if (!schema) continue;
    switch (schema.type) {
      case "title":
        out[title] = titleValue(value);
        break;
      case "rich_text":
        out[title] =
          typeof value === "object" && value.rich_text
            ? value
            : richTextValue(value);
        break;
      case "select":
        out[title] =
          typeof value === "object" && value.select
            ? value
            : selectValue(value);
        break;
      case "status":
        out[title] =
          typeof value === "object" && value.status
            ? value
            : statusValue(value);
        break;
      case "multi_select":
        out[title] = Array.isArray(value)
          ? { multi_select: value.map((n) => ({ name: String(n) })) }
          : { multi_select: [{ name: String(value) }] };
        break;
      case "checkbox":
        out[title] =
          typeof value === "object" && "checkbox" in value
            ? value
            : checkboxValue(value === true || value === "true" || value === "checked");
        break;
      case "number":
        out[title] =
          typeof value === "object" && "number" in value
            ? value
            : numberValue(value);
        break;
      case "date":
        if (typeof value === "object" && value.date) out[title] = value;
        else if (typeof value === "object" && value.start)
          out[title] = dateValue(value.start, value.end);
        else out[title] = dateValue(value);
        break;
      case "url":
        out[title] =
          typeof value === "object" && "url" in value
            ? value
            : urlValue(value);
        break;
      case "files":
        if (typeof value === "object" && value.files) out[title] = value;
        else if (Array.isArray(value)) out[title] = { files: value };
        break;
      default:
        break;
    }
  }
  return out;
}

async function createPage({ databaseName, title, properties = {}, children = [] }) {
  const db = await resolveDatabase(databaseName);
  const titleKey = titlePropName(db);
  const props = buildProps(db, { ...properties, [titleKey]: title });
  if (!props[titleKey]) props[titleKey] = titleValue(title);

  const body = {
    parent: { database_id: db.id },
    properties: props,
  };
  if (children.length) body.children = children.slice(0, 100);

  const page = await notion("POST", "/pages", body);
  return {
    id: page.id,
    url: page.url,
    databaseName,
    databaseId: db.id,
  };
}

async function updatePage(pageId, { properties, archived } = {}) {
  const body = {};
  if (properties) body.properties = properties;
  if (archived !== undefined) body.archived = archived;
  return notion("PATCH", `/pages/${pageId}`, body);
}

async function updatePageProps(databaseName, pageId, propertyMap) {
  const db = await resolveDatabase(databaseName);
  const properties = buildProps(db, propertyMap);
  if (!Object.keys(properties).length) return null;
  return updatePage(pageId, { properties });
}

async function appendBlocks(pageId, children) {
  if (!children?.length) return;
  for (let i = 0; i < children.length; i += 100) {
    await notion("PATCH", `/blocks/${pageId}/children`, {
      children: children.slice(i, i + 100),
    });
  }
}

async function queryDatabase(databaseName, { filter, sorts, pageSize = 100 } = {}) {
  const db = await resolveDatabase(databaseName);
  const pages = [];
  let cursor = undefined;
  do {
    const body = { page_size: pageSize };
    if (filter) body.filter = filter;
    if (sorts) body.sorts = sorts;
    if (cursor) body.start_cursor = cursor;
    const data = await notion("POST", `/databases/${db.id}/query`, body);
    pages.push(...(data.results || []));
    cursor = data.has_more ? data.next_cursor : null;
  } while (cursor);
  return { db, pages };
}

function plainFromRich(arr) {
  return (arr || []).map((t) => t.plain_text || "").join("");
}

function pageTitle(page) {
  const props = page.properties || {};
  for (const p of Object.values(props)) {
    if (p.type === "title") return plainFromRich(p.title);
  }
  return "(untitled)";
}

function pagePropText(page, name) {
  const p = page.properties?.[name];
  if (!p) return "";
  switch (p.type) {
    case "title":
      return plainFromRich(p.title);
    case "rich_text":
      return plainFromRich(p.rich_text);
    case "select":
      return p.select?.name || "";
    case "status":
      return p.status?.name || "";
    case "multi_select":
      return (p.multi_select || []).map((x) => x.name).join(", ");
    case "checkbox":
      return p.checkbox ? "true" : "false";
    case "number":
      return p.number == null ? "" : String(p.number);
    case "date":
      return p.date?.start || "";
    case "url":
      return p.url || "";
    case "people":
      return (p.people || []).map((u) => u.name || u.id).join(", ");
    default:
      return "";
  }
}

function pageDateISO(page, name) {
  const raw = pagePropText(page, name);
  const m = String(raw).match(/^(\d{4}-\d{2}-\d{2})/);
  return m ? m[1] : "";
}

function isDoneStage(stage) {
  return DONE_STAGES.has(String(stage || "").trim().toLowerCase());
}

function pageUrl(pageOrUrl) {
  if (!pageOrUrl) return null;
  if (typeof pageOrUrl === "string") return pageOrUrl;
  return pageOrUrl.url || null;
}

/** Convert simple markdown into Notion blocks (headings, lists, paragraphs). */
function markdownToBlocks(markdown) {
  const lines = String(markdown || "").split(/\r?\n/);
  const blocks = [];
  let i = 0;

  const para = (text) => ({
    object: "block",
    type: "paragraph",
    paragraph: { rich_text: richText(text) },
  });

  while (i < lines.length) {
    const line = lines[i];
    const trimmed = line.trim();
    if (!trimmed) {
      i += 1;
      continue;
    }
    if (trimmed.startsWith("### ")) {
      blocks.push({
        object: "block",
        type: "heading_3",
        heading_3: { rich_text: richText(trimmed.slice(4)) },
      });
      i += 1;
      continue;
    }
    if (trimmed.startsWith("## ")) {
      blocks.push({
        object: "block",
        type: "heading_2",
        heading_2: { rich_text: richText(trimmed.slice(3)) },
      });
      i += 1;
      continue;
    }
    if (trimmed.startsWith("# ")) {
      blocks.push({
        object: "block",
        type: "heading_1",
        heading_1: { rich_text: richText(trimmed.slice(2)) },
      });
      i += 1;
      continue;
    }
    if (/^[-*] /.test(trimmed)) {
      const items = [];
      while (i < lines.length && /^[-*] /.test(lines[i].trim())) {
        items.push(lines[i].trim().replace(/^[-*] /, ""));
        i += 1;
      }
      for (const item of items) {
        blocks.push({
          object: "block",
          type: "bulleted_list_item",
          bulleted_list_item: { rich_text: richText(item) },
        });
      }
      continue;
    }
    if (/^\d+\. /.test(trimmed)) {
      const items = [];
      while (i < lines.length && /^\d+\. /.test(lines[i].trim())) {
        items.push(lines[i].trim().replace(/^\d+\. /, ""));
        i += 1;
      }
      for (const item of items) {
        blocks.push({
          object: "block",
          type: "numbered_list_item",
          numbered_list_item: { rich_text: richText(item) },
        });
      }
      continue;
    }
    // Accumulate consecutive plain lines into one paragraph.
    const parts = [trimmed];
    i += 1;
    while (
      i < lines.length &&
      lines[i].trim() &&
      !/^#{1,3} /.test(lines[i].trim()) &&
      !/^[-*] /.test(lines[i].trim()) &&
      !/^\d+\. /.test(lines[i].trim())
    ) {
      parts.push(lines[i].trim());
      i += 1;
    }
    blocks.push(para(parts.join("\n")));
  }
  return blocks.slice(0, 100);
}

async function getBlockChildren(blockId) {
  const blocks = [];
  let cursor = undefined;
  do {
    const qs = cursor
      ? `?start_cursor=${encodeURIComponent(cursor)}&page_size=100`
      : "?page_size=100";
    const data = await notion("GET", `/blocks/${blockId}/children${qs}`);
    blocks.push(...(data.results || []));
    cursor = data.has_more ? data.next_cursor : null;
  } while (cursor);
  return blocks;
}

function blockPlainText(block) {
  if (!block || !block.type) return "";
  const data = block[block.type];
  if (!data) return "";
  if (data.rich_text) return plainFromRich(data.rich_text);
  if (data.text) return plainFromRich(data.text);
  if (data.caption) return plainFromRich(data.caption);
  return "";
}

async function pageBodyText(pageId) {
  const blocks = await getBlockChildren(pageId);
  return blocks.map(blockPlainText).filter(Boolean).join("\n\n");
}

async function createChildPage({ parentPageId, title, children = [] }) {
  const body = {
    parent: { page_id: parentPageId },
    properties: {
      title: titleValue(title),
    },
  };
  if (children.length) body.children = children.slice(0, 100);
  const page = await notion("POST", "/pages", body);
  return { id: page.id, url: page.url, title };
}

async function createDatabase({ parentPageId, title, properties }) {
  const existing = await searchByTitle(title, "database");
  if (existing) {
    const db = await notion("GET", `/databases/${existing.id}`);
    return { id: db.id, url: db.url, title, wasCreated: false, properties: db.properties };
  }

  const db = await notion("POST", "/databases", {
    parent: { type: "page_id", page_id: parentPageId },
    title: richText(title),
    properties,
  });
  return {
    id: db.id,
    url: db.url,
    title,
    wasCreated: true,
    properties: db.properties,
  };
}

/** Views + file uploads require a newer Notion-Version than page/database CRUD. */
const VIEWS_VERSION = process.env.NOTION_VIEWS_VERSION || "2026-03-11";
const FILE_UPLOAD_VERSION =
  process.env.NOTION_FILE_UPLOAD_VERSION || VIEWS_VERSION;

async function notionViews(method, endpoint, body, attempt = 1) {
  assertKey();
  const res = await fetch(`${NOTION_API}${endpoint}`, {
    method,
    headers: {
      Authorization: `Bearer ${apiKey()}`,
      "Notion-Version": VIEWS_VERSION,
      "Content-Type": "application/json",
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let json;
  try {
    json = text ? JSON.parse(text) : {};
  } catch {
    throw new Error(
      `Notion views non-JSON (HTTP ${res.status}): ${text.replace(/\s+/g, " ").slice(0, 200)}`
    );
  }
  if (res.status === 429 && attempt <= 8) {
    const retry =
      Number(res.headers.get("retry-after") || 0) || Math.min(60, attempt * 2);
    await sleep((retry + 1) * 1000);
    return notionViews(method, endpoint, body, attempt + 1);
  }
  if (!res.ok) {
    throw new Error(`Notion views HTTP ${res.status}: ${json?.message || JSON.stringify(json)}`);
  }
  if (PACING_MS > 0) await sleep(PACING_MS);
  return json;
}

/**
 * Ensure a Calendar view exists on a database, keyed by the Date property.
 * Idempotent — skips if a Calendar calendar view already exists.
 */
async function ensureCalendarView(databaseId, { name = "Calendar", dateProperty = "Date" } = {}) {
  const db = await notionViews("GET", `/databases/${databaseId}`);
  const dataSourceId = db.data_sources?.[0]?.id;
  if (!dataSourceId) {
    throw new Error(`No data_sources on database ${databaseId} (need Notion-Version ${VIEWS_VERSION})`);
  }

  const existing = await notionViews(
    "GET",
    `/views?database_id=${encodeURIComponent(databaseId)}`
  );
  for (const stub of existing.results || []) {
    const view = await notionViews("GET", `/views/${stub.id}`);
    if (view.name === name && view.type === "calendar") {
      return { id: view.id, url: view.url, wasCreated: false };
    }
  }

  const ds = await notionViews("GET", `/data_sources/${dataSourceId}`);
  const dateProp = ds.properties?.[dateProperty];
  if (!dateProp?.id) {
    throw new Error(`Date property "${dateProperty}" not found on data source ${dataSourceId}`);
  }

  const created = await notionViews("POST", "/views", {
    database_id: databaseId,
    data_source_id: dataSourceId,
    name,
    type: "calendar",
    configuration: {
      type: "calendar",
      date_property_id: dateProp.id,
    },
  });
  return { id: created.id, url: created.url, wasCreated: true };
}

/**
 * Ensure a board view grouped by a select/status property.
 * Idempotent by view name + type.
 */
async function ensureBoardView(
  databaseId,
  { name, groupProperty, filter = null } = {}
) {
  if (!name || !groupProperty) {
    throw new Error("ensureBoardView requires name and groupProperty");
  }

  const db = await notionViews("GET", `/databases/${databaseId}`);
  const dataSourceId = db.data_sources?.[0]?.id;
  if (!dataSourceId) {
    throw new Error(`No data_sources on database ${databaseId}`);
  }

  const existing = await notionViews(
    "GET",
    `/views?database_id=${encodeURIComponent(databaseId)}`
  );
  for (const stub of existing.results || []) {
    const view = await notionViews("GET", `/views/${stub.id}`);
    if (view.name === name && view.type === "board") {
      return { id: view.id, url: view.url, wasCreated: false };
    }
  }

  const ds = await notionViews("GET", `/data_sources/${dataSourceId}`);
  const prop = ds.properties?.[groupProperty];
  if (!prop?.id) {
    throw new Error(`Property "${groupProperty}" not found on data source`);
  }

  const body = {
    database_id: databaseId,
    data_source_id: dataSourceId,
    name,
    type: "board",
    configuration: {
      type: "board",
      group_by: {
        type: prop.type === "status" ? "status" : "select",
        property_id: prop.id,
        ...(prop.type === "status" ? { group_by: "option" } : {}),
        sort: { type: "manual" },
        hide_empty_groups: false,
      },
    },
  };
  if (filter) body.filter = filter;

  const created = await notionViews("POST", "/views", body);
  return { id: created.id, url: created.url, wasCreated: true };
}

/**
 * Ensure a table view, optionally filtered (e.g. Type = Villager).
 * Idempotent by view name + type.
 */
async function ensureTableView(databaseId, { name, filter = null, sorts = null } = {}) {
  if (!name) throw new Error("ensureTableView requires name");

  const db = await notionViews("GET", `/databases/${databaseId}`);
  const dataSourceId = db.data_sources?.[0]?.id;
  if (!dataSourceId) {
    throw new Error(`No data_sources on database ${databaseId}`);
  }

  const existing = await notionViews(
    "GET",
    `/views?database_id=${encodeURIComponent(databaseId)}`
  );
  for (const stub of existing.results || []) {
    const view = await notionViews("GET", `/views/${stub.id}`);
    if (view.name === name && view.type === "table") {
      return { id: view.id, url: view.url, wasCreated: false };
    }
  }

  const body = {
    database_id: databaseId,
    data_source_id: dataSourceId,
    name,
    type: "table",
    configuration: { type: "table" },
  };
  if (filter) body.filter = filter;
  if (sorts) body.sorts = sorts;

  const created = await notionViews("POST", "/views", body);
  return { id: created.id, url: created.url, wasCreated: true };
}

async function notionFiles(method, endpoint, body, attempt = 1) {
  assertKey();
  const res = await fetch(`${NOTION_API}${endpoint}`, {
    method,
    headers: {
      Authorization: `Bearer ${apiKey()}`,
      "Notion-Version": FILE_UPLOAD_VERSION,
      "Content-Type": "application/json",
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let json;
  try {
    json = text ? JSON.parse(text) : {};
  } catch {
    throw new Error(
      `Notion files non-JSON (HTTP ${res.status}): ${text.replace(/\s+/g, " ").slice(0, 200)}`
    );
  }
  if (res.status === 429 && attempt <= 8) {
    const retry =
      Number(res.headers.get("retry-after") || 0) || Math.min(60, attempt * 2);
    await sleep((retry + 1) * 1000);
    return notionFiles(method, endpoint, body, attempt + 1);
  }
  if (!res.ok) {
    throw new Error(
      `Notion files HTTP ${res.status}: ${json?.message || JSON.stringify(json)}`
    );
  }
  if (PACING_MS > 0) await sleep(PACING_MS);
  return json;
}

function guessContentType(filename, fallback = "application/octet-stream") {
  const lower = String(filename || "").toLowerCase();
  if (lower.endsWith(".png")) return "image/png";
  if (lower.endsWith(".webp")) return "image/webp";
  if (lower.endsWith(".gif")) return "image/gif";
  if (lower.endsWith(".heic") || lower.endsWith(".heif")) return "image/heic";
  if (lower.endsWith(".pdf")) return "application/pdf";
  if (lower.endsWith(".jpg") || lower.endsWith(".jpeg")) return "image/jpeg";
  return fallback;
}

/**
 * Upload a small file (≤20MB) to Notion-managed storage.
 * Returns { id, filename, contentType } for attaching to pages / blocks.
 */
async function uploadFile(buf, filename, contentType) {
  const name = String(filename || "receipt.jpg").replace(/[^\w.\-]+/g, "_").slice(0, 120);
  const type = contentType || guessContentType(name, "image/jpeg");
  const created = await notionFiles("POST", "/file_uploads", {
    mode: "single_part",
    filename: name,
    content_type: type,
  });
  const form = new FormData();
  form.append(
    "file",
    new Blob([new Uint8Array(buf)], { type }),
    name
  );
  const res = await fetch(`${NOTION_API}/file_uploads/${created.id}/send`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${apiKey()}`,
      "Notion-Version": FILE_UPLOAD_VERSION,
    },
    body: form,
  });
  const text = await res.text();
  let json;
  try {
    json = text ? JSON.parse(text) : {};
  } catch {
    throw new Error(
      `Notion file send non-JSON (HTTP ${res.status}): ${text.replace(/\s+/g, " ").slice(0, 200)}`
    );
  }
  if (!res.ok) {
    throw new Error(
      `Notion file send HTTP ${res.status}: ${json?.message || JSON.stringify(json)}`
    );
  }
  if (PACING_MS > 0) await sleep(PACING_MS);
  return { id: created.id, filename: name, contentType: type };
}

/**
 * Attach an uploaded file to a page's files property and as an image/pdf/file block.
 */
async function attachFileToPage(
  pageId,
  { uploadId, filename, propertyName = "Receipt", kind = "image" } = {}
) {
  if (!pageId || !uploadId) return;
  const fileObj = {
    type: "file_upload",
    file_upload: { id: uploadId },
    name: filename || "receipt",
  };
  if (propertyName) {
    await notionFiles("PATCH", `/pages/${pageId}`, {
      properties: {
        [propertyName]: { type: "files", files: [fileObj] },
      },
    });
  }
  let block;
  if (kind === "pdf") {
    block = {
      object: "block",
      type: "pdf",
      pdf: { type: "file_upload", file_upload: { id: uploadId } },
    };
  } else if (kind === "image") {
    block = {
      object: "block",
      type: "image",
      image: { type: "file_upload", file_upload: { id: uploadId } },
    };
  } else {
    block = {
      object: "block",
      type: "file",
      file: { type: "file_upload", file_upload: { id: uploadId } },
    };
  }
  await notionFiles("PATCH", `/blocks/${pageId}/children`, { children: [block] });
}

module.exports = {
  DB,
  WATCH_DB_NAMES,
  CATALOG_FILE,
  apiKey,
  assertKey,
  notion,
  sleep,
  loadCatalog,
  saveCatalog,
  resolveDatabase,
  primeDatabase,
  clearDatabaseCache,
  prop,
  titlePropName,
  richText,
  titleValue,
  richTextValue,
  selectValue,
  statusValue,
  checkboxValue,
  numberValue,
  dateValue,
  urlValue,
  buildProps,
  createPage,
  updatePage,
  updatePageProps,
  appendBlocks,
  queryDatabase,
  pageTitle,
  pagePropText,
  pageDateISO,
  isDoneStage,
  pageUrl,
  markdownToBlocks,
  getBlockChildren,
  pageBodyText,
  createChildPage,
  createDatabase,
  ensureCalendarView,
  ensureBoardView,
  ensureTableView,
  searchByTitle,
  guessContentType,
  uploadFile,
  attachFileToPage,
};
