/**
 * Import a WhatsApp chat export into a SEPARATE Telegram archive group.
 *
 * Use a dedicated group for history (e.g. "Vila Akoya — WhatsApp Archive").
 * Do NOT import into the Jobs & Docs bot group — keep that for live tasks only.
 *
 * The bot is only needed temporarily to post messages; remove it from the
 * archive group after import if you like. The task bot ignores all groups
 * except TASK_GROUP_CHAT_ID, so it will not react to archive chat even if
 * it remains a member.
 *
 * Run:
 *   TELEGRAM_BOT_TOKEN="..." \
 *   WHATSAPP_IMPORT_CHAT_ID="-100..." \
 *   node scripts/import-whatsapp-to-telegram.js
 *
 * Setup:
 *   1. Create "Vila Akoya — WhatsApp Archive" group in Telegram
 *   2. Add @vila_akoya_bot (admin recommended for large uploads)
 *   3. Send any message in that group
 *   4. npm run telegram:chats   → copy the archive group's negative id
 *   5. WHATSAPP_IMPORT_CHAT_ID="-..." npm run whatsapp:import
 *   6. (Optional) remove bot from archive group when done
 *
 * Optional env:
 *   WHATSAPP_IMPORT_CHAT_ID  target archive group (alias: TELEGRAM_IMPORT_CHAT_ID)
 *   WHATSAPP_ZIP           path to export zip (default: scripts/WhatsApp Chat - Vila Akoya - General.zip)
 *   WHATSAPP_EXTRACT_DIR   extracted media folder (auto-created from zip)
 *   SKIP_SYSTEM_MESSAGES   "true" (default) — skip "X was added" etc.
 *   POST_INTRO             "false" to skip the archive header message
 *   POST_DAY_MARKERS       "true" to post a date separator at each new day
 *   DRY_RUN                "true" — parse and print plan only, no Telegram posts
 *   START_AT_EVENT         resume from event index (also reads state file)
 *   PACING_MS              delay between posts (default 1200)
 *   ALBUM_WINDOW_SEC       group photos within N seconds (default 120)
 *   MAX_MB                 skip files larger than this (default 48; bot limit ~50MB)
 *   STATE_FILE             resume checkpoint path
 */

"use strict";

const fs = require("fs");
const path = require("path");
const { execSync } = require("child_process");
require("./lib/load-env");
const { parseChatFile, buildPostPlan } = require("./lib/whatsapp-parser");

const TELEGRAM_BOT_TOKEN = process.env.TELEGRAM_BOT_TOKEN || "";
const WHATSAPP_IMPORT_CHAT_ID =
  process.env.WHATSAPP_IMPORT_CHAT_ID ||
  process.env.TELEGRAM_IMPORT_CHAT_ID ||
  process.env.TELEGRAM_CHAT_ID ||
  "";

const WHATSAPP_ZIP =
  process.env.WHATSAPP_ZIP ||
  path.join(__dirname, "WhatsApp Chat - Vila Akoya - General.zip");
const WHATSAPP_EXTRACT_DIR =
  process.env.WHATSAPP_EXTRACT_DIR ||
  path.join(__dirname, ".wa-import-cache");

const SKIP_SYSTEM = process.env.SKIP_SYSTEM_MESSAGES !== "false";
const POST_INTRO = process.env.POST_INTRO !== "false";
const POST_DAY_MARKERS = process.env.POST_DAY_MARKERS === "true";
const DRY_RUN = process.env.DRY_RUN === "true";
const START_AT_EVENT = Number(process.env.START_AT_EVENT || 0);
const PACING_MS = Number(process.env.PACING_MS || 1200);
const ALBUM_WINDOW_SEC = Number(process.env.ALBUM_WINDOW_SEC || 120);
const MAX_MB = Number(process.env.MAX_MB || 48);
const STATE_FILE =
  process.env.STATE_FILE ||
  path.join(__dirname, ".wa-telegram-import-state.json");

const TELEGRAM_API = `https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}`;
const MAX_BYTES = MAX_MB * 1024 * 1024;

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

function log(...args) {
  console.log(new Date().toISOString(), ...args);
}

