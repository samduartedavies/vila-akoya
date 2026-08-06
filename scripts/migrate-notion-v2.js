/**
 * Migrate Vila Akoya Notion ops to the community-agreed v2 structure.
 *
 * - Internal Events / External Events / Reminders (calendars)
 * - Simplified Tasks (+ villager/management); Jobs + Recurring Duties link
 * - Partnerships section
 * - Richer Members fields
 * - Community brief page
 *
 * Safe to re-run. Prefer `npm run notion:cleanup-to-notes` to archive
 * Maintenance / Guest Issues / Stewards / Decisions (not in meeting notes).
 *
 *   NOTION_PARENT_PAGE_ID=... node scripts/migrate-notion-v2.js
 */

"use strict";

const notion = require("./lib/notion");

const PARENT_PAGE_ID = (process.env.NOTION_PARENT_PAGE_ID ||
  notion.loadCatalog().parentPageId ||
  "")
  .replace(/-/g, "")
  .replace(
    /^([0-9a-f]{8})([0-9a-f]{4})([0-9a-f]{4})([0-9a-f]{4})([0-9a-f]{12})$/i,
    "$1-$2-$3-$4-$5"
  );

function selectOpts(names) {
  const colors = [
    "gray", "brown", "orange", "yellow", "green", "blue", "purple", "pink", "red",
  ];
  return names.map((name, i) => ({ name, color: colors[i % colors.length] }));
}
function selectProp(options) {
  return { select: { options: selectOpts(options) } };
}
function rich() { return { rich_text: {} }; }
function check() { return { checkbox: {} }; }
function date() { return { date: {} }; }
function url() { return { url: {} }; }
function title() { return { title: {} }; }
function num() { return { number: { format: "number" } }; }

async function ensureSection(parentId, title, catalog) {
  if (catalog.pages?.[title]) {
    console.log(`  Reusing section: ${title}`);
    return { id: catalog.pages[title], title };
  }
  const found = await notion.searchByTitle(title, "page");
  if (found) {
    catalog.pages[title] = found.id;
    console.log(`  Found section: ${title}`);
    return { id: found.id, title };
  }
  const page = await notion.createChildPage({
    parentPageId: parentId,
    title,
    children: [
      {
        object: "block",
        type: "callout",
        callout: {
          icon: { type: "emoji", emoji: "🌿" },
          rich_text: notion.richText("Vila Akoya ops — community structure v2"),
        },
      },
    ],
  });
  catalog.pages[title] = page.id;
  console.log(`  Created section: ${title}`);
  return page;
}

async function renameDatabase(dbId, newTitle) {
  await notion.notion("PATCH", `/databases/${dbId}`, {
    title: notion.richText(newTitle),
  });
}

async function addProperties(dbId, properties) {
  // Fetch current props; only add missing titles.
  const db = await notion.notion("GET", `/databases/${dbId}`);
  const existing = new Set(Object.keys(db.properties || {}));
  const toAdd = {};
  for (const [name, schema] of Object.entries(properties)) {
    if (!existing.has(name)) toAdd[name] = schema;
  }
  if (!Object.keys(toAdd).length) return { added: [] };
  await notion.notion("PATCH", `/databases/${dbId}`, { properties: toAdd });
  return { added: Object.keys(toAdd) };
}

async function ensureDatabase(parentPageId, key, properties, catalog) {
  if (catalog.databases[key]) {
    console.log(`  Reusing DB: ${key}`);
    await notion.primeDatabase(key, catalog.databases[key]);
    const added = await addProperties(catalog.databases[key], properties);
    if (added.added.length) console.log(`    + props: ${added.added.join(", ")}`);
    return catalog.databases[key];
  }
  const db = await notion.createDatabase({
    parentPageId,
    title: key,
    properties,
  });
  catalog.databases[key] = db.id;
  await notion.primeDatabase(key, db.id);
  console.log(db.wasCreated ? `  Created DB: ${key}` : `  Found DB: ${key}`);
  return db.id;
}

