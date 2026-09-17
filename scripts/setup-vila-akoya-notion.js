/**
 * Vila Akoya — Notion workspace setup
 *
 * Creates a Notion-native ops structure (calendars for events/bookings/meals,
 * rich documentation pages, recurring duties, tasks, community dirs).
 *
 * Prerequisites:
 * 1. Create a blank Notion page (e.g. "Vila Akoya Ops").
 * 2. Share it with the V.A.V.A integration (Connections → invite).
 * 3. Copy the page ID from the URL (32-char hex after the title slug).
 *
 * Run:
 *   NOTION_API_KEY="ntn_..." NOTION_PARENT_PAGE_ID="..." node scripts/setup-vila-akoya-notion.js
 *
 * Optional:
 *   INCLUDE_SAMPLE_ITEMS="false"
 *   NOTION_PACING_MS="350"
 */

"use strict";

const notion = require("./lib/notion");

const PARENT_PAGE_ID = (process.env.NOTION_PARENT_PAGE_ID || "")
  .replace(/-/g, "")
  .replace(
    /^([0-9a-f]{8})([0-9a-f]{4})([0-9a-f]{4})([0-9a-f]{4})([0-9a-f]{12})$/i,
    "$1-$2-$3-$4-$5"
  );
const INCLUDE_SAMPLES = process.env.INCLUDE_SAMPLE_ITEMS !== "false";

const AREAS = [
  "Water",
  "Compost & toilets",
  "Solar / electric",
  "Buildings / structures",
  "Tools / workshop",
  "Land / roads / drainage",
  "Campsite / guest",
  "Garden",
  "Community / events",
  "Safety & emergency",
  "Admin / money",
  "Other",
];

function selectOpts(names) {
  const colors = [
    "gray",
    "brown",
    "orange",
    "yellow",
    "green",
    "blue",
    "purple",
    "pink",
    "red",
  ];
  return names.map((name, i) => ({
    name,
    color: colors[i % colors.length],
  }));
}

function selectProp(options) {
  return { select: { options: selectOpts(options) } };
}

function rich() {
  return { rich_text: {} };
}
function num() {
  return { number: { format: "number" } };
}
function euro() {
  return { number: { format: "euro" } };
}
function files() {
  return { files: {} };
}
function check() {
  return { checkbox: {} };
}
function date() {
  return { date: {} };
}
function url() {
  return { url: {} };
}
function title() {
  return { title: {} };
}

const DOC_AREAS = [
  "Water",
  "Compost & toilets",
  "Solar / electric",
  "Buildings / structures",
  "Tools / workshop",
  "Land / roads / drainage",
  "Campsite / guest",
  "Garden",
  "Community / events",
  "Safety & emergency",
  "Admin / money",
];

