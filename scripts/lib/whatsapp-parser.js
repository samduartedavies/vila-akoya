/**
 * Parse a WhatsApp iOS/Android export (_chat.txt + media files).
 */

"use strict";

const fs = require("fs");
const path = require("path");

const MSG_RE =
  /^\[(\d{1,2}\/\d{1,2}\/\d{4}), (\d{1,2}:\d{2}:\d{2})\] ([^:]+): (.*)$/;
const ATTACHED_RE = /<attached:\s*([^>]+)>/i;
const OMITTED_RE = /(image|video|audio|document|sticker|gif) omitted/i;

function stripLine(line) {
  return String(line || "")
    .replace(/^[\u200e\u200f\u202a\u202c\u202d\u202e\ufeff]+/, "")
    .replace(/[\u200e\u200f\u202a\u202c\u202d\u202e\ufeff]/g, "")
    .trimEnd();
}

function parseDate(dateStr, timeStr) {
  const [d, m, y] = dateStr.split("/").map(Number);
  const [hh, mm, ss] = timeStr.split(":").map(Number);
  return new Date(y, m - 1, d, hh, mm, ss);
}

function mediaKind(filename) {
  const ext = path.extname(filename).toLowerCase();
  if ([".jpg", ".jpeg", ".png", ".webp", ".gif", ".heic"].includes(ext)) {
    return "photo";
  }
  if ([".mp4", ".mov", ".m4v", ".3gp"].includes(ext)) return "video";
  return "document";
}

function isSystemMessage(body) {
  const t = body.trim();
  if (!t) return true;
  if (OMITTED_RE.test(t)) return true;
  if (/Messages and calls are end-to-end encrypted/i.test(t)) return true;
  if (/created (this|the) group/i.test(t)) return true;
  if (/changed the (group )?(description|subject|icon|photo)/i.test(t)) return true;
  if (/pinned a message/i.test(t)) return true;
  if (/\b(was added|were added|was removed|left)\b/i.test(t)) return true;
  if (/added ~|added \u2068|You joined using/i.test(t)) return true;
  if (/security code changed/i.test(t)) return true;
  if (/Waiting for this message/i.test(t)) return true;
  if (/^‎?<attached:/i.test(t) && !ATTACHED_RE.test(t)) return true;
  return false;
}

function extractAttachment(body, mediaDir) {
  const match = body.match(ATTACHED_RE);
  if (!match) return null;

  const filename = match[1].trim();
  const filePath = path.join(mediaDir, filename);
  if (!fs.existsSync(filePath)) return null;

  let caption = body
    .replace(ATTACHED_RE, "")
    .replace(/•\s*‎?\d+\s*pages?/gi, "")
    .trim();

  if (OMITTED_RE.test(caption)) caption = "";

  return {
    filename,
    filePath,
    kind: mediaKind(filename),
    caption,
  };
}

/**
 * @returns {Array<{index:number,date:Date,dateStr:string,timeStr:string,sender:string,body:string,type:string,attachment?:object}>}
 */
function parseChatFile(chatPath, mediaDir) {
  const raw = fs.readFileSync(chatPath, "utf8");
  const lines = raw.split(/\n/);
  const messages = [];
  let current = null;

  for (const rawLine of lines) {
    const line = stripLine(rawLine);
    if (!line.trim()) {
      if (current) current.body += "\n";
      continue;
    }

    const m = line.match(MSG_RE);
    if (m) {
      if (current) messages.push(current);
      current = {
        index: messages.length,
        dateStr: m[1],
        timeStr: m[2],
        date: parseDate(m[1], m[2]),
        sender: m[3].trim(),
        body: m[4].trim(),
        type: "text",
      };
      continue;
    }

    if (current) {
      current.body += (current.body ? "\n" : "") + line;
    }
  }
  if (current) messages.push(current);

  for (let i = 0; i < messages.length; i++) {
    const msg = messages[i];
    msg.index = i;

    const attachment = extractAttachment(msg.body, mediaDir);
    if (attachment) {
      msg.attachment = attachment;
      msg.type = attachment.kind;
      if (attachment.caption) msg.body = attachment.caption;
      else msg.body = "";
    } else if (OMITTED_RE.test(msg.body) || isSystemMessage(msg.body)) {
      msg.type = "system";
    } else {
      msg.type = "text";
    }
  }

  return messages;
}

/**
 * Turn parsed messages into postable events. Consecutive photos from the same
 * sender within `albumWindowSec` become one Telegram album (max 10 photos).
 */
function buildPostPlan(messages, { albumWindowSec = 120, albumMax = 10 } = {}) {
  const events = [];

  function flushAlbum(album) {
    if (!album) return;
    events.push({
      type: "album",
      date: album.date,
      sender: album.sender,
      photos: album.photos,
      caption: album.caption,
      sourceIndexes: album.sourceIndexes,
    });
  }

  let album = null;

  function closeAlbum() {
    flushAlbum(album);
    album = null;
  }

  for (const msg of messages) {
    if (msg.type === "system") {
      closeAlbum();
      events.push({
        type: "system",
        date: msg.date,
        sender: msg.sender,
        text: msg.body,
        sourceIndexes: [msg.index],
      });
      continue;
    }

    if (msg.type === "photo") {
      const canJoin =
        album &&
        album.sender === msg.sender &&
        msg.date - album.lastDate <= albumWindowSec * 1000 &&
        album.photos.length < albumMax;

      if (canJoin) {
        album.photos.push({
          filePath: msg.attachment.filePath,
          filename: msg.attachment.filename,
        });
        album.lastDate = msg.date;
        album.sourceIndexes.push(msg.index);
        if (msg.body && !album.caption) album.caption = msg.body;
        continue;
      }

      closeAlbum();
      album = {
        date: msg.date,
        sender: msg.sender,
        photos: [
          {
            filePath: msg.attachment.filePath,
            filename: msg.attachment.filename,
          },
        ],
        caption: msg.body || "",
        lastDate: msg.date,
        sourceIndexes: [msg.index],
      };
      continue;
    }

    closeAlbum();

    if (msg.type === "video" || msg.type === "document") {
      events.push({
        type: msg.type,
        date: msg.date,
        sender: msg.sender,
        filePath: msg.attachment.filePath,
        filename: msg.attachment.filename,
        caption: msg.body || "",
        sourceIndexes: [msg.index],
      });
      continue;
    }

    events.push({
      type: "text",
      date: msg.date,
      sender: msg.sender,
      text: msg.body,
      sourceIndexes: [msg.index],
    });
  }

  closeAlbum();
  return events;
}

module.exports = {
  parseChatFile,
  buildPostPlan,
  stripLine,
  isSystemMessage,
};
