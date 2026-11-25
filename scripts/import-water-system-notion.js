/**
 * Import Vila Akoya Water System markdown pack into Notion under Docs · Water.
 *
 *   node scripts/import-water-system-notion.js [path-to.zip-or-folder]
 *
 * Creates:
 *   Documentation hub › Docs · Water › Vila Akoya — Water System › (child pages)
 *   + Documentation DB rows for the knowledge base and Telegram Bot FAQs
 *
 * After import: npm run docs:index
 */

"use strict";

const fs = require("fs");
const path = require("path");
const { execSync } = require("child_process");

require("./lib/load-env");
const notion = require("./lib/notion");

const PARENT_HUB = "Docs · Water";
const ROOT_TITLE = "Vila Akoya — Water System";
const AREA = "Water";

const SKIP_FILES = /^README/i;
const PAGE_ORDER = [
  "00 START HERE.md",
  "01 System Map & Equipment.md",
  "02 Timers & Controls.md",
  "03 Monitoring & Safe Routine.md",
  "04 Faults & Troubleshooting.md",
  "05 Telegram Bot FAQs.md",
  "06 Hans Video Index.md",
  "07 Open Questions for Hans.md",
];

function resolveImportDir(arg) {
  const input =
    arg ||
    process.env.WATER_SYSTEM_IMPORT ||
    path.join(process.env.HOME || "", "Downloads", "Vila_Akoya_Water_System_Notion_Import.zip");
  if (!fs.existsSync(input)) {
    throw new Error(`Import path not found: ${input}`);
  }
  const stat = fs.statSync(input);
  if (stat.isDirectory()) {
    const sub = fs.readdirSync(input).find((n) => n.includes("Water System"));
    return sub ? path.join(input, sub) : input;
  }
  const tmp = path.join(require("os").tmpdir(), `vila-water-import-${Date.now()}`);
  fs.mkdirSync(tmp, { recursive: true });
  execSync(`unzip -q "${input}" -d "${tmp}"`);
  const sub = fs.readdirSync(tmp).find((n) => n.includes("Water System"));
  return sub ? path.join(tmp, sub) : tmp;
}