/** Database schemas — Stage replaces Monday groups. */
const DATABASES = [
  {
    key: "Tasks",
    parentSection: "Work",
    purpose:
      "Inbox for village and management jobs from Telegram / daily life.",
    properties: {
      Name: title(),
      Type: selectProp(["Villager", "Management"]),
      Assignee: rich(),
      "Created by": rich(),
      Status: selectProp(["Not started", "In progress", "Done"]),
      Priority: selectProp(["Low", "Average", "High"]),
      Notes: rich(),
    },
    samples: [
      "Example: lower compost toilet smells and sawdust is empty",
      "Example: document what the red valve does on water system",
    ],
  },
  {
    key: "Recurring Duties",
    parentSection: "Work",
    purpose:
      "Duties that belong to a Job role (e.g. Cleaner → clean toilets daily). Link via Job relation.",
    properties: {
      Name: title(),
      Status: selectProp(["Active", "Paused", "Done"]),
      Frequency: selectProp(["Daily", "Weekly", "Monthly", "Seasonal"]),
      "Next due date": date(),
      "Last completed": date(),
      "Completion log": rich(),
      Notes: rich(),
    },
    samples: [],
  },
  {
    key: "Jobs",
    parentSection: "Work",
    purpose:
      "Job roles (Cleaner, Breakfast cook…). Assignee is a community member name (text). Recurring Duties link here.",
    properties: {
      Name: title(),
      Assignee: rich(),
      Status: selectProp(["Active", "Paused", "Archived"]),
      Description: rich(),
      Notes: rich(),
    },
    samples: ["Child minder"],
  },
  {
    key: "Campsite Bookings",
    parentSection: "Calendars",
    purpose:
      "One row per calendar day — check-ins, check-outs, on site. Add a Calendar layout on Date in Notion.",
    calendarView: { name: "Calendar", dateColumn: "Date" },
    properties: {
      Name: title(),
      "Day ID": rich(),
      Date: date(),
      "Checking in": rich(),
      "Checking out": rich(),
      Staying: rich(),
    },
    samples: [],
  },
  {
    key: "Guest Stays",
    parentSection: "Calendars",
    purpose:
      "Off-site bookings (email, phone, walk-in) logged via VAVA. One row per stay. Website bookings stay on Campsite Bookings.",
    calendarView: { name: "Check-ins", dateColumn: "Check-in" },
    properties: {
      Name: title(),
      "Check-in": date(),
      "Check-out": date(),
      Kind: selectProp(["Campsite", "Accommodation", "Other"]),
      Unit: rich(),
      Party: rich(),
      Adults: num(),
      Children: num(),
      Source: selectProp(["Email", "Phone", "Telegram", "Walk-in", "Other"]),
      Status: selectProp(["Confirmed", "Tentative", "Cancelled"]),
      Notes: rich(),
      "Logged by": rich(),
    },
    samples: [],
  },
  {
    key: "Campsite Meals",
    parentSection: "Calendars",
    purpose:
      "Daily kitchen headcounts (breakfast + weekday dinner). Add a Calendar layout on Date.",
    calendarView: { name: "Calendar", dateColumn: "Date" },
    properties: {
      Name: title(),
      "Day ID": rich(),
      Date: date(),
      "Breakfast cooks": num(),
      "Dinner cooks": num(),
      "Breakfast detail": rich(),
      "Dinner detail": rich(),
      "Weekday dinner?": check(),
    },
    samples: [],
  },
  {
    key: "Childcare",
    parentSection: "Calendars",
    purpose:
      "Daily childcare roster for child minders. Add a Calendar layout on Date. Contains parent contacts and allergy/medical notes.",
    calendarView: { name: "Calendar", dateColumn: "Date" },
    properties: {
      Name: title(),
      "Day ID": rich(),
      Date: date(),
      Headcount: num(),
      Capacity: num(),
      Roster: rich(),
      Absent: num(),
      "Open?": check(),
    },
    samples: [],
  },
  {
    key: "Events",
    parentSection: "Calendars",
    purpose:
      "Gatherings, markets, work parties. Date + Recurrence; open any row for a full agenda page. Calendar layout on Date.",
    calendarView: { name: "Calendar", dateColumn: "Date" },
    properties: {
      Name: title(),
      Stage: selectProp(["Ideas", "Planning", "Upcoming", "Recurring", "Done / past"]),
      Status: selectProp(["Planned", "Confirmed", "Cancelled", "Done"]),
      "Event type": selectProp([
        "Shared meal",
        "Work party",
        "Workshop",
        "Celebration",
        "Market",
        "Delivery",
        "Other",
      ]),
      Date: date(),
      Recurrence: rich(),
      "Helpers / doers": rich(),
      Location: rich(),
      "Needs volunteers?": check(),
      "Announce on Telegram?": check(),
      Source: selectProp(["Telegram", "Manual", "Seed"]),
      Notes: rich(),
    },
    samples: ["Weekly shared meal", "Monthly work party"],
    sampleStage: "Upcoming",
  },
  {
    key: "Projects",
    parentSection: "Work",
    purpose:
      "Bigger build / creative / garden / community projects. Use the Tasks relation for assignable work items.",
    properties: {
      Name: title(),
      Stage: selectProp([
        "Ideas",
        "Approved",
        "Planning",
        "In progress",
        "Waiting for money/materials",
        "Done",
      ]),
      Status: selectProp(["Active", "Paused", "Done"]),
      "Project type": selectProp(["Build", "Garden", "Social", "Creative", "Other"]),
      Priority: selectProp(["Low", "Medium", "High"]),
      "Helpers / doers": rich(),
      "Target date": date(),
      "Estimated cost": num(),
      "Needs community decision?": check(),
      Notes: rich(),
    },
    samples: ["Build shaded seating area near campsite"],
    sampleStage: "Ideas",
  },
  {
    key: "Documentation",
    parentSection: "Documentation hub",
    purpose:
      "Each row is a full Notion page (body = the doc). Prefer this over a separate register + workdoc.",
    properties: {
      Name: title(),
      "Doc status": selectProp(["Draft", "Needs review", "Approved", "Deprecated"]),
      Area: selectProp(AREAS),
      "Last reviewed": date(),
      "Review frequency": selectProp(["Monthly", "Quarterly", "Yearly", "As needed"]),
      Criticality: selectProp(["Low", "Medium", "High", "Emergency"]),
      "Telegram user": rich(),
      Summary: rich(),
      Notes: rich(),
    },
    samples: [
      "Water System — Valve Map",
      "Compost Toilet — Daily Use & Troubleshooting",
      "Guest Check-in Process",
      "Emergency Contacts & First Response",
    ],
    sampleDocStatus: "Draft",
  },
  {
    key: "Members",
    parentSection: "Community",
    purpose: "Residents, volunteers, helpers — Telegram handles for the bot.",
    properties: {
      Name: title(),
      Stage: selectProp([
        "Residents",
        "Long-term volunteers",
        "Short-term helpers / guests",
        "Onboarding",
        "Left / inactive",
      ]),
      "Member status": selectProp(["Active", "Away", "Inactive"]),
      "Role / focus": selectProp([
        "Villager",
        "Volunteer",
        "Worker",
        "Guest",
        "Manager",
      ]),
      "Display name": rich(),
      "Telegram username": rich(),
      "Telegram user ID": rich(),
      "Telegram chat ID": rich(),
      "Phone / contact": rich(),
      "Areas of help": selectProp(AREAS),
      "Help areas": {
        multi_select: { options: selectOpts(AREAS) },
      },
      Skillsets: {
        multi_select: {
          options: selectOpts([
            "Building / carpentry",
            "Garden / land",
            "Cooking / food",
            "Solar / electrical",
            "Plumbing / water",
            "Vehicles / mechanics",
            "Languages / translation",
            "Kids / education",
            "Events / hosting",
            "Admin / money",
            "Art / music",
            "Healing / bodywork",
          ]),
        },
      },
      Skills: rich(),
      Bio: rich(),
      Profile: rich(),
      "Questionnaire done?": check(),
      "Active?": check(),
      "Joined date": date(),
      Notes: rich(),
    },
    samples: [],
  },
  {
    key: "Expenses",
    parentSection: "Expenses",
    purpose:
      "Receipts from Telegram (photo, PDF, or pasted text). Keep this page private — the bot uses the integration, not a public link. Group by Submitted by and Sum Amount.",
    properties: {
      Name: title(),
      Amount: euro(),
      Currency: selectProp(["EUR", "GBP", "USD", "Other"]),
      Date: date(),
      Month: rich(),
      "Submitted by": rich(),
      "Telegram username": rich(),
      "Telegram user ID": rich(),
      Category: selectProp([
        "Food / groceries",
        "Hardware",
        "Fuel",
        "Transport",
        "Utilities",
        "Maintenance",
        "Supplies",
        "Guest / campsite",
        "Other",
      ]),
      Entity: selectProp(["Aldeia Alcateia", "Povoado Gentil"]),
      Company: rich(),
      NIF: rich(),
      Status: selectProp(["To reimburse", "Reimbursed", "Recorded"]),
      Merchant: rich(),
      VAT: euro(),
      Source: selectProp(["Photo", "PDF", "Text"]),
      "Receipt text": rich(),
      Notes: rich(),
      Receipt: files(),
    },
    samples: [],
  },
  {
    key: "Where things are",
    parentSection: "Documentation hub",
    purpose:
      "Portable items (keys, tools, chargers). VAVA answers “where is X?” and updates this when someone says where they left it.",
    properties: {
      Name: title(),
      "Also known as": rich(),
      "Current location": rich(),
      "Usual place": rich(),
      Kind: selectProp([
        "Keys",
        "Tool",
        "Supplies",
        "Vehicle",
        "Document",
        "Furniture",
        "Other",
      ]),
      Area: selectProp(AREAS),
      Status: selectProp(["Active", "Missing", "Archived"]),
      "Last seen": date(),
      "Last updated by": rich(),
      Notes: rich(),
    },
    samples: ["Bathroom keys", "Drill"],
  },
];

