/**
 * Vila Akoya — documentation indexer (Notion)
 *
 * Indexes:
 *   1. Rows in the Documentation database
 *   2. Freeform pages under Documentation hub (e.g. Tech → Wifi)
 *
 *   NOTION_API_KEY="..." OPENAI_API_KEY="..." node scripts/index-vila-akoya-docs.js
 */

"use strict";

const openaiLib = require("./lib/openai");
const docsIndex = require("./lib/docs-index");
const notion = require("./lib/notion");

const HUB_PAGE_NAME = "Documentation hub";

async function collectFromDocsDatabase() {
  const { pages } = await notion.queryDatabase(notion.DB.DOCS);
  const docs = [];
  for (const page of pages) {
    const status = notion.pagePropText(page, "Doc status");
    if (status === "Deprecated") continue;
    const name = notion.pageTitle(page);
    const summary = notion.pagePropText(page, "Summary");
    const notes = notion.pagePropText(page, "Notes");
    let body = "";
    try {
      body = await notion.pageBodyText(page.id);
    } catch (err) {
      console.warn(`  Could not read body for ${name}: ${err.message}`);
    }
    const text = [summary, notes, body].filter(Boolean).join("\n\n").trim();
    if (!text) continue;
    docs.push({
      id: page.id,
      name,
      url: page.url,
      text,
    });
  }
  return docs;
}

async function walkChildPages(pageId, pathLabels, out, seen) {
  if (seen.has(pageId)) return;
  seen.add(pageId);

  let blocks;
  try {
    blocks = await notion.getBlockChildren(pageId);
  } catch (err) {
    console.warn(`  Could not list children of ${pageId}: ${err.message}`);
    return;
  }

  for (const block of blocks) {
    if (block.type !== "child_page") continue;
    const title = block.child_page?.title || "Untitled";
    const childPath = [...pathLabels, title];
    const url = `https://www.notion.so/${block.id.replace(/-/g, "")}`;

    let body = "";
    try {
      body = await notion.pageBodyText(block.id);
    } catch (err) {
      console.warn(`  Could not read body for ${title}: ${err.message}`);
    }

    if (body.trim()) {
      out.push({
        id: block.id,
        name: childPath.join(" › "),
        url,
        text: body.trim(),
      });
    }

    await walkChildPages(block.id, childPath, out, seen);
  }
}

async function collectFromDocsHub() {
  const catalog = notion.loadCatalog();
  let hubId = catalog.pages?.[HUB_PAGE_NAME];
  if (!hubId) {
    const found = await notion.searchByTitle(HUB_PAGE_NAME, "page");
    hubId = found?.id;
  }
  if (!hubId) {
    console.warn("  Documentation hub page not found — skipping freeform pages.");
    return [];
  }

  const docs = [];
  await walkChildPages(hubId, [], docs, new Set());
  return docs;
}

async function fetchDocPages() {
  const fromDb = await collectFromDocsDatabase();
  console.log(`  Documentation database: ${fromDb.length}`);
  const fromHub = await collectFromDocsHub();
  console.log(`  Documentation hub pages: ${fromHub.length}`);

  // Prefer DB rows when the same page id appears twice; otherwise keep both.
  const byId = new Map();
  for (const doc of [...fromHub, ...fromDb]) {
    byId.set(doc.id, doc);
  }
  return [...byId.values()];
}

async function main() {
  if (!openaiLib.isEnabled()) {
    throw new Error("Missing OPENAI_API_KEY — cannot build the docs index.");
  }
  notion.assertKey();

  console.log("Fetching Notion documentation...");
  const docs = await fetchDocPages();
  console.log(`Found ${docs.length} docs with text.`);

  const chunks = [];
  for (const doc of docs) {
    const pieces = docsIndex.chunkText(doc.text);
    pieces.forEach((piece, i) => {
      chunks.push({
        id: `${doc.id}-${i}`,
        docId: doc.id,
        docName: doc.name,
        url: doc.url || null,
        text: piece,
      });
    });
    console.log(`  ${doc.name}: ${pieces.length} chunk(s)`);
  }

  if (!chunks.length) {
    console.log(
      "No text found. Add docs in the Documentation database or under Documentation hub, then rerun."
    );
    docsIndex.saveIndex({ model: openaiLib.EMBED_MODEL, chunks: [] });
    return;
  }

  console.log(`Embedding ${chunks.length} chunk(s)...`);
  const batchSize = 64;
  for (let i = 0; i < chunks.length; i += batchSize) {
    const batch = chunks.slice(i, i + batchSize);
    const vectors = await openaiLib.embed(batch.map((c) => c.text));
    batch.forEach((c, j) => {
      c.embedding = vectors[j];
    });
    console.log(`  embedded ${Math.min(i + batchSize, chunks.length)}/${chunks.length}`);
  }

  docsIndex.saveIndex({ model: openaiLib.EMBED_MODEL, chunks });
  console.log(`\nDONE. Wrote ${chunks.length} chunk(s) to ${docsIndex.DEFAULT_INDEX_FILE}`);
}

main().catch((err) => {
  console.error("\nIndexing failed:");
  console.error(err);
  process.exit(1);
});
