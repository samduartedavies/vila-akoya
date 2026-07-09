/**
 * Tiny local vector index for Vila Akoya documentation.
 *
 * The corpus is small (dozens of docs), so we keep it dead simple:
 * embeddings live in a single JSON file and search is a plain in-memory
 * cosine similarity scan. No vector database required.
 */

"use strict";

const fs = require("fs");
const path = require("path");

const DEFAULT_INDEX_FILE =
  process.env.DOCS_INDEX_FILE ||
  path.join(__dirname, "..", ".vila-akoya-docs-index.json");

/**
 * Split text into overlapping chunks on paragraph/sentence boundaries.
 */
function chunkText(text, { maxChars = 1200, overlap = 150 } = {}) {
  const clean = String(text || "").replace(/\r\n/g, "\n").trim();
  if (!clean) return [];
  if (clean.length <= maxChars) return [clean];

  const chunks = [];
  let start = 0;
  while (start < clean.length) {
    let end = Math.min(start + maxChars, clean.length);
    if (end < clean.length) {
      // Prefer to break on a paragraph or sentence boundary.
      const slice = clean.slice(start, end);
      const breakAt = Math.max(
        slice.lastIndexOf("\n\n"),
        slice.lastIndexOf(". "),
        slice.lastIndexOf("\n")
      );
      if (breakAt > maxChars * 0.5) end = start + breakAt + 1;
    }
    chunks.push(clean.slice(start, end).trim());
    if (end >= clean.length) break;
    start = end - overlap;
    if (start < 0) start = 0;
  }
  return chunks.filter(Boolean);
}

function loadIndex(file = DEFAULT_INDEX_FILE) {
  try {
    const raw = JSON.parse(fs.readFileSync(file, "utf8"));
    return {
      model: raw.model || null,
      builtAt: raw.builtAt || null,
      chunks: Array.isArray(raw.chunks) ? raw.chunks : [],
      file,
    };
  } catch {
    return { model: null, builtAt: null, chunks: [], file };
  }
}

function saveIndex(index, file = DEFAULT_INDEX_FILE) {
  fs.writeFileSync(
    file,
    JSON.stringify(
      {
        model: index.model || null,
        builtAt: new Date().toISOString(),
        chunks: index.chunks || [],
      },
      null,
      2
    )
  );
}

function fileMtime(file = DEFAULT_INDEX_FILE) {
  try {
    return fs.statSync(file).mtimeMs;
  } catch {
    return 0;
  }
}

function cosine(a, b) {
  let dot = 0;
  let na = 0;
  let nb = 0;
  for (let i = 0; i < a.length; i++) {
    dot += a[i] * b[i];
    na += a[i] * a[i];
    nb += b[i] * b[i];
  }
  if (na === 0 || nb === 0) return 0;
  return dot / (Math.sqrt(na) * Math.sqrt(nb));
}

/**
 * Return the top-k chunks for a query embedding, each with a `score`.
 */
function search(index, queryEmbedding, k = 4) {
  const scored = (index.chunks || []).map((chunk) => ({
    ...chunk,
    score: cosine(queryEmbedding, chunk.embedding),
  }));
  scored.sort((a, b) => b.score - a.score);
  return scored.slice(0, k);
}

module.exports = {
  DEFAULT_INDEX_FILE,
  chunkText,
  loadIndex,
  saveIndex,
  fileMtime,
  cosine,
  search,
};