async function ensureSection(parentId, title, catalog) {
  const existingId = catalog.pages?.[title];
  if (existingId) {
    console.log(`  Reusing section page: ${title}`);
    return { id: existingId, title };
  }
  const found = await notion.searchByTitle(title, "page");
  if (found) {
    catalog.pages[title] = found.id;
    console.log(`  Found section page: ${title}`);
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
          rich_text: notion.richText(
            "Vila Akoya ops — created by setup-vila-akoya-notion.js"
          ),
        },
      },
    ],
  });
  catalog.pages[title] = page.id;
  console.log(`  Created section page: ${title}`);
  return page;
}

async function seedSample(dbKey, config) {
  if (!INCLUDE_SAMPLES || !config.samples?.length) return;
  const { pages } = await notion.queryDatabase(dbKey);
  if (pages.length > 0) {
    console.log(`  Skipping samples — ${dbKey} already has rows`);
    return;
  }
  for (const name of config.samples) {
    const props = {};
    if (config.sampleStage) props.Stage = config.sampleStage;
    if (config.sampleDocStatus) props["Doc status"] = config.sampleDocStatus;
    if (dbKey === "Documentation") {
      const area =
        name.includes("Water")
          ? "Water"
          : name.includes("Compost")
            ? "Compost & toilets"
            : name.includes("Guest")
              ? "Campsite / guest"
              : name.includes("Emergency")
                ? "Safety & emergency"
                : "Other";
      props.Area = area;
      props.Summary = `Sample stub for ${name}`;
    }
    await notion.createPage({ databaseName: dbKey, title: name, properties: props });
    console.log(`  Sample: ${name}`);
  }
}

