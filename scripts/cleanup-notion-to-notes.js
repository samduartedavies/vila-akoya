/**
 * Align Notion with the community meeting notes:
 * keep only sections listed there; archive the rest.
 *
 * Keep:
 *   Calendars — Internal Events, External Events, Reminders, Bookings, Meals
 *   Work — Tasks, Jobs (+ Recurring Duties under Jobs), Projects
 *   Partnerships
 *   Community — Members
 *   Documentation hub
 *
 * Archive (not in notes):
 *   Maintenance, Guest Issues, Stewards, Decisions
 *
 * Open Maintenance / Guest Issues rows are copied into Tasks first.
 *
 *   node scripts/cleanup-notion-to-notes.js
 */

"use strict";

const notion = require("./lib/notion");

const ARCHIVE_DBS = [
  "Maintenance",
  "Guest Issues",
  "Stewards",
  "Decisions",
];

const BRIEF_TITLE = "Community structure (v2) — for feedback";

function briefBlocks() {
  return [
    {
      object: "block",
      type: "callout",
      callout: {
        icon: { type: "emoji", emoji: "📋" },
        rich_text: notion.richText(
          "Matches the community meeting notes. Sections not listed there were removed."
        ),
      },
    },
    {
      object: "block",
      type: "heading_2",
      heading_2: { rich_text: notion.richText("How Notion is organised") },
    },
    {
      object: "block",
      type: "paragraph",
      paragraph: {
        rich_text: notion.richText("Everything sits under Vila Akoya Operations:"),
      },
    },
    {
      object: "block",
      type: "heading_3",
      heading_3: { rich_text: notion.richText("Calendars") },
    },
    {
      object: "block",
      type: "bulleted_list_item",
      bulleted_list_item: {
        rich_text: notion.richText(
          "Internal Events — workshops / happenings on site. Each event is a page with documentation, tasks (assignee + status), and people in charge. May recur weekly/monthly/daily."
        ),
      },
    },
    {
      object: "block",
      type: "bulleted_list_item",
      bulleted_list_item: {
        rich_text: notion.richText(
          "External Events — markets, gatherings, group trips (with recurrence where needed)."
        ),
      },
    },
    {
      object: "block",
      type: "bulleted_list_item",
      bulleted_list_item: {
        rich_text: notion.richText(
          "Reminders and deadlines — e.g. organic veggies order Fridays before 3pm."
        ),
      },
    },
    {
      object: "block",
      type: "bulleted_list_item",
      bulleted_list_item: {
        rich_text: notion.richText(
          "Campsite Bookings — who checks in / out / is on site each day."
        ),
      },
    },
    {
      object: "block",
      type: "bulleted_list_item",
      bulleted_list_item: {
        rich_text: notion.richText(
          "Campsite Meals — breakfast & dinner cook counts."
        ),
      },
    },
    {
      object: "block",
      type: "heading_3",
      heading_3: { rich_text: notion.richText("Work") },
    },
    {
      object: "block",
      type: "bulleted_list_item",
      bulleted_list_item: {
        rich_text: notion.richText(
          "Tasks — inbox for chat / daily life. Columns: Name, Type (villager/management), Assignee, Created by, Status, Priority (Low/Average/High), Notes."
        ),
      },
    },
    {
      object: "block",
      type: "bulleted_list_item",
      bulleted_list_item: {
        rich_text: notion.richText(
          "Jobs — roles like Cleaner; within each job, Recurring Duties (fridge monthly, grass weekly…)."
        ),
      },
    },
    {
      object: "block",
      type: "bulleted_list_item",
      bulleted_list_item: {
        rich_text: notion.richText(
          "Projects — bigger shared builds / ideas; assign people, subtasks, detailed notes."
        ),
      },
    },
    {
      object: "block",
      type: "heading_3",
      heading_3: { rich_text: notion.richText("Partnerships") },
    },
    {
      object: "block",
      type: "bulleted_list_item",
      bulleted_list_item: {
        rich_text: notion.richText(
          "Partner name, affiliate links, links to documentation."
        ),
      },
    },
    {
      object: "block",
      type: "heading_3",
      heading_3: { rich_text: notion.richText("Community") },
    },
    {
      object: "block",
      type: "bulleted_list_item",
      bulleted_list_item: {
        rich_text: notion.richText(
          "Members — who’s here + volunteers; Telegram handles, skills, profiles, bios. VAVA can send a questionnaire."
        ),
      },
    },
    {
      object: "block",
      type: "heading_3",
      heading_3: { rich_text: notion.richText("Documentation hub") },
    },
    {
      object: "block",
      type: "bulleted_list_item",
      bulleted_list_item: {
        rich_text: notion.richText(
          "Full write-ups (e.g. Wi‑Fi) as pages, plus a Documentation register. Bot answers “how does X work?” and partnership discount questions from here."
        ),
      },
    },
  ];
}

