/**
 * Turn Internal/External Events into real event hubs:
 * - Relation to Tasks (assign people, status)
 * - Series ↔ Occurrences self-relation
 * - Page body sections for documentation + how to use tasks
 *
 * Also upgrades existing series (e.g. Weekly potluck) and links occurrences.
 *
 *   node scripts/enrich-notion-event-pages.js
 */

"use strict";

const notion = require("./lib/notion");
const { eventBodyBlocks, pageHasMeaningfulBody } = require("./lib/event-hub");

const EVENT_DBS = [notion.DB.INTERNAL_EVENTS, notion.DB.EXTERNAL_EVENTS];

async function ensureEventSchema(eventDbName) {
  const eventDb = await notion.resolveDatabase(eventDbName);
  const tasksDb = await notion.resolveDatabase(notion.DB.TASKS);
  const props = eventDb.properties || {};
  const patch = {};

  if (!props.Tasks) {
    const dualName =
      eventDbName === notion.DB.INTERNAL_EVENTS
        ? "Internal event"
        : "External event";
    patch.Tasks = {
      relation: {
        database_id: tasksDb.id,
        type: "dual_property",
        dual_property: { name: dualName },
      },
    };
  }

  // Self-relation: occurrence → series hub
  if (!props.Series) {
    patch.Series = {
      relation: {
        database_id: eventDb.id,
        single_property: {},
      },
    };
  }

  if (Object.keys(patch).length) {
    await notion.notion("PATCH", `/databases/${eventDb.id}`, {
      properties: patch,
    });
    notion.clearDatabaseCache(eventDbName);
    await notion.primeDatabase(eventDbName, eventDb.id);
    notion.clearDatabaseCache(notion.DB.TASKS);
    console.log(`  ${eventDbName}: added ${Object.keys(patch).join(", ")}`);
  } else {
    console.log(`  ${eventDbName}: schema ok`);
  }
}

function seriesTitleFromPage(page) {
  const name = notion.pageTitle(page);
  return name.replace(/\s+[—–-]\s+\d{4}-\d{2}-\d{2}\s*$/, "").trim() || name;
}

async function enrichDatabase(dbName) {
  console.log(`\n=== ${dbName} ===`);
  await ensureEventSchema(dbName);

  const { pages } = await notion.queryDatabase(dbName);
  const seriesPages = [];
  const occurrences = [];

  for (const page of pages) {
    const isOcc = notion.pagePropText(page, "Is occurrence?") === "true";
    if (isOcc) occurrences.push(page);
    else seriesPages.push(page);
  }

  const seriesByTitle = new Map();
  for (const page of seriesPages) {
    const title = seriesTitleFromPage(page);
    const existing = seriesByTitle.get(title);
    if (!existing) {
      seriesByTitle.set(title, page);
      continue;
    }
    const prefer =
      notion.pagePropText(page, "Stage") === "Recurring" ||
      !notion.pagePropText(page, "Occurrence ID");
    if (prefer) seriesByTitle.set(title, page);
  }

  for (const [title, page] of seriesByTitle) {
    const blocks = await notion.getBlockChildren(page.id);
    if (!pageHasMeaningfulBody(blocks)) {
      await notion.appendBlocks(
        page.id,
        eventBodyBlocks({
          title,
          isSeries: Boolean(notion.pagePropText(page, "Recurrence")),
          recurrenceLabel: notion.pagePropText(page, "Recurrence") || null,
        })
      );
      console.log(`  Hub body → ${title}`);
    } else {
      console.log(`  Hub body exists → ${title}`);
    }

    if (notion.pagePropText(page, "Recurrence")) {
      await notion.updatePageProps(dbName, page.id, {
        Stage: "Recurring",
        "Is occurrence?": false,
      });
    }
  }

  // Link occurrences → series only (skip per-occurrence bodies — too many API calls)
  const db = await notion.resolveDatabase(dbName);
  const seriesProp = db.properties?.Series;
  let linked = 0;
  let skipped = 0;

  for (let i = 0; i < occurrences.length; i++) {
    const page = occurrences[i];
    const title = seriesTitleFromPage(page);
    const series = seriesByTitle.get(title);
    if (!series || !seriesProp) {
      skipped++;
      continue;
    }

    const already =
      page.properties?.Series?.relation?.some((r) => r.id === series.id) ||
      false;
    if (already) {
      skipped++;
      continue;
    }

    await notion.updatePage(page.id, {
      properties: {
        Series: { relation: [{ id: series.id }] },
      },
    });
    linked++;
    if (linked % 25 === 0) {
      console.log(`  Linked ${linked}/${occurrences.length} occurrences…`);
    }
  }
  console.log(
    `  Linked ${linked} occurrences → series hubs (${skipped} already linked / no hub)`
  );
}

async function seedPotluckExampleTask() {
  const { pages } = await notion.queryDatabase(notion.DB.INTERNAL_EVENTS);
  const hub = pages.find(
    (p) =>
      notion.pageTitle(p) === "Weekly potluck" &&
      notion.pagePropText(p, "Is occurrence?") !== "true"
  );
  if (!hub) {
    console.log("\nNo Weekly potluck hub found");
    return;
  }

  console.log(`\nWeekly potluck hub: ${hub.url}`);

  const { pages: tasks } = await notion.queryDatabase(notion.DB.TASKS);
  const exists = tasks.some((t) => /potluck/i.test(notion.pageTitle(t)));
  if (exists) {
    console.log("Sample potluck task already exists");
    return;
  }

  const created = await notion.createPage({
    databaseName: notion.DB.TASKS,
    title: "Potluck — set up tables & signs",
    properties: {
      Status: "Not started",
      Type: "Villager",
      Notes:
        "Example task linked to Weekly potluck. Assign someone in Assignee.",
      "Created by": "setup",
    },
  });

  const eventDb = await notion.resolveDatabase(notion.DB.INTERNAL_EVENTS);
  if (eventDb.properties?.Tasks) {
    await notion.updatePage(hub.id, {
      properties: {
        Tasks: { relation: [{ id: created.id }] },
      },
    });
  }
  console.log(`Sample task linked to Weekly potluck: ${created.url}`);
}

async function main() {
  notion.assertKey();
  console.log("Enriching event pages into hubs (docs + tasks)…");
  for (const db of EVENT_DBS) {
    try {
      await enrichDatabase(db);
    } catch (err) {
      console.warn(`Failed ${db}:`, err.message);
    }
  }
  await seedPotluckExampleTask();
  console.log("\nDONE.");
  console.log(
    "Open a series hub (e.g. Weekly potluck — not a dated calendar row)."
  );
  console.log(
    "Use Overview/Documentation in the page body, and the Tasks property to assign work."
  );
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