async function setupOverview(parentId) {
  await notion.appendBlocks(parentId, [
    {
      object: "block",
      type: "heading_1",
      heading_1: { rich_text: notion.richText("Vila Akoya Ops") },
    },
    {
      object: "block",
      type: "paragraph",
      paragraph: {
        rich_text: notion.richText(
          "Community operations live here — calendars for events, bookings and meals; work databases for tasks and maintenance; documentation as full Notion pages; members and decisions for community life."
        ),
      },
    },
    {
      object: "block",
      type: "heading_2",
      heading_2: { rich_text: notion.richText("How to use") },
    },
    {
      object: "block",
      type: "bulleted_list_item",
      bulleted_list_item: {
        rich_text: notion.richText(
          "Calendars → open Events / Bookings / Meals and switch the layout to Calendar on the Date property."
        ),
      },
    },
    {
      object: "block",
      type: "bulleted_list_item",
      bulleted_list_item: {
        rich_text: notion.richText(
          "Documentation → each row is the doc itself (open the page for the full write-up)."
        ),
      },
    },
    {
      object: "block",
      type: "bulleted_list_item",
      bulleted_list_item: {
        rich_text: notion.richText(
          "Jobs = roles with Assignee; Recurring Duties link under each job. Marking a duty done logs the day and sets Next due from Frequency."
        ),
      },
    },
    {
      object: "block",
      type: "bulleted_list_item",
      bulleted_list_item: {
        rich_text: notion.richText(
          "Telegram bot reads and writes these databases. Re-index docs with: npm run docs:index"
        ),
      },
    },
  ]);
}