function escapeHtml(text) {
  return String(text ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

function formatHeader(date, sender) {
  const d = date instanceof Date ? date : new Date(date);
  const pad = (n) => String(n).padStart(2, "0");
  const stamp = `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
  return `<b>[${stamp}] ${escapeHtml(sender)}</b>`;
}

function loadState() {
  try {
    return JSON.parse(fs.readFileSync(STATE_FILE, "utf8"));
  } catch {
    return { lastEventIndex: -1, posted: 0, skipped: 0 };
  }
}

function saveState(state) {
  fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2));
}

function ensureExtracted() {
  const chatPath = path.join(WHATSAPP_EXTRACT_DIR, "_chat.txt");
  if (fs.existsSync(chatPath)) {
    log(`Using extracted export: ${WHATSAPP_EXTRACT_DIR}`);
    return chatPath;
  }

  if (!fs.existsSync(WHATSAPP_ZIP)) {
    throw new Error(`WhatsApp zip not found: ${WHATSAPP_ZIP}`);
  }

  fs.mkdirSync(WHATSAPP_EXTRACT_DIR, { recursive: true });
  log(`Extracting ${WHATSAPP_ZIP} ...`);
  execSync(`unzip -qo ${JSON.stringify(WHATSAPP_ZIP)} -d ${JSON.stringify(WHATSAPP_EXTRACT_DIR)}`, {
    stdio: "inherit",
  });

  if (!fs.existsSync(chatPath)) {
    throw new Error(`No _chat.txt found after extracting ${WHATSAPP_ZIP}`);
  }
  return chatPath;
}

async function telegram(method, payload, { multipart = false, attempt = 1 } = {}) {
  let res;
  if (multipart) {
    res = await fetch(`${TELEGRAM_API}/${method}`, {
      method: "POST",
      body: payload,
    });
  } else {
    res = await fetch(`${TELEGRAM_API}/${method}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
  }

  const json = await res.json();
  if (!json.ok) {
    const retry = json.parameters?.retry_after;
    if (json.error_code === 429 && retry && attempt <= 8) {
      log(`Rate limited, waiting ${retry + 1}s...`);
      await sleep((retry + 1) * 1000);
      return telegram(method, payload, { multipart, attempt: attempt + 1 });
    }
    const err = new Error(`Telegram ${method}: ${JSON.stringify(json)}`);
    err.telegram = json;
    throw err;
  }
  return json.result;
}

/** Try common id formats; group ids are usually negative. */
function chatIdCandidates(rawId) {
  const s = String(rawId).trim();
  const out = new Set([s]);
  if (/^\d+$/.test(s)) out.add(`-${s}`);
  if (/^-\d+$/.test(s)) out.add(s.slice(1));
  return [...out];
}

async function resolveTelegramChatId(rawId) {
  const tried = [];
  for (const id of chatIdCandidates(rawId)) {
    tried.push(id);
    try {
      const chat = await telegram("getChat", { chat_id: id });
      return { id, chat };
    } catch (err) {
      if (err.telegram?.error_code !== 400) throw err;
    }
  }

  throw new Error(
    `Telegram chat not found for WHATSAPP_IMPORT_CHAT_ID=${rawId} (tried: ${tried.join(", ")}).\n\n` +
      "Use a separate archive group (not the Jobs & Docs bot group).\n" +
      "1. Create e.g. \"Vila Akoya — WhatsApp Archive\"\n" +
      "2. Add @vila_akoya_bot to that group only\n" +
      "3. Send any message in the group\n" +
      "4. Run: npm run telegram:chats\n" +
      "5. WHATSAPP_IMPORT_CHAT_ID=\"-...\" npm run whatsapp:import"
  );
}

function fileTooLarge(filePath) {
  const size = fs.statSync(filePath).size;
  return size > MAX_BYTES ? size : 0;
}

async function sendText(chatId, html) {
  if (DRY_RUN) {
    log("[dry-run] text:", html.slice(0, 120));
    return;
  }
  await telegram("sendMessage", {
    chat_id: chatId,
    text: html.slice(0, 4096),
    parse_mode: "HTML",
    disable_web_page_preview: false,
  });
}

async function sendPhoto(chatId, filePath, caption) {
  const big = fileTooLarge(filePath);
  if (big) {
    log(`Skipping large photo (${(big / 1024 / 1024).toFixed(1)}MB): ${path.basename(filePath)}`);
    return false;
  }

  if (DRY_RUN) {
    log("[dry-run] photo:", path.basename(filePath), caption?.slice(0, 60) || "");
    return true;
  }

  const form = new FormData();
  form.append("chat_id", String(chatId));
  if (caption) {
    form.append("caption", caption.slice(0, 1024));
    form.append("parse_mode", "HTML");
  }
  form.append("photo", new Blob([fs.readFileSync(filePath)]), path.basename(filePath));
  await telegram("sendPhoto", form, { multipart: true });
  return true;
}

async function sendVideo(chatId, filePath, caption) {
  const big = fileTooLarge(filePath);
  if (big) {
    log(`File too large for video (${(big / 1024 / 1024).toFixed(1)}MB), sending as document: ${path.basename(filePath)}`);
    return sendDocument(chatId, filePath, caption);
  }

  if (DRY_RUN) {
    log("[dry-run] video:", path.basename(filePath));
    return true;
  }

  const form = new FormData();
  form.append("chat_id", String(chatId));
  if (caption) {
    form.append("caption", caption.slice(0, 1024));
    form.append("parse_mode", "HTML");
  }
  form.append("video", new Blob([fs.readFileSync(filePath)]), path.basename(filePath));
  await telegram("sendVideo", form, { multipart: true });
  return true;
}

async function sendDocument(chatId, filePath, caption) {
  const big = fileTooLarge(filePath);
  if (big) {
    log(`Skipping large file (${(big / 1024 / 1024).toFixed(1)}MB): ${path.basename(filePath)}`);
    await sendText(
      chatId,
      `${caption || formatHeader(new Date(), "?")}\n\n<i>(file too large to import: ${escapeHtml(path.basename(filePath))})</i>`
    );
    return false;
  }

  if (DRY_RUN) {
    log("[dry-run] document:", path.basename(filePath));
    return true;
  }

  const form = new FormData();
  form.append("chat_id", String(chatId));
  if (caption) {
    form.append("caption", caption.slice(0, 1024));
    form.append("parse_mode", "HTML");
  }
  form.append("document", new Blob([fs.readFileSync(filePath)]), path.basename(filePath));
  await telegram("sendDocument", form, { multipart: true });
  return true;
}

async function sendAlbum(chatId, photos, caption) {
  if (!photos.length) return false;
  if (photos.length === 1) {
    return sendPhoto(chatId, photos[0].filePath, caption);
  }

  const usable = [];
  for (const p of photos) {
    const big = fileTooLarge(p.filePath);
    if (big) {
      log(`Skipping large photo in album: ${p.filename}`);
      continue;
    }
    usable.push(p);
    if (usable.length >= 10) break;
  }

  if (!usable.length) return false;
  if (usable.length === 1) {
    return sendPhoto(chatId, usable[0].filePath, caption);
  }

  if (DRY_RUN) {
    log("[dry-run] album:", usable.map((p) => p.filename).join(", "));
    return true;
  }

  const media = usable.map((p, i) => ({
    type: "photo",
    media: `attach://p${i}`,
    ...(i === 0 && caption
      ? { caption: caption.slice(0, 1024), parse_mode: "HTML" }
      : {}),
  }));

  const form = new FormData();
  form.append("chat_id", String(chatId));
  form.append("media", JSON.stringify(media));
  usable.forEach((p, i) => {
    form.append(`p${i}`, new Blob([fs.readFileSync(p.filePath)]), p.filename);
  });

  await telegram("sendMediaGroup", form, { multipart: true });
  return true;
}

async function postEvent(chatId, event) {
  const header = formatHeader(event.date, event.sender);

  if (event.type === "system") {
    if (SKIP_SYSTEM) return "skipped";
    await sendText(chatId, `${header}\n<i>${escapeHtml(event.text)}</i>`);
    return "posted";
  }

  if (event.type === "text") {
    if (!event.text?.trim()) return "skipped";
    await sendText(chatId, `${header}\n${escapeHtml(event.text)}`);
    return "posted";
  }

  if (event.type === "album") {
    const caption = event.caption
      ? `${header}\n${escapeHtml(event.caption)}`
      : header;
    await sendAlbum(chatId, event.photos, caption);
    return "posted";
  }

  if (event.type === "video") {
    const caption = event.caption
      ? `${header}\n${escapeHtml(event.caption)}`
      : header;
    await sendVideo(chatId, event.filePath, caption);
    return "posted";
  }

  if (event.type === "document") {
    const caption = event.caption
      ? `${header}\n${escapeHtml(event.caption)}`
      : header;
    await sendDocument(chatId, event.filePath, caption);
    return "posted";
  }

  return "skipped";
}

function dayKey(date) {
  const d = date instanceof Date ? date : new Date(date);
  return `${d.getFullYear()}-${d.getMonth() + 1}-${d.getDate()}`;
}

async function main() {
  if (!DRY_RUN && !TELEGRAM_BOT_TOKEN) {
    throw new Error("Missing TELEGRAM_BOT_TOKEN");
  }
  if (!DRY_RUN && !WHATSAPP_IMPORT_CHAT_ID) {
    throw new Error(
      "Missing WHATSAPP_IMPORT_CHAT_ID.\n" +
        "Create a separate archive group, add the bot, run npm run telegram:chats, then:\n" +
        "  WHATSAPP_IMPORT_CHAT_ID=\"-your_archive_group_id\" npm run whatsapp:import"
    );
  }

  let targetChatId = WHATSAPP_IMPORT_CHAT_ID;
  if (!DRY_RUN) {
    const resolved = await resolveTelegramChatId(WHATSAPP_IMPORT_CHAT_ID);
    targetChatId = resolved.id;
    const label = resolved.chat.title || resolved.chat.first_name || resolved.chat.username;
    log(`Archive target: ${label} (id ${targetChatId})`);
    if (String(WHATSAPP_IMPORT_CHAT_ID) !== String(targetChatId)) {
      log(`Note: WHATSAPP_IMPORT_CHAT_ID=${WHATSAPP_IMPORT_CHAT_ID} resolved to ${targetChatId}`);
    }
  }

  const chatPath = ensureExtracted();
  const messages = parseChatFile(chatPath, WHATSAPP_EXTRACT_DIR);
  const events = buildPostPlan(messages, { albumWindowSec: ALBUM_WINDOW_SEC });

  const counts = events.reduce((acc, e) => {
    acc[e.type] = (acc[e.type] || 0) + 1;
    return acc;
  }, {});

  log(`Parsed ${messages.length} WhatsApp messages → ${events.length} Telegram events`);
  log("Event breakdown:", counts);

  const state = loadState();
  let startAt = START_AT_EVENT > 0 ? START_AT_EVENT : state.lastEventIndex + 1;
  if (startAt < 0) startAt = 0;

  if (!DRY_RUN && POST_INTRO && startAt === 0) {
    const first = messages.find((m) => m.type !== "system") || messages[0];
    const last = messages[messages.length - 1];
    await sendText(
      targetChatId,
      "<b>📦 WhatsApp archive import</b>\n" +
        `This group is receiving the imported history from <b>Vila Akoya — General</b>.\n` +
        `Messages are prefixed with their original date and sender.\n` +
        `Range: ${escapeHtml(first?.dateStr || "?")} → ${escapeHtml(last?.dateStr || "?")}\n` +
        `<i>(${messages.length} messages, ${events.length} posts — photos grouped into albums where possible)</i>`
    );
    await sleep(PACING_MS);
  }

  let posted = state.posted || 0;
  let skipped = state.skipped || 0;
  let lastDay = null;

  for (let i = startAt; i < events.length; i++) {
    const event = events[i];

    if (POST_DAY_MARKERS && event.type !== "system") {
      const dk = dayKey(event.date);
      if (dk !== lastDay) {
        lastDay = dk;
        const d = event.date;
        const label = d.toLocaleDateString("en-GB", {
          weekday: "long",
          year: "numeric",
          month: "long",
          day: "numeric",
        });
        if (!DRY_RUN) {
          await sendText(targetChatId, `<b>── ${escapeHtml(label)} ──</b>`);
          await sleep(PACING_MS);
        } else {
          log("[dry-run] day marker:", label);
        }
      }
    }

    try {
      const result = await postEvent(targetChatId, event);
      if (result === "posted") posted++;
      else skipped++;

      if ((i + 1) % 25 === 0 || i === events.length - 1) {
        log(`Progress ${i + 1}/${events.length} (posted ${posted}, skipped ${skipped})`);
      }

      if (!DRY_RUN) {
        saveState({ lastEventIndex: i, posted, skipped });
      }
    } catch (err) {
      log(`Failed at event ${i} (${event.type}, ${event.sender}):`, err.message);
      if (!DRY_RUN) {
        saveState({ lastEventIndex: i - 1, posted, skipped, lastError: err.message });
      }
      throw err;
    }

    await sleep(PACING_MS);
  }

  log(`\nDONE. Posted ${posted}, skipped ${skipped}.`);
  if (!DRY_RUN) {
    saveState({ lastEventIndex: events.length - 1, posted, skipped, completedAt: new Date().toISOString() });
  }
}

main().catch((err) => {
  console.error("\nImport failed:");
  console.error(err);
  process.exit(1);
});
