/**
 * Archive all Tasks in Notion and import tasks from the WhatsApp
 * "To do and done!" export.
 *
 *   node scripts/import-whatsapp-tasks-notion.js
 *   WHATSAPP_TASKS_ZIP=vava/WhatsApp\ Chat\ -\ To\ do\ and\ done!.zip node scripts/...
 *
 * Set ARCHIVE_EXISTING=false to skip clearing current tasks.
 */

"use strict";

const fs = require("fs");
const path = require("path");
const os = require("os");
const { execSync } = require("child_process");

const notion = require("./lib/notion");
const { parseChatFile } = require("./lib/whatsapp-parser");

const ZIP_PATH =
  process.env.WHATSAPP_TASKS_ZIP ||
  path.join(__dirname, "..", "vava", "WhatsApp Chat - To do and done!.zip");
const ARCHIVE_EXISTING = process.env.ARCHIVE_EXISTING !== "false";

function cleanSender(name) {
  return String(name || "")
    .replace(/[\u{1F300}-\u{1FAFF}]/gu, "")
    .replace(/[~‎\u200e\u200f]/g, "")
    .trim();
}

function inferType(text) {
  const t = String(text || "").toLowerCase();
  if (
    /\b(hire|cleaning person|professional|contractor|lab|university|order message|deadline|volunteers contact|install our|boundary fence)\b/.test(
      t
    ) ||
    /\bget a\b/.test(t)
  ) {
    return "Management";
  }
  return "Villager";
}

function titleFromBody(body, max = 200) {
  const first = String(body || "")
    .split(/\n/)[0]
    .trim();
  const t = first || String(body || "").trim();
  return t.length > max ? t.slice(0, max - 3) + "..." : t;
}

function parseFocusLine(line) {
  const raw = String(line || "").trim();
  if (!raw || raw.length < 3) return null;
  // "Task description - Person" or "Task - Person & Person"
  const m = raw.match(/^(.+?)\s*[-–—]\s*(.+)$/);
  if (m) {
    const title = m[1].trim();
    const assignee = m[2].replace(/…$/, "").trim();
    if (title.length < 3) return null;
    return {
      title: titleFromBody(title),
      assignee,
      notes: raw,
      type: inferType(title),
    };
  }
  return {
    title: titleFromBody(raw),
    assignee: null,
    notes: raw,
    type: inferType(raw),
  };
}

function extractTasks(messages) {
  const tasks = [];
  const seen = new Set();

  function add(task) {
    const key = task.title.toLowerCase();
    if (seen.has(key)) return;
    seen.add(key);
    tasks.push(task);
  }

  for (const msg of messages) {
    if (msg.type === "system") continue;
    const sender = cleanSender(msg.sender);
    let body = (msg.body || "").trim();
    if (!body && msg.attachment?.caption) body = msg.attachment.caption.trim();
    if (!body) continue;
    if (/was added|created this group|Added You to a Group/i.test(body)) continue;

    const dateISO = msg.date.toISOString().slice(0, 10);
    const meta = `Imported from WhatsApp "To do and done!" on ${dateISO} by ${sender}.`;

    if (/^Focus of today:/i.test(body)) {
      const lines = body.split(/\n/).slice(1);
      for (const line of lines) {
        const parsed = parseFocusLine(line);
        if (!parsed) continue;
        add({
          ...parsed,
          createdBy: sender,
          notes: `${parsed.notes}\n\n${meta}`,
          sourceDate: dateISO,
        });
      }
      continue;
    }

    add({
      title: titleFromBody(body),
      assignee: null,
      notes: `${body}\n\n${meta}`,
      type: inferType(body),
      createdBy: sender,
      sourceDate: dateISO,
    });
  }

  return tasks;
}

function extractZip(zipPath) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "vava-wa-"));
  execSync(`unzip -o -q ${JSON.stringify(zipPath)} -d ${JSON.stringify(dir)}`, {
    stdio: "pipe",
  });
  const chatPath = path.join(dir, "_chat.txt");
  if (!fs.existsSync(chatPath)) {
    throw new Error(`No _chat.txt in ${zipPath}`);
  }
  return { chatPath, mediaDir: dir };
}

async function archiveAllTasks() {
  const { pages } = await notion.queryDatabase(notion.DB.TASKS);
  let n = 0;
  for (const page of pages) {
    if (page.archived) continue;
    await notion.updatePage(page.id, { archived: true });
    n++;
  }
  console.log(`  Archived ${n} existing task(s)`);
}

async function importTasks(tasks) {
  let created = 0;
  for (const task of tasks) {
    const props = {
      Status: "Not started",
      "Created by": task.createdBy || "WhatsApp import",
      Notes: task.notes,
      Type: task.type || "Villager",
    };
    if (task.assignee) props.Assignee = task.assignee;

    await notion.createPage({
      databaseName: notion.DB.TASKS,
      title: task.title,
      properties: props,
    });
    created++;
    console.log(`  + ${task.title}${task.assignee ? ` → ${task.assignee}` : ""}`);
  }
  return created;
}

async function main() {
  notion.assertKey();
  if (!fs.existsSync(ZIP_PATH)) {
    throw new Error(`Zip not found: ${ZIP_PATH}`);
  }

  console.log("Importing WhatsApp tasks → Notion\n");
  console.log(`Source: ${ZIP_PATH}`);

  const { chatPath, mediaDir } = extractZip(ZIP_PATH);
  const messages = parseChatFile(chatPath, mediaDir);
  const tasks = extractTasks(messages);
  console.log(`\nParsed ${tasks.length} task(s) from chat\n`);

  if (ARCHIVE_EXISTING) {
    console.log("=== Archive existing Tasks ===");
    await archiveAllTasks();
  }

  console.log("\n=== Create tasks ===");
  const n = await importTasks(tasks);

  const db = await notion.resolveDatabase(notion.DB.TASKS);
  console.log(`\nDONE. Created ${n} tasks.`);
  console.log(db.url);
  console.log('Use the "Recently added" or "Needs assign" views for morning meetings.');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
