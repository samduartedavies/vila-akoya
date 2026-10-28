/**
 * Load KEY=value lines from the repo-root .env into process.env (zero deps).
 * Values already set in the environment win over the file.
 */

"use strict";

const fs = require("fs");
const path = require("path");

const ENV_FILE = process.env.VILA_ENV_FILE || path.join(__dirname, "..", "..", ".env");

function loadEnv(file = ENV_FILE) {
  let raw;
  try {
    raw = fs.readFileSync(file, "utf8");
  } catch {
    return false;
  }
  for (const line of raw.split(/\r?\n/)) {
    const m = line.match(/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/);
    if (!m) continue;
    const [, key, rawValue] = m;
    if (process.env[key] !== undefined && process.env[key] !== "") continue;
    let value = rawValue;
    const quoted = value.match(/^(['"])(.*)\1$/);
    if (quoted) value = quoted[2];
    else value = value.replace(/\s+#.*$/, "");
    process.env[key] = value;
  }
  return true;
}

loadEnv();

module.exports = { loadEnv, ENV_FILE };
