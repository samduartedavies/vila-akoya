/**
 * Recurring duty due dates and completion scheduling.
 */

"use strict";

function addDaysISO(iso, days) {
  const [y, m, d] = iso.split("-").map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d + days, 12));
  return dt.toISOString().slice(0, 10);
}

function addMonthsISO(iso, months) {
  const [y, m, d] = iso.split("-").map(Number);
  const dt = new Date(Date.UTC(y, m - 1 + months, 1, 12));
  const lastDay = new Date(Date.UTC(dt.getUTCFullYear(), dt.getUTCMonth() + 1, 0)).getUTCDate();
  dt.setUTCDate(Math.min(d, lastDay));
  return dt.toISOString().slice(0, 10);
}

function daysBetween(fromISO, toISO) {
  const a = new Date(fromISO + "T12:00:00Z").getTime();
  const b = new Date(toISO + "T12:00:00Z").getTime();
  return Math.round((b - a) / 86400000);
}

/** Next due date after completing a duty on completedISO. */
function nextDueAfterCompletion(frequency, completedISO) {
  const freq = String(frequency || "Daily").toLowerCase();
  if (freq === "weekly") return addDaysISO(completedISO, 7);
  if (freq === "monthly") return addMonthsISO(completedISO, 1);
  if (freq === "seasonal") return addMonthsISO(completedISO, 3);
  // Daily (default)
  return addDaysISO(completedISO, 1);
}

/**
 * Is this duty due on dateISO?
 * Uses Next due date when set; otherwise Frequency + Last completed.
 */
function isDutyDueOn(dateISO, { frequency, lastCompleted, nextDue, status }) {
  const st = String(status || "").toLowerCase();
  if (st === "paused" || st === "done" || st === "archived") return false;

  const last = (lastCompleted || "").slice(0, 10);
  if (last && last === dateISO) return false; // already done today

  const next = (nextDue || "").slice(0, 10);
  if (next) return next <= dateISO;

  // No next-due yet — infer from last completed + frequency
  if (!last) return true;
  const freq = String(frequency || "Daily").toLowerCase();
  const gap = daysBetween(last, dateISO);
  if (freq === "weekly") return gap >= 7;
  if (freq === "monthly") return gap >= 28;
  if (freq === "seasonal") return gap >= 85;
  return gap >= 1; // daily
}

/** Spread N weekly duties across the week (first due baseISO, then +step days). */
function staggeredWeeklyDueDate(baseISO, weeklyIndex, weeklyCount) {
  if (!baseISO || weeklyCount <= 1 || weeklyIndex <= 0) return baseISO;
  const step = Math.max(1, Math.floor(7 / weeklyCount));
  return addDaysISO(baseISO, weeklyIndex * step);
}

function appendCompletionLog(existing, dateISO, note) {
  const line = note ? `${dateISO} — ${note}` : dateISO;
  const prev = String(existing || "").trim();
  const lines = prev
    ? prev
        .split("\n")
        .map((l) => l.trim())
        .filter(Boolean)
    : [];
  if (lines.some((l) => l.startsWith(dateISO))) {
    return prev; // already logged this day
  }
  lines.push(line);
  // Keep last ~40 entries
  return lines.slice(-40).join("\n");
}

function nameMatchesPerson(haystack, displayName, handle) {
  const h = String(haystack || "").toLowerCase();
  if (!h.trim()) return false;
  const name = String(displayName || "").toLowerCase().trim();
  const hand = String(handle || "")
    .toLowerCase()
    .replace(/^@/, "")
    .trim();
  if (name && name.length >= 2 && h.includes(name)) return true;
  if (hand && hand.length >= 2 && h.includes(hand)) return true;
  // First name match
  if (name) {
    const first = name.split(/\s+/)[0];
    if (first.length >= 3 && h.includes(first)) return true;
  }
  return false;
}

module.exports = {
  addDaysISO,
  addMonthsISO,
  daysBetween,
  nextDueAfterCompletion,
  isDutyDueOn,
  staggeredWeeklyDueDate,
  appendCompletionLog,
  nameMatchesPerson,
};
