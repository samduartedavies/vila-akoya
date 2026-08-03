"use strict";

/**
 * Fetch campsite ops snapshots from WordPress REST.
 */

const DEFAULT_BASE =
  process.env.VILA_OPS_API_BASE ||
  process.env.WP_SITE_URL ||
  "http://localhost:8888";

// Local wp-env default matches `wp option update vila_cb_ops_api_token …`
const LOCAL_OPS_TOKEN = "vila-local-ops-dev-token";

function apiBase() {
  return String(DEFAULT_BASE).replace(/\/$/, "");
}

function isLocalBase(base = apiBase()) {
  return /localhost|127\.0\.0\.1/.test(base);
}

const OPS_TOKEN =
  process.env.VILA_OPS_API_TOKEN ||
  (isLocalBase(String(DEFAULT_BASE).replace(/\/$/, "")) ? LOCAL_OPS_TOKEN : "");

function authHeaders() {
  const headers = { Accept: "application/json" };
  if (OPS_TOKEN) {
    headers.Authorization = `Bearer ${OPS_TOKEN}`;
  }
  return headers;
}

async function fetchJson(path) {
  const url = `${apiBase()}${path}`;
  const res = await fetch(url, { headers: authHeaders() });
  const text = await res.text();
  let json;
  try {
    json = JSON.parse(text);
  } catch {
    throw new Error(`Non-JSON from ${url} (HTTP ${res.status}): ${text.slice(0, 160)}`);
  }
  if (!res.ok) {
    const msg = json?.message || json?.code || res.statusText;
    const hint =
      res.status === 401
        ? " — set VILA_OPS_API_TOKEN to match WordPress (local: vila-local-ops-dev-token)"
        : "";
    throw new Error(`Ops API ${path} failed (HTTP ${res.status}): ${msg}${hint}`);
  }
  return json;
}

function addDaysISO(ymd, days) {
  const d = new Date(ymd + "T12:00:00");
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

function todayISO(tz = "Europe/Lisbon") {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: tz,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}

/**
 * @param {string} [dateISO] Y-m-d
 */
async function fetchOpsDay(dateISO) {
  const date = dateISO || todayISO();
  return fetchJson(`/wp-json/vila-campsite-booking/v1/ops/day?date=${encodeURIComponent(date)}`);
}

/**
 * @param {string} from Y-m-d
 * @param {string} to Y-m-d
 */
async function fetchOpsActivity(from, to) {
  return fetchJson(
    `/wp-json/vila-campsite-booking/v1/ops/activity?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`
  );
}

/**
 * @param {string} from Y-m-d
 * @param {string} to Y-m-d
 */
async function fetchOpsBookings(from, to) {
  return fetchJson(
    `/wp-json/vila-campsite-booking/v1/ops/bookings?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`
  );
}

/**
 * @param {string} from Y-m-d
 * @param {string} to Y-m-d
 */
async function fetchOpsMeals(from, to) {
  return fetchJson(
    `/wp-json/vila-campsite-booking/v1/ops/meals?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`
  );
}

function formatBookingRecapLine(booking) {
  const plot = booking.plot_label ? ` — ${booking.plot_label}` : "";
  const party = booking.party_line ? ` — ${booking.party_line}` : "";
  const unit = booking.unit_type ? ` — ${booking.unit_type}` : "";
  const who = booking.companions_line || booking.guest_name;
  return `${who}${plot}${party}${unit}`;
}

function formatMealParties(parties, max = 6) {
  if (!parties?.length) return "";
  const lines = parties.slice(0, max).map((p) => {
    const cook = (p.adults || 0) + (p.children || 0);
    const bits = [];
    if (cook) bits.push(`${cook} to cook`);
    if (p.plot) bits.push(p.plot);
    return `${p.name}${bits.length ? ` (${bits.join(" · ")})` : ""}`;
  });
  if (parties.length > max) {
    lines.push(`+${parties.length - max} more`);
  }
  return lines.join("; ");
}

module.exports = {
  apiBase,
  fetchOpsDay,
  fetchOpsActivity,
  fetchOpsBookings,
  fetchOpsMeals,
  formatBookingRecapLine,
  formatMealParties,
  addDaysISO,
  todayISO,
};