async function writeBrief(parentId, catalog) {
  const title = "Community structure (v2) — for feedback";
  if (catalog.pages[title]) {
    console.log(`  Brief already exists`);
    return catalog.pages[title];
  }
  const page = await notion.createChildPage({
    parentPageId: parentId,
    title,
    children: [
      {
        object: "block",
        type: "heading_1",
        heading_1: { rich_text: notion.richText("Vila Akoya Operations — agreed structure") },
      },
      {
        object: "block",
        type: "paragraph",
        paragraph: {
          rich_text: notion.richText(
            "From the community meeting. VAVA is the Telegram bot. Feedback welcome."
          ),
        },
      },
      {
        object: "block",
        type: "heading_2",
        heading_2: { rich_text: notion.richText("Calendars") },
      },
      {
        object: "block",
        type: "bulleted_list_item",
        bulleted_list_item: {
          rich_text: notion.richText(
            "Internal Events — workshops & happenings on site. Each event is a page (notes, who’s in charge, guest list). Can recur."
          ),
        },
      },
      {
        object: "block",
        type: "bulleted_list_item",
        bulleted_list_item: {
          rich_text: notion.richText(
            "External Events — markets, group trips, off-site gatherings (with recurrence)."
          ),
        },
      },
      {
        object: "block",
        type: "bulleted_list_item",
        bulleted_list_item: {
          rich_text: notion.richText(
            "Reminders & deadlines — e.g. organic veggies order Fridays before 3pm."
          ),
        },
      },
      {
        object: "block",
        type: "bulleted_list_item",
        bulleted_list_item: {
          rich_text: notion.richText("Campsite Bookings — check-ins / outs / on site per day."),
        },
      },
      {
        object: "block",
        type: "bulleted_list_item",
        bulleted_list_item: {
          rich_text: notion.richText("Campsite Meals — breakfast & dinner cook counts."),
        },
      },
      {
        object: "block",
        type: "heading_2",
        heading_2: { rich_text: notion.richText("Work") },
      },
      {
        object: "block",
        type: "bulleted_list_item",
        bulleted_list_item: {
          rich_text: notion.richText(
            "Tasks — simple: name, villager/management, assignee, created by, status, notes, priority. Maintenance lives here too."
          ),
        },
      },
      {
        object: "block",
        type: "bulleted_list_item",
        bulleted_list_item: {
          rich_text: notion.richText(
            "Jobs — roles like Cleaner; each job lists its recurring duties (fridge monthly, grass weekly…)."
          ),
        },
      },
      {
        object: "block",
        type: "bulleted_list_item",
        bulleted_list_item: {
          rich_text: notion.richText(
            "Projects — bigger builds/ideas; people, notes, subtasks."
          ),
        },
      },
      {
        object: "block",
        type: "heading_2",
        heading_2: { rich_text: notion.richText("Partnerships") },
      },
      {
        object: "block",
        type: "paragraph",
        paragraph: {
          rich_text: notion.richText(
            "Partner name, affiliate / discount links, link to documentation. Ask VAVA “how much discount with X?”."
          ),
        },
      },
      {
        object: "block",
        type: "heading_2",
        heading_2: { rich_text: notion.richText("Community") },
      },
      {
        object: "block",
        type: "bulleted_list_item",
        bulleted_list_item: {
          rich_text: notion.richText(
            "Members — Telegram, skills, profile/bio. Ask VAVA for the questionnaire when you join."
          ),
        },
      },
      {
        object: "block",
        type: "heading_2",
        heading_2: { rich_text: notion.richText("Documentation hub") },
      },
      {
        object: "block",
        type: "paragraph",
        paragraph: {
          rich_text: notion.richText(
            "Full write-ups (Wi‑Fi, water…). VAVA answers “how does X work?” from these pages."
          ),
        },
      },
      {
        object: "block",
        type: "heading_2",
        heading_2: { rich_text: notion.richText("How VAVA works") },
      },
      {
        object: "block",
        type: "bulleted_list_item",
        bulleted_list_item: {
          rich_text: notion.richText(
            "In the group: only activates when you @tag it (then it can use recent messages / a voice note you just sent). In a private chat with VAVA: no tag needed."
          ),
        },
      },
      {
        object: "block",
        type: "bulleted_list_item",
        bulleted_list_item: {
          rich_text: notion.richText(
            "“I’m coming” → adds you to an event guest list."
          ),
        },
      },
      {
        object: "block",
        type: "bulleted_list_item",
        bulleted_list_item: {
          rich_text: notion.richText(
            "Tasks: say priority; villager vs management (management = needs a professional / arranging). VAVA asks follow-ups if details are missing."
          ),
        },
      },
      {
        object: "block",
        type: "bulleted_list_item",
        bulleted_list_item: {
          rich_text: notion.richText(
            "Check off work (“have you done this?”), notes on tasks/projects, reminders (“remind me in 3 months”), stagnant-task nudges."
          ),
        },
      },
    ],
  });
  catalog.pages[title] = page.id;
  console.log(`  Created brief: ${page.url || page.id}`);
  return page.id;
}

