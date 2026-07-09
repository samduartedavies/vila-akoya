/**
 * Vila Akoya — documentation indexer
 *
 * Pulls monday.com docs (workdocs) from the workspace, extracts their text,
 * chunks + embeds it with OpenAI, and writes a small local vector index that
 * the Telegram bot's /ask command searches.
 *
 * Run whenever docs change (manually, or on a cron / launchd timer):
 *   MONDAY_API_TOKEN="..." OPENAI_API_KEY="..." \
 *   node scripts/index-vila-akoya-docs.js
 *
 * Optional env:
 *   MONDAY_WORKSPACE_ID   limit to one workspace (recommended)
 *   MONDAY_API_VERSION    default "2026-10"
 *   DOCS_INDEX_FILE       where to write the index (default lib/.vila-akoya-docs-index.json)
 */

"use strict";

const openaiLib = require("./lib/openai");
const docsIndex = require("./lib/docs-index");

const MONDAY_API_TOKEN =
  process.env.MONDAY_API_TOKEN ||
  "eyJhbGciOiJIUzI1NiJ9.eyJ0aWQiOjY3NzQ0MDk2NywiYWFpIjoxMSwidWlkIjoxMDcyNzM0NDIsImlhZCI6IjIwMjYtMDctMDFUMDk6NDU6NTYuMDAwWiIsInBlciI6Im1lOndyaXRlIiwiYWN0aWQiOjM1ODIxNzEyLCJyZ24iOiJldWMxIn0.fUMN4Zpvsm7hOx8xyo4Qb76Ovi_tDs-7nhhdu8ZIyEg";
const WORKSPACE_ID = process.env.MONDAY_WORKSPACE_ID || 6923231;
const MONDAY_API_VERSION = process.env.MONDAY_API_VERSION || "2026-10";
const MONDAY_API_URL = "https://api.monday.com/v2";

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function monday(query, variables = {}, attempt = 1) {
  const res = await fetch(MONDAY_API_URL, {
    method: "POST",
    headers: {
      Authorization: MONDAY_API_TOKEN,
      "Content-Type": "application/json",
      "API-Version": MONDAY_API_VERSION,
    },
    body: JSON.stringify({ query, variables }),
  });
  const json = await res.json();

  const complexityError = json.errors?.find(
    (err) => err.extensions?.code === "COMPLEXITY_BUDGET_EXHAUSTED"
  );
  if (complexityError && attempt <= 8) {
    const wait =
      Number(complexityError.extensions?.retry_in_seconds || 0) ||
      Math.min(60, attempt * 5);
    console.log(`Complexity budget exhausted, waiting ${wait + 2}s...`);
    await sleep((wait + 2) * 1000);
    return monday(query, variables, attempt + 1);
  }
  if (json.errors) throw new Error(JSON.stringify(json.errors));
  if (!res.ok) throw new Error(`monday HTTP ${res.status}`);
  return json.data;
}

// Recursively pull every "insert" string out of a monday doc block's content.
function extractInserts(node, out) {
  if (node == null) return;
  if (Array.isArray(node)) {
    for (const child of node) extractInserts(child, out);
    return;
  }
  if (typeof node === "object") {
    if (typeof node.insert === "string") out.push(node.insert);
    for (const key of Object.keys(node)) {
      if (key !== "insert") extractInserts(node[key], out);
    }
  }
}

function blockToText(block) {
  if (!block?.content) return "";
  let parsed;
  try {
    parsed = JSON.parse(block.content);
  } catch {
    return "";
  }
  const inserts = [];
  extractInserts(parsed, inserts);
  return inserts.join("").trim();
}

async function fetchAllDocs() {
  const docs = [];
  let page = 1;
  const wsFilter = WORKSPACE_ID ? `, workspace_ids: [${WORKSPACE_ID}]` : "";
  // eslint-disable-next-line no-constant-condition
  while (true) {
    const data = await monday(`
      query {
        docs(limit: 50, page: ${page}${wsFilter}) {
          id
          object_id
          name
          url
          blocks { id type content }
        }
      }
    `);
    const batch = data.docs || [];
    docs.push(...batch);
    if (batch.length < 50) break;
    page += 1;
  }
  return docs;
}

async function main() {
  if (!openaiLib.isEnabled()) {
    throw new Error("Missing OPENAI_API_KEY — cannot build the docs index.");
  }
  if (!MONDAY_API_TOKEN) {
    throw new Error("Missing MONDAY_API_TOKEN.");
  }

  console.log("Fetching monday docs...");
  const docs = await fetchAllDocs();
  console.log(`Found ${docs.length} docs.`);

  const chunks = [];
  for (const doc of docs) {
    const text = (doc.blocks || [])
      .map(blockToText)
      .filter(Boolean)
      .join("\n\n");
    if (!text.trim()) continue;

    const pieces = docsIndex.chunkText(text);
    pieces.forEach((piece, i) => {
      chunks.push({
        id: `${doc.id}-${i}`,
        docId: doc.id,
        docName: doc.name || `Doc ${doc.id}`,
        url: doc.url || null,
        text: piece,
      });
    });
    console.log(`  ${doc.name}: ${pieces.length} chunk(s)`);
  }

  if (!chunks.length) {
    console.log(
      "No text found in any doc. Create monday docs (e.g. from the '50 — Documentation Register' board) and rerun."
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
