/**
 * Expand recurring Internal/External Events into dated calendar occurrences.
 *
 * Notion calendar views only show the Date property — they do not expand a
 * Recurrence text field. This script materialises upcoming dates so every
 * Saturday (etc.) appears on the calendar.
 *
 *   node scripts/expand-notion-recurring-events.js
 *
 * Optional:
 *   RECURRENCE_HORIZON_DAYS=180   (default 180)
 *   RECURRENCE_PAST_DAYS=7       (default 7 — keep recent past visible)
 */

"use strict";

const notion = require("./lib/notion");
const {
  parseRecurrence,
  expandBetween,
  describeRecurrence,
} = require("./lib/event-recurrence");

const HORIZON = Number(process.env.RECURRENCE_HORIZON_DAYS || 180);
const PAST = Number(process.env.RECURRENCE_PAST_DAYS || 7);
const DBS = [notion.DB.INTERNAL_EVENTS, notion.DB.EXTERNAL_EVENTS];
const TZ = process.env.RECAP_TZ || "Europe/Lisbon";

function todayISO() {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: TZ,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}

function addDaysISO(iso, days) {
  const [y, m, d] = iso.split("-").map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d + days, 12));
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: TZ,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(dt);
}

/** Pull HH:MM from a Notion date start if present. */
function timeParts(dateObj) {
  const start = dateObj?.start || "";
  const end = dateObj?.end || "";
  const sm = String(start).match(/T(\d{2}):(\d{2})/);
  const em = String(end).match(/T(\d{2}):(\d{2})/);
  return {
    startHM: sm ? `${sm[1]}:${sm[2]}` : null,
    endHM: em ? `${em[1]}:${em[2]}` : null,
  };
}

function datePayload(ymd, startHM, endHM) {
  if (startHM) {
    const start = `${ymd}T${startHM}:00`;
    const end = endHM ? `${ymd}T${endHM}:00` : undefined;
    return {
      start,
      end,
      time_zone: TZ,
    };
  }
  return { start: ymd, end: endHM ? ymd : undefined };
}

function occurrenceId(seriesTitle, recurrence, ymd) {
  return `${seriesTitle.trim()}|${recurrence}|${ymd}`;
}

function seriesTitleFromPage(page) {
  const name = notion.pageTitle(page);
  // "Weekly potluck — 2026-08-08" → "Weekly potluck"
  return name.replace(/\s+[—–-]\s+\d{4}-\d{2}-\d{2}\s*$/, "").trim() || name;
}

async function ensureOccurrenceProp(dbName) {
  const db = await notion.resolveDatabase(dbName);
  if (db.properties?.["Occurrence ID"]) return;
  await notion.notion("PATCH", `/databases/${db.id}`, {
    properties: {
      "Occurrence ID": { rich_text: {} },
      "Is occurrence?": { checkbox: {} },
    },
  });
  notion.clearDatabaseCache(dbName);
  await notion.primeDatabase(dbName, db.id);
}