async function setupDocAreaPages(docsHubId, catalog) {
  for (const area of DOC_AREAS) {
    const key = `Docs · ${area}`;
    if (catalog.pages[key]) continue;
    const page = await notion.createChildPage({
      parentPageId: docsHubId,
      title: area,
      children: [
        {
          object: "block",
          type: "paragraph",
          paragraph: {
            rich_text: notion.richText(
              `Hub for ${area} documentation. Filter the Documentation database by Area = “${area}”, or create new docs from Telegram.`
            ),
          },
        },
      ],
    });
    catalog.pages[key] = page.id;
    console.log(`  Doc area page: ${area}`);
  }
}

async function setup() {
  notion.assertKey();
  if (!PARENT_PAGE_ID || PARENT_PAGE_ID.length < 32) {
    throw new Error(
      "Set NOTION_PARENT_PAGE_ID to the page shared with the V.A.V.A integration.\n" +
        "Example URL: https://www.notion.so/Vila-Akoya-Ops-abc123... → use the 32-char id."
    );
  }

  console.log("Starting Vila Akoya Notion setup...");
  console.log(`Parent page: ${PARENT_PAGE_ID}`);
  console.log(`Samples: ${INCLUDE_SAMPLES}`);

  const catalog = notion.loadCatalog();
  catalog.parentPageId = PARENT_PAGE_ID;
  catalog.databases = catalog.databases || {};
  catalog.pages = catalog.pages || {};

  try {
    await setupOverview(PARENT_PAGE_ID);
  } catch (err) {
    // Overview may already have been appended on a re-run.
    console.log(`  Overview note: ${err.message}`);
  }

  const sections = {};
  for (const name of ["Calendars", "Work", "Community", "Documentation hub", "Expenses"]) {
    sections[name] = await ensureSection(PARENT_PAGE_ID, name, catalog);
  }

  const results = [];
  for (const config of DATABASES) {
    console.log(`\n=== ${config.key} ===`);
    console.log(`  ${config.purpose}`);
    const parent = sections[config.parentSection];
    if (!parent?.id) throw new Error(`Missing section ${config.parentSection}`);

    const db = await notion.createDatabase({
      parentPageId: parent.id,
      title: config.key,
      properties: config.properties,
    });
    catalog.databases[config.key] = db.id;
    await notion.primeDatabase(config.key, db.id);
    notion.saveCatalog(catalog);
    console.log(
      db.wasCreated
        ? `  Created database (${db.id})`
        : `  Reusing database (${db.id})`
    );
    await seedSample(config.key, config);
    if (config.calendarView) {
      try {
        const view = await notion.ensureCalendarView(db.id, {
          name: config.calendarView.name || "Calendar",
          dateProperty: config.calendarView.dateColumn || "Date",
        });
        console.log(
          view.wasCreated
            ? `  Created Calendar view`
            : `  Reusing Calendar view`
        );
      } catch (err) {
        console.warn(`  Calendar view skipped: ${err.message}`);
      }
    }
    results.push({ name: config.key, id: db.id, url: db.url, wasCreated: db.wasCreated });
  }

  console.log("\n=== Documentation area hubs ===");
  await setupDocAreaPages(sections["Documentation hub"].id, catalog);

  notion.saveCatalog(catalog);
  console.log(`\nCatalog written to ${notion.CATALOG_FILE}`);
  console.log("\nDONE. Databases:");
  console.log(JSON.stringify(results, null, 2));

  console.log("\nNext steps:");
  console.log("1. Invite community members to the parent page.");
  console.log("2. Seed rota + markets: npm run notion:seed-rota && npm run notion:seed-markets");
  console.log("3. Sync campsite: npm run notion:sync-campsite");
  console.log("4. Index docs: npm run docs:index");
  console.log("5. Start bot: npm run telegram:bot");
  console.log(
    "6. In wp-config.php set: define('VILA_NOTION_API_KEY', '...'); and database IDs from the catalog if needed."
  );
}

setup().catch((err) => {
  console.error("\nSetup failed:");
  console.error(err);
  process.exit(1);
});
