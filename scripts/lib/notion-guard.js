/**
 * Telegram bot must not delete or archive Notion content — only add and edit.
 */

"use strict";

const REMOVAL_WORDS = /\b(delete|remove|erase|trash|drop|purge|wipe)\b/i;

const REMOVAL_TARGET =
  /\b(notion|page|row|entry|database|board|task|event|receipt|expense|invoice|project|member|doc|faq)\b/i;

const ARCHIVE_REQUEST = /\b(archive|unpublish|hide)\b/i;

/** Cancel / discontinue gatherings — mark done in Notion, not delete. */
function looksLikeEventCancellationRequest(text) {
  const t = String(text || "").toLowerCase();
  if (!/\b(events?|workshop|class|session|gathering|market|course)\b/.test(t)) {
    return false;
  }
  if (
    /\b(cancel|cancelled|canceled|called off|no more|not happening|discontinued|stopped|ended)\b/.test(
      t
    )
  ) {
    return true;
  }
  if (/\b(remove|delete|drop)\b/.test(t) && /\bevents?\b/.test(t)) {
    return true;
  }
  return false;
}

/** Change when a Telegram broadcast reminder runs (recurrence), not delete the row. */
function looksLikeReminderScheduleUpdate(text) {
  const t = String(text || "").toLowerCase();
  if (!/\b(reminder|morning meeting|daily roundup|broadcast)\b/.test(t)) {
    return false;
  }
  return (
    /\b(update|change|set|only|never|not on|skip|weekdays?|monday|friday|weekend)\b/.test(t) ||
    /\b(monday|mon)\b.*\b(friday|fri)\b/.test(t)
  );
}

function messageRequestsNotionRemoval(text) {
  const t = String(text || "").trim();
  if (!t) return false;
  if (looksLikeEventCancellationRequest(t)) return false;
  if (looksLikeReminderScheduleUpdate(t)) return false;
  if (ARCHIVE_REQUEST.test(t) && REMOVAL_TARGET.test(t)) return true;
  if (!REMOVAL_WORDS.test(t)) return false;
  return (
    REMOVAL_TARGET.test(t) ||
    /\bfrom notion\b/i.test(t) ||
    /\bclear\b.*\b(board|database|list)\b/i.test(t)
  );
}

const BLOCKED_WRITE_KEYS = /^(archive|archived|delete|deleted|remove|removed)$/i;

function assertSafeNotionProperties(properties) {
  if (!properties || typeof properties !== "object") return;
  for (const key of Object.keys(properties)) {
    if (BLOCKED_WRITE_KEYS.test(key)) {
      throw new Error(`Notion guard: cannot set property "${key}" from Telegram`);
    }
    const val = properties[key];
    if (typeof val === "string" && /^(deleted|archived|removed)$/i.test(val.trim())) {
      throw new Error(`Notion guard: cannot set "${key}" to removed/archived`);
    }
  }
}

function assertSafeNotionOp(op) {
  const bad = ["delete", "remove", "archive", "erase", "trash"];
  if (bad.includes(String(op || "").toLowerCase())) {
    throw new Error(`Notion guard: op "${op}" is not allowed from Telegram`);
  }
}

module.exports = {
  messageRequestsNotionRemoval,
  looksLikeEventCancellationRequest,
  looksLikeReminderScheduleUpdate,
  assertSafeNotionProperties,
  assertSafeNotionOp,
};