async function expandDatabase(dbName, fromISO, toISO) {
  await ensureOccurrenceProp(dbName);
  const db = await notion.resolveDatabase(dbName);
  const { pages } = await notion.queryDatabase(dbName);

  // Index existing occurrences by Occurrence ID
  const byOccId = new Map();
  for (const page of pages) {
    const oid = notion.pagePropText(page, "Occurrence ID");
    if (oid) byOccId.set(oid, page);
  }

  // Series masters: have Recurrence, not marked as occurrence (or no Occurrence ID)
  const series = [];
  for (const page of pages) {
    const stage = notion.pagePropText(page, "Stage");
    const status = notion.pagePropText(page, "Status").toLowerCase();
    if (notion.isDoneStage(stage) || status === "cancelled" || status === "done") {
      continue;
    }
    const recurrence = notion.pagePropText(page, "Recurrence");
    const rule = parseRecurrence(recurrence);
    if (!rule) continue;
    const isOcc =
      notion.pagePropText(page, "Is occurrence?") === "true" ||
      Boolean(notion.pagePropText(page, "Occurrence ID"));
    // Prefer pages that are series templates: recurrence set, and either
    // not an occurrence, OR treat first dated page as template for times.
    if (isOcc) continue;
    series.push({ page, recurrence: recurrence.trim(), rule });
  }

  // If a series was only stored as a single dated page with recurrence
  // (Is occurrence? false, has Date), still expand from it.
  if (!series.length) {
    for (const page of pages) {
      const stage = notion.pagePropText(page, "Stage");
      const status = notion.pagePropText(page, "Status").toLowerCase();
      if (notion.isDoneStage(stage) || status === "cancelled" || status === "done") {
        continue;
      }
      const recurrence = notion.pagePropText(page, "Recurrence");
      const rule = parseRecurrence(recurrence);
      if (!rule) continue;
      if (notion.pagePropText(page, "Is occurrence?") === "true") continue;
      series.push({ page, recurrence: recurrence.trim(), rule });
    }
  }

  let created = 0;
  let updated = 0;
  let skipped = 0;

  for (const { page, recurrence, rule } of series) {
    const title = seriesTitleFromPage(page);
    const dateRaw = page.properties?.Date?.date || null;
    const { startHM, endHM } = timeParts(dateRaw);
    const dates = expandBetween(rule, fromISO, toISO, TZ);
    const label = describeRecurrence(rule);

    console.log(`  ${title} [${recurrence}] → ${dates.length} date(s) (${label})`);

    for (const ymd of dates) {
      const oid = occurrenceId(title, recurrence, ymd);
      const occTitle = `${title} — ${ymd}`;
      const props = {
        Stage: "Upcoming",
        Status: notion.pagePropText(page, "Status") || "Confirmed",
        Date: datePayload(ymd, startHM, endHM),
        Recurrence: recurrence,
        Location: notion.pagePropText(page, "Location") || undefined,
        "People in charge":
          notion.pagePropText(page, "People in charge") || undefined,
        "Announce on Telegram?":
          notion.pagePropText(page, "Announce on Telegram?") === "true",
        "Occurrence ID": oid,
        "Is occurrence?": true,
        Notes:
          `Occurrence of “${title}” (${label}).\n` +
          (notion.pagePropText(page, "Notes")
            ? `\nFrom series notes:\n${notion.pagePropText(page, "Notes")}`
            : ""),
      };

      const existing = byOccId.get(oid);
      if (existing) {
        const est = notion.pagePropText(existing, "Stage");
        const es = notion.pagePropText(existing, "Status").toLowerCase();
        if (notion.isDoneStage(est) || es === "cancelled" || es === "done") {
          skipped++;
          continue;
        }
        await notion.updatePageProps(dbName, existing.id, props);
        updated++;
        continue;
      }

      // Also match by title+date if Occurrence ID missing on older rows
      const soft = pages.find(
        (p) =>
          notion.pageTitle(p) === occTitle ||
          (seriesTitleFromPage(p) === title &&
            notion.pageDateISO(p, "Date") === ymd &&
            notion.pagePropText(p, "Recurrence") === recurrence)
      );
      if (soft && soft.id === page.id && dates.length > 1) {
        // Don't convert the only series master into a single occurrence if
        // we're about to create many — keep master, create occ pages.
      }

      if (soft && soft.id !== page.id) {
        await notion.updatePageProps(dbName, soft.id, {
          ...props,
          Name: occTitle,
        });
        await notion.updatePage(soft.id, {
          properties: {
            ...notion.buildProps(db, props),
            [notion.titlePropName(db)]: notion.titleValue(occTitle),
            ...(db.properties?.Series
              ? { Series: { relation: [{ id: page.id }] } }
              : {}),
          },
        });
        byOccId.set(oid, soft);
        updated++;
        continue;
      }

      // If this ymd is exactly the master's date and it's the only page,
      // convert master into an occurrence AND keep recurrence on a series stub…
      // Simpler: always create occurrence pages; clear Date on master so it
      // doesn't double-appear — done after loop for masters.

      const createdPage = await notion.createPage({
        databaseName: dbName,
        title: occTitle,
        properties: props,
      });
      // Point occurrence → series hub (docs + shared tasks live on the hub)
      if (db.properties?.Series) {
        try {
          await notion.updatePage(createdPage.id, {
            properties: {
              Series: { relation: [{ id: page.id }] },
            },
          });
        } catch (_) {
          /* schema may lag */
        }
      }
      created++;
    }

    // Keep series master as template: clear Date so calendar only shows occurrences
    try {
      const db = await notion.resolveDatabase(dbName);
      await notion.updatePage(page.id, {
        properties: {
          ...notion.buildProps(db, {
            Stage: "Recurring",
            Recurrence: recurrence,
            Notes:
              (notion.pagePropText(page, "Notes") || "") +
              (notion.pagePropText(page, "Notes")?.includes("[series template]")
                ? ""
                : "\n\n[series template] Calendar dates are generated as separate occurrence pages. Docs & tasks live on this hub page."),
          }),
          Date: { date: null },
        },
      });
    } catch (err) {
      console.warn(`    Could not clear master date: ${err.message}`);
    }
    skipped++;
  }

  return { created, updated, series: series.length };
}

async function expandRecurringEvents(opts = {}) {
  const horizon = opts.horizonDays ?? HORIZON;
  const past = opts.pastDays ?? PAST;
  const databases = opts.databases || DBS;
  const log = opts.log || (() => {});

  const today = todayISO();
  const from = addDaysISO(today, -past);
  const to = addDaysISO(today, horizon);
  log(`Expanding recurring events ${from} → ${to} (${TZ})`);

  let totals = { created: 0, updated: 0, series: 0 };
  for (const dbName of databases) {
    log(`=== ${dbName} ===`);
    try {
      const r = await expandDatabase(dbName, from, to);
      totals.created += r.created;
      totals.updated += r.updated;
      totals.series += r.series;
    } catch (err) {
      log(`  Skip ${dbName}: ${err.message}`);
    }
  }
  return totals;
}

async function main() {
  notion.assertKey();
  const totals = await expandRecurringEvents({ log: console.log });
  console.log(
    `\nDONE — ${totals.series} series, ${totals.created} created, ${totals.updated} updated`
  );
}

module.exports = { expandRecurringEvents, expandDatabase };

if (require.main === module) {
  main().catch((err) => {
    console.error(err);
    process.exit(1);
  });
}