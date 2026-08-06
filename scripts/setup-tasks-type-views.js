/**
 * Split Tasks by Villager vs Management in Notion:
 * - Clarify Type options
 * - Board view grouped by Type
 * - Filtered table views for each type
 *
 *   node scripts/setup-tasks-type-views.js
 */

"use strict";

const notion = require("./lib/notion");

async function clarifyTypeProperty(dbId) {
  // Descriptions help in the property UI; colors often can't be patched on existing options.
  try {
    await notion.notion("PATCH", `/databases/${dbId}`, {
      properties: {
        Type: {
          select: {
            options: [
              {
                name: "Villager",
                description:
                  "Village work anyone can pitch in on (chores, fixes, help around site).",
              },
              {
                name: "Management",
                description:
                  "Needs management to hire / arrange professionals or external work.",
              },
            ],
          },
        },
      },
    });
    notion.clearDatabaseCache(notion.DB.TASKS);
    console.log("  Type option descriptions updated");
  } catch (err) {
    console.warn(`  Type descriptions skip: ${err.message}`);
  }
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
        "Tasks split by Type: Villager (anyone can do) vs Management (hire/arrange professionals). Use the “By type” board or the Villager / Management table tabs."
      ),
    },
  });
  console.log("  Work callout updated");
}

async function main() {
  notion.assertKey();
  console.log("Setting up Tasks villager / management split…\n");

  const db = await notion.resolveDatabase(notion.DB.TASKS);
  await clarifyTypeProperty(db.id);

  const openFilter = {
    property: "Status",
    select: { does_not_equal: "Done" },
  };

  const byType = await notion.ensureBoardView(db.id, {
    name: "By type",
    groupProperty: "Type",
    filter: openFilter,
  });
  console.log(
    `  Board “By type”: ${byType.wasCreated ? "created" : "exists"} ${byType.url || ""}`
  );

  const villager = await notion.ensureTableView(db.id, {
    name: "Villager tasks",
    filter: {
      and: [
        { property: "Type", select: { equals: "Villager" } },
        openFilter,
      ],
    },
    sorts: [{ property: "Priority", direction: "descending" }],
  });
  console.log(
    `  Table “Villager tasks”: ${villager.wasCreated ? "created" : "exists"}`
  );

  const management = await notion.ensureTableView(db.id, {
    name: "Management tasks",
    filter: {
      and: [
        { property: "Type", select: { equals: "Management" } },
        openFilter,
      ],
    },
    sorts: [{ property: "Priority", direction: "descending" }],
  });
  console.log(
    `  Table “Management tasks”: ${management.wasCreated ? "created" : "exists"}`
  );

  const recent = await notion.ensureTableView(db.id, {
    name: "Recently added",
    filter: openFilter,
    sorts: [{ timestamp: "created_time", direction: "descending" }],
  });
  console.log(
    `  Table “Recently added”: ${recent.wasCreated ? "created" : "exists"} ${recent.url || ""}`
  );

  const needsAssign = await notion.ensureTableView(db.id, {
    name: "Needs assign",
    filter: {
      and: [
        openFilter,
        { property: "Assignee", rich_text: { is_empty: true } },
      ],
    },
    sorts: [{ timestamp: "created_time", direction: "descending" }],
  });
  console.log(
    `  Table “Needs assign”: ${needsAssign.wasCreated ? "created" : "exists"} ${needsAssign.url || ""}`
  );

  await updateWorkCallout();

  console.log("\nDONE.");
  console.log(`Open Tasks: ${db.url}`);
  console.log(
    "Use tabs: By type | Villager tasks | Management tasks (set Type on each task)."
  );
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
