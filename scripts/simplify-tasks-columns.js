/**
 * Slim Tasks to meeting-note columns only:
 *   Name, Type (Villager/Management), Assignee, Created by, Status, Notes
 * (+ keep event relations so event hubs can still attach tasks)
 *
 * Status → Not started | In progress | Done
 *
 *   node scripts/simplify-tasks-columns.js
 */

"use strict";

const notion = require("./lib/notion");

const KEEP = new Set([
  "Name",
  "Type",
  "Assignee",
  "Created by",
  "Status",
  "Priority",
  "Notes",
  // Event hub links (not in the “column clutter” list, but required for events)
  "Related to Internal Events (Tasks)",
  "Related to External Events (Tasks)",
  "Project",
]);

const STATUS_MAP = {
  Open: "Not started",
  open: "Not started",
  Blocked: "Not started",
  blocked: "Not started",
  "In progress": "In progress",
  "in progress": "In progress",
  Done: "Done",
  done: "Done",
};

async function remapStatuses(dbId) {
  const { pages } = await notion.queryDatabase(notion.DB.TASKS);
  let updated = 0;
  for (const page of pages) {
    const current = notion.pagePropText(page, "Status");
    const next = STATUS_MAP[current] || (current ? null : "Not started");
    if (!next || next === current) continue;
    await notion.updatePage(page.id, {
      properties: { Status: { select: { name: next } } },
    });
    updated++;
  }
  console.log(`  Remapped Status on ${updated} page(s)`);
}

async function setStatusOptions(dbId) {
  await notion.notion("PATCH", `/databases/${dbId}`, {
    properties: {
      Status: {
        select: {
          options: [
            { name: "Not started", color: "gray" },
            { name: "In progress", color: "blue" },
            { name: "Done", color: "green" },
          ],
        },
      },
      Type: {
        select: {
          options: [
            { name: "Villager", color: "green" },
            { name: "Management", color: "orange" },
          ],
        },
      },
    },
  });
  console.log("  Status options → Not started | In progress | Done");
}

async function removeExtraColumns(dbId, props) {
  const patch = {};
  for (const name of Object.keys(props)) {
    if (KEEP.has(name)) continue;
    if (props[name].type === "title") continue;
    patch[name] = null;
  }
  const keys = Object.keys(patch);
  if (!keys.length) {
    console.log("  No extra columns to remove");
    return;
  }
  // Notion allows batching; do in chunks of ~10
  for (let i = 0; i < keys.length; i += 8) {
    const chunk = {};
    for (const k of keys.slice(i, i + 8)) chunk[k] = null;
    await notion.notion("PATCH", `/databases/${dbId}`, { properties: chunk });
  }
  console.log(`  Removed columns: ${keys.join(", ")}`);
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
        "Tasks columns: Name, Type (Villager / Management), Assignee, Created by, Status (Not started / In progress / Done), Priority (Low / Average / High), Notes. Use By type / Villager / Management views."
      ),
    },
  });
  console.log("  Work callout updated");
}

async function main() {
  notion.assertKey();
  console.log("Simplifying Tasks columns…\n");
  const db = await notion.resolveDatabase(notion.DB.TASKS);

  // Remap while old Status options still exist
  await remapStatuses(db.id);

  try {
    await setStatusOptions(db.id);
  } catch (err) {
    // Color updates on existing options sometimes fail — retry names only
    console.warn(`  Status option patch (retry without colors): ${err.message}`);
    await notion.notion("PATCH", `/databases/${db.id}`, {
      properties: {
        Status: {
          select: {
            options: [
              { name: "Not started" },
              { name: "In progress" },
              { name: "Done" },
            ],
          },
        },
      },
    });
  }

  notion.clearDatabaseCache(notion.DB.TASKS);
  const fresh = await notion.resolveDatabase(notion.DB.TASKS);
  await removeExtraColumns(fresh.id, fresh.properties);
  notion.clearDatabaseCache(notion.DB.TASKS);

  await updateWorkCallout();

  const final = await notion.resolveDatabase(notion.DB.TASKS);
  console.log("\nRemaining properties:");
  console.log(" ", Object.keys(final.properties).sort().join(", "));
  console.log("\nDONE.", final.url);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