async function main() {
  notion.assertKey();
  if (!PARENT_PAGE_ID) throw new Error("Missing NOTION_PARENT_PAGE_ID / catalog parentPageId");

  console.log("Migrating Notion structure to v2...");
  console.log(`Parent: ${PARENT_PAGE_ID}`);

  const catalog = notion.loadCatalog();
  catalog.parentPageId = PARENT_PAGE_ID;
  catalog.databases = catalog.databases || {};
  catalog.pages = catalog.pages || {};

  const calendars = await ensureSection(PARENT_PAGE_ID, "Calendars", catalog);
  const work = await ensureSection(PARENT_PAGE_ID, "Work", catalog);
  const community = await ensureSection(PARENT_PAGE_ID, "Community", catalog);
  const partnerships = await ensureSection(PARENT_PAGE_ID, "Partnerships", catalog);

  // Rename Events → External Events (keep same id / market data)
  if (catalog.databases.Events && !catalog.databases["External Events"]) {
    console.log("\n=== Rename Events → External Events ===");
    await renameDatabase(catalog.databases.Events, "External Events");
    catalog.databases["External Events"] = catalog.databases.Events;
    delete catalog.databases.Events;
    console.log("  Renamed");
  }

  console.log("\n=== Calendars databases ===");
  await ensureDatabase(
    calendars.id,
    "Internal Events",
    {
      Name: title(),
      Stage: selectProp(["Ideas", "Planning", "Upcoming", "Recurring", "Done / past"]),
      Status: selectProp(["Planned", "Confirmed", "Cancelled", "Done"]),
      Date: date(),
      Recurrence: rich(),
      "People in charge": rich(),
      "Guest list": rich(),
      Location: rich(),
      "Announce on Telegram?": check(),
      Source: selectProp(["Telegram", "Manual", "Seed"]),
      Notes: rich(),
    },
    catalog
  );
  await ensureDatabase(
    calendars.id,
    "External Events",
    {
      Name: title(),
      Stage: selectProp(["Ideas", "Planning", "Upcoming", "Recurring", "Done / past"]),
      Status: selectProp(["Planned", "Confirmed", "Cancelled", "Done"]),
      "Event type": selectProp([
        "Market", "Trip / outing", "Gathering", "Delivery", "Other",
      ]),
      Date: date(),
      Recurrence: rich(),
      "Helpers / doers": rich(),
      Location: rich(),
      "Guest list": rich(),
      "Announce on Telegram?": check(),
      Source: selectProp(["Telegram", "Manual", "Seed"]),
      Notes: rich(),
    },
    catalog
  );
  await ensureDatabase(
    calendars.id,
    "Reminders",
    {
      Name: title(),
      Status: selectProp(["Open", "Done", "Cancelled"]),
      "Due date": date(),
      Time: rich(),
      Recurrence: rich(),
      "Created by": rich(),
      Notes: rich(),
    },
    catalog
  );

  // Calendar views
  for (const name of ["Internal Events", "External Events", "Reminders", "Campsite Bookings", "Campsite Meals"]) {
    const id = catalog.databases[name];
    if (!id) continue;
    const dateProp = name === "Reminders" ? "Due date" : "Date";
    try {
      const view = await notion.ensureCalendarView(id, {
        name: "Calendar",
        dateProperty: dateProp,
      });
      console.log(`  Calendar view ${name}: ${view.wasCreated ? "created" : "ok"}`);
    } catch (err) {
      console.warn(`  Calendar view ${name}: ${err.message}`);
    }
  }

  console.log("\n=== Work: simplify Tasks + Jobs ===");
  if (catalog.databases.Tasks) {
    const added = await addProperties(catalog.databases.Tasks, {
      Type: selectProp(["Villager", "Management"]),
      Assignee: rich(),
      "Created by": rich(),
      Status: selectProp(["Not started", "In progress", "Done"]),
      Priority: selectProp(["Low", "Average", "High"]),
      Notes: rich(),
    });
    console.log(`  Tasks props: ${added.added.join(", ") || "(already present)"}`);
    console.log("  Tip: run npm run notion:simplify-tasks to drop unused columns.");
  }

  await ensureDatabase(
    work.id,
    "Jobs",
    {
      Name: title(),
      Status: selectProp(["Active", "Paused", "Archived"]),
      Area: selectProp([
        "Kitchen", "Toilets", "Garden", "Land", "Campsite", "Buildings", "Other",
      ]),
      Description: rich(),
      "Primary people": rich(),
      Notes: rich(),
    },
    catalog
  );

  if (catalog.databases["Recurring Duties"]) {
    const added = await addProperties(catalog.databases["Recurring Duties"], {
      Job: rich(), // text match to Jobs name until relation wired in UI
      Frequency: selectProp(["Daily", "Weekly", "Monthly", "Seasonal"]),
      Recurrence: rich(),
      "Next due date": date(),
      "Last completed": date(),
      Assignee: rich(),
      Notes: rich(),
    });
    console.log(`  Recurring Duties props: ${added.added.join(", ") || "(ok)"}`);
  }

  if (catalog.databases.Projects) {
    const added = await addProperties(catalog.databases.Projects, {
      Assignees: rich(),
      Status: selectProp(["Ideas", "Planning", "In progress", "Paused", "Done"]),
      Notes: rich(),
      "Target date": date(),
    });
    console.log(`  Projects props: ${added.added.join(", ") || "(ok)"}`);
  }

  console.log("\n=== Partnerships ===");
  await ensureDatabase(
    partnerships.id,
    "Partnerships",
    {
      Name: title(),
      Status: selectProp(["Active", "Paused", "Ended"]),
      "Partner name": rich(),
      Discount: rich(),
      "Affiliate link": url(),
      "Documentation": url(),
      Notes: rich(),
    },
    catalog
  );

  console.log("\n=== Members enrichment ===");
  if (catalog.databases.Members) {
    const added = await addProperties(catalog.databases.Members, {
      Skills: rich(),
      Bio: rich(),
      Profile: rich(),
      "Questionnaire done?": check(),
      "Telegram username": rich(),
      "Display name": rich(),
      "Active?": check(),
    });
    console.log(`  Members props: ${added.added.join(", ") || "(ok)"}`);
  }

  console.log("\n=== Community brief page ===");
  await writeBrief(PARENT_PAGE_ID, catalog);

  notion.saveCatalog(catalog);
  console.log(`\nCatalog saved → ${notion.CATALOG_FILE}`);
  console.log("DONE.");
  console.log("\nThen run: npm run notion:cleanup-to-notes");
  console.log("- On Tasks: hide old columns; use Type / Assignee / Created by / Status / Priority / Notes.");
  console.log("- On Jobs: set Assignee (person name); add Recurring Duties linked via Job.");
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
