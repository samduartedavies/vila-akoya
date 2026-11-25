/**
 * Open-Meteo forecast for the morning recap (no API key).
 */

"use strict";

const DEFAULT_LAT = Number(process.env.WEATHER_LATITUDE || 37.6539);
const DEFAULT_LON = Number(process.env.WEATHER_LONGITUDE || -8.22569);
const DEFAULT_TZ = process.env.RECAP_TZ || "Europe/Lisbon";

const WMO_SHORT = {
  0: "Clear",
  1: "Mostly clear",
  2: "Partly cloudy",
  3: "Overcast",
  45: "Fog",
  48: "Rime fog",
  51: "Light drizzle",
  53: "Drizzle",
  55: "Heavy drizzle",
  61: "Light rain",
  63: "Rain",
  65: "Heavy rain",
  71: "Light snow",
  73: "Snow",
  75: "Heavy snow",
  80: "Rain showers",
  81: "Rain showers",
  82: "Heavy showers",
  95: "Thunderstorms",
  96: "Thunderstorms with hail",
  99: "Thunderstorms with hail",
};

function wmoLabel(code) {
  const n = Number(code);
  if (Number.isFinite(n) && WMO_SHORT[n]) return WMO_SHORT[n];
  if (n >= 56 && n <= 67) return "Rain";
  if (n >= 68 && n <= 77) return "Snow or sleet";
  if (n >= 85 && n <= 86) return "Snow showers";
  return "Mixed conditions";
}

function roundTemp(c) {
  const n = Number(c);
  if (!Number.isFinite(n)) return null;
  return Math.round(n);
}

/**
 * @param {string} dateISO YYYY-MM-DD in local recap timezone
 * @param {{ lat?: number, lon?: number, tz?: string }} [opts]
 * @returns {Promise<string|null>} Plain English one-liner for the recap
 */
async function forecastLineForDate(dateISO, opts = {}) {
  const lat = Number(opts.lat ?? DEFAULT_LAT);
  const lon = Number(opts.lon ?? DEFAULT_LON);
  const tz = opts.tz || DEFAULT_TZ;
  if (!dateISO || !/^\d{4}-\d{2}-\d{2}$/.test(dateISO)) return null;

  const url =
    `https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lon}` +
    `&timezone=${encodeURIComponent(tz)}` +
    `&current=temperature_2m,weathercode,windspeed_10m` +
    `&daily=weathercode,temperature_2m_max,temperature_2m_min,precipitation_probability_max,windspeed_10m_max` +
    `&forecast_days=16`;

  let json;
  try {
    const res = await fetch(url, { signal: AbortSignal.timeout(12000) });
    if (!res.ok) return null;
    json = await res.json();
  } catch {
    return null;
  }

  const times = json?.daily?.time || [];
  const idx = times.indexOf(dateISO);
  if (idx < 0) return null;

  const maxT = roundTemp(json.daily.temperature_2m_max?.[idx]);
  const minT = roundTemp(json.daily.temperature_2m_min?.[idx]);
  const rainPct = json.daily.precipitation_probability_max?.[idx];
  const wind = roundTemp(json.daily.windspeed_10m_max?.[idx]);
  const code = json.daily.weathercode?.[idx];
  const summary = wmoLabel(code);

  const parts = [summary];
  if (maxT != null && minT != null) {
    parts.push(`high ${maxT}°C, low ${minT}°C`);
  } else if (maxT != null) {
    parts.push(`around ${maxT}°C`);
  }

  if (Number.isFinite(rainPct) && rainPct >= 25) {
    parts.push(`${Math.round(rainPct)}% chance of rain`);
  }
  if (wind != null && wind >= 28) {
    parts.push(`wind up to ${wind} km/h`);
  }

  const today = times[0];
  if (dateISO === today && json.current) {
    const nowT = roundTemp(json.current.temperature_2m);
    if (nowT != null) {
      parts.push(`now ${nowT}°C`);
    }
  }

  return parts.join(", ");
}

module.exports = {
  forecastLineForDate,
  wmoLabel,
};