async function migrateOpenRowsToTasks(dbName, typeDefault) {
  let pages;
  try {
    ({ pages } = await notion.queryDatabase(dbName));
  } catch (err) {
    console.log(`  Skip migrate ${dbName}: ${err.message}`);
    return 0;
  }

  let moved = 0;
  for (const page of pages) {
    const stage = (notion.pagePropText(page, "Stage") || "").toLowerCase();
    if (notion.isDoneStage(stage)) continue;
    if (page.archived) continue;

    const title = notion.pageTitle(page);
    const notes = [
      notion.pagePropText(page, "Notes") || "",
      `[migrated from ${dbName}]`,
    ]
      .filter(Boolean)
      .join("\n");

    await notion.createPage({
      databaseName: notion.DB.TASKS,
      title: title.slice(0, 200) || `Item from ${dbName}`,
      properties: {
        Status: "Not started",
        Type: typeDefault,
        Notes: notes,
        "Created by": "cleanup-migrate",
      },
    });
    moved++;
  }
  console.log(`  Migrated ${moved} open rows from ${dbName} → Tasks`);
  return moved;
}

async function archiveDatabase(dbName) {
  const catalog = notion.loadCatalog();
  const id = catalog.databases?.[dbName];
  if (!id) {
    console.log(`  ${dbName}: not in catalog`);
    return;
  }
  try {
    // Databases must be archived via /databases, not /pages
    await notion.notion("PATCH", `/databases/${id}`, { archived: true });
    console.log(`  Archived ${dbName}`);
  } catch (err) {
    console.warn(`  Could not archive ${dbName}: ${err.message}`);
    return;
  }
  delete catalog.databases[dbName];
  notion.saveCatalog(catalog);
}

async function updateWorkCallout() {
  const workId = notion.loadCatalog().pages?.Work;
  if (!workId) return;
  const blocks = await notion.getBlockChildren(workId);
  const callout = blocks.find((b) => b.type === "callout");
  if (!callout) return;
  await notion.notion("PATCH", `/blocks/${callout.id}`, {
    callout: {
      rich_text: notion.richText(
        "Tasks (villager + management), Jobs with Recurring Duties, and Projects. Maintenance / guest issues live in Tasks."
      ),
    },
  });
  console.log("  Updated Work callout");
}

async function updateCommunityCallout() {
  const id = notion.loadCatalog().pages?.Community;
  if (!id) return;
  const blocks = await notion.getBlockChildren(id);
  const callout = blocks.find((b) => b.type === "callout");
  if (!callout) return;
  await notion.notion("PATCH", `/blocks/${callout.id}`, {
    callout: {
      rich_text: notion.richText(
        "Members — who’s here, Telegram handles, skills, profiles, bios."
      ),
    },
  });
  console.log("  Updated Community callout");
}

async function rewriteBrief() {
  const pageId = notion.loadCatalog().pages?.[BRIEF_TITLE];
  if (!pageId) {
    console.log("  Brief page not in catalog — skip");
    return;
  }
  const blocks = await notion.getBlockChildren(pageId);
  for (const b of blocks) {
    try {
      await notion.notion("DELETE", `/blocks/${b.id}`);
    } catch (_) {
      /* ignore */
    }
  }
  await notion.appendBlocks(pageId, briefBlocks());
  console.log(`  Rewrote brief: ${BRIEF_TITLE}`);
}

async function main() {
  notion.assertKey();
  console.log("Cleaning Notion to match meeting notes…\n");

  console.log("=== Migrate open rows into Tasks ===");
  await migrateOpenRowsToTasks("Maintenance", "Villager");
  await migrateOpenRowsToTasks("Guest Issues", "Villager");

  console.log("\n=== Archive DBs not in the notes ===");
  for (const name of ARCHIVE_DBS) {
    await archiveDatabase(name);
  }

  console.log("\n=== Update section copy ===");
  await updateWorkCallout();
  await updateCommunityCallout();
  await rewriteBrief();

  console.log("\nDONE. Kept structure:");
  console.log("  Calendars · Work (Tasks, Jobs+Recurring Duties, Projects)");
  console.log("  Partnerships · Community (Members) · Documentation hub");
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