function pageTitleFromFile(filename, body) {
  const base = filename.replace(/\.md$/i, "").replace(/^\d+\s+/, "").trim();
  const h1 = body.match(/^#\s+(.+)$/m);
  if (h1) return h1[1].trim().slice(0, 120);
  return base;
}

function stripLeadingH1(body) {
  return String(body || "").replace(/^#\s+.+\n+/, "").trim();
}

/** Split markdown into segments of prose vs image references. */
function segmentMarkdown(md, mediaDir) {
  const segments = [];
  const re = /!\[([^\]]*)\]\(([^)]+)\)/g;
  let last = 0;
  let m;
  while ((m = re.exec(md)) !== null) {
    if (m.index > last) {
      segments.push({ type: "text", value: md.slice(last, m.index) });
    }
    const rel = m[2].replace(/^\.\//, "");
    const abs = path.join(mediaDir, rel);
    segments.push({ type: "image", alt: m[1], path: abs });
    last = m.index + m[0].length;
  }
  if (last < md.length) segments.push({ type: "text", value: md.slice(last) });
  return segments;
}

async function blocksFromMarkdown(md, mediaDir) {
  const blocks = [];
  for (const seg of segmentMarkdown(md, mediaDir)) {
    if (seg.type === "text") {
      const piece = stripLeadingH1(seg.value);
      if (!piece.trim()) continue;
      blocks.push(...notion.markdownToBlocks(piece));
      continue;
    }
    if (!fs.existsSync(seg.path)) {
      blocks.push({
        object: "block",
        type: "paragraph",
        paragraph: {
          rich_text: notion.richText(`(Image missing: ${path.basename(seg.path)})`),
        },
      });
      continue;
    }
    const buf = fs.readFileSync(seg.path);
    const filename = path.basename(seg.path);
    const mime = notion.guessContentType(filename);
    const upload = await notion.uploadFile(buf, filename, mime);
    blocks.push({
      object: "block",
      type: "image",
      image: {
        type: "file_upload",
        file_upload: { id: upload.id },
        caption: seg.alt ? notion.richText(seg.alt) : [],
      },
    });
  }
  return blocks;
}

async function findChildPage(parentId, title) {
  const children = await notion.getBlockChildren(parentId);
  for (const block of children) {
    if (block.type !== "child_page") continue;
    if ((block.child_page?.title || "").trim() === title.trim()) {
      return { id: block.id, url: `https://www.notion.so/${block.id.replace(/-/g, "")}` };
    }
  }
  return null;
}

async function ensureRootParent(catalog) {
  let waterHubId = catalog.pages?.[PARENT_HUB];
  if (!waterHubId) {
    const found = await notion.searchByTitle(PARENT_HUB, "page");
    waterHubId = found?.id;
  }
  if (!waterHubId) {
    throw new Error(`Notion page "${PARENT_HUB}" not found. Run notion:setup first.`);
  }

  let root = await findChildPage(waterHubId, ROOT_TITLE);
  if (!root) {
    const callout = {
      object: "block",
      type: "callout",
      callout: {
        icon: { type: "emoji", emoji: "💧" },
        rich_text: notion.richText(
          "Water system knowledge base for VAVA (Telegram). Indexed after `npm run docs:index`. Operational steps in 05 Telegram Bot FAQs — validate 07 Open Questions before approving live pump/timer instructions."
        ),
      },
    };
    const page = await notion.createChildPage({
      parentPageId: waterHubId,
      title: ROOT_TITLE,
      children: [callout],
    });
    root = { id: page.id, url: page.url };
    console.log(`Created ${ROOT_TITLE}: ${page.url}`);
  } else {
    console.log(`Using existing ${ROOT_TITLE}: ${root.url}`);
  }
  return root;
}

async function upsertDocPage(rootId, title, blocks) {
  let existing = await findChildPage(rootId, title);
  if (existing) {
    console.log(`  Page exists, appending blocks only if empty: ${title}`);
    const body = await notion.pageBodyText(existing.id);
    if (body.trim().length > 80) {
      return existing;
    }
    await notion.appendBlocks(existing.id, blocks.slice(0, 100));
    if (blocks.length > 100) await notion.appendBlocks(existing.id, blocks.slice(100));
    return existing;
  }
  const slice = blocks.slice(0, 100);
  const page = await notion.createChildPage({
    parentPageId: rootId,
    title,
    children: slice,
  });
  if (blocks.length > 100) {
    await notion.appendBlocks(page.id, blocks.slice(100));
  }
  return { id: page.id, url: page.url };
}

async function ensureDocumentationRow({ title, summary, status, criticality }) {
  const { pages } = await notion.queryDatabase(notion.DB.DOCS);
  const hit = pages.find((p) => notion.pageTitle(p).trim() === title.trim());
  if (hit) {
    console.log(`  Documentation DB row exists: ${title}`);
    return { id: hit.id, url: hit.url };
  }
  const page = await notion.createPage({
    databaseName: notion.DB.DOCS,
    title,
    properties: {
      "Doc status": status || "Needs review",
      Area: AREA,
      "Last reviewed": "2026-11-18",
      Criticality: criticality || "High",
      Summary: summary || "",
      Notes: `Imported from water system pack. Hub: ${ROOT_TITLE}.`,
    },
    children: notion.markdownToBlocks(summary || "").slice(0, 20),
  });
  console.log(`  Created Documentation row: ${title}`);
  return page;
}

async function main() {
  notion.assertKey();
  const importDir = resolveImportDir(process.argv[2]);
  const mediaDir = path.join(importDir, "media");
  const catalog = notion.loadCatalog();

  console.log(`Importing from ${importDir}`);
  const root = await ensureRootParent(catalog);

  const files = fs
    .readdirSync(importDir)
    .filter((f) => f.endsWith(".md") && !SKIP_FILES.test(f));
  files.sort((a, b) => {
    const ai = PAGE_ORDER.indexOf(a);
    const bi = PAGE_ORDER.indexOf(b);
    if (ai >= 0 && bi >= 0) return ai - bi;
    if (ai >= 0) return -1;
    if (bi >= 0) return 1;
    return a.localeCompare(b);
  });

  const imported = [];
  for (const file of files) {
    const body = fs.readFileSync(path.join(importDir, file), "utf8");
    const title = pageTitleFromFile(file, body);
    console.log(`Importing: ${title}`);
    const blocks = await blocksFromMarkdown(body, mediaDir);
    const page = await upsertDocPage(root.id, title, blocks);
    imported.push({ title, url: page.url, file });
  }

  await ensureDocumentationRow({
    title: "Vila Akoya — Water System",
    summary:
      "Working draft of borehole, tank, treatment room and controls. Evidence-tagged; Hans verification pending on timers and pump ops. Use child pages under Documentation hub for detail.",
    status: "Needs review",
    criticality: "High",
  });

  await ensureDocumentationRow({
    title: "Water system — Telegram Bot FAQs",
    summary:
      "Bot-ready Q&A for the water system (escalation rules, refusal text). Pair with 07 Open Questions before approving operational answers.",
    status: "Needs review",
    criticality: "High",
  });

  console.log("\nDONE — imported pages:");
  for (const row of imported) console.log(`  • ${row.title}\n    ${row.url}`);
  console.log(`\nNext: npm run docs:index`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});