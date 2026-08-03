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
      "Inbox for Telegram / manual jobs before routing into Maintenance or Guest Issues.",
    properties: {
      Name: title(),
      Stage: selectProp([
        "New brain dumps",
        "Needs review",
        "Accepted",
        "Rejected / not a task",
        "Documentation gap",
        "Done",
      ]),
      Status: selectProp(["Open", "In progress", "Blocked", "Done"]),
      Priority: selectProp(["Low", "Medium", "High", "Urgent"]),
      Area: selectProp(AREAS),
      "Doer name": rich(),
      "Due date": date(),
      Source: selectProp(["Telegram", "WhatsApp", "Manual", "Meeting", "Website"]),
      "Telegram user": rich(),
      "Telegram chat ID": rich(),
      "Safety critical?": check(),
      "Visitor visible?": check(),
      "Needs money?": check(),
      "Needs materials?": check(),
      "Estimated effort": rich(),
      Notes: rich(),
    },
    samples: [
      "Example: lower compost toilet smells and sawdust is empty",
      "Example: document what the red valve does on water system",
    ],
    sampleStage: "Needs review",
  },
  {
    key: "Recurring Duties",
    parentSection: "Work",
    purpose:
      "Repeating checks, cleaning, inspections. Use Frequency + Next due; weekly rota uses Mon–Sun columns.",
    properties: {
      Name: title(),
      Stage: selectProp(["Daily", "Weekly", "Monthly", "Seasonal", "Community chore rota", "Done / logged"]),
      Status: selectProp(["Active", "Paused", "Done"]),
      Frequency: selectProp(["Daily", "Weekly", "Monthly", "Seasonal"]),
      Area: selectProp(AREAS),
      "Doer name": rich(),
      "Next due date": date(),
      "Last completed": date(),
      Recurrence: rich(),
      "Safety critical?": check(),
      Notes: rich(),
      Mon: rich(),
      Tue: rich(),
      Wed: rich(),
      Thu: rich(),
      Fri: rich(),
      Sat: rich(),
      Sun: rich(),
    },
    samples: [
      "Check compost sawdust levels",
      "Check water pressure and visible leaks",
      "Clean guest shower block",
    ],
    sampleStage: "Daily",
  },
  {
    key: "Maintenance",
    parentSection: "Work",
    purpose: "Physical systems and repairs — water, compost, solar, buildings, tools, land.",
    properties: {
      Name: title(),
      Stage: selectProp([
        "Water",
        "Compost & toilets",
        "Solar / electric",
        "Buildings / structures",
        "Tools / workshop",
        "Land / roads / drainage",
        "Done",
      ]),
      Status: selectProp(["Open", "In progress", "Waiting", "Done"]),
      Priority: selectProp(["Low", "Medium", "High", "Urgent"]),
      "Doer name": rich(),
      "Due date": date(),
      "Needs materials?": check(),
      "Estimated cost": num(),
      "Safety critical?": check(),
      Notes: rich(),
    },
    samples: ["Inspect red valve and label it clearly", "Create simple map of water shutoff points"],
    sampleStage: "Water",
  },
  {
    key: "Guest Issues",
    parentSection: "Work",
    purpose: "Visitor-facing campsite issues, cleaning, facilities, pitches, feedback.",
    properties: {
      Name: title(),
      Stage: selectProp([
        "New guest issues",
        "Cleaning",
        "Facilities",
        "Pitch / camping area",
        "Check-in / booking",
        "Guest feedback",
        "Done",
      ]),
      Status: selectProp(["Open", "In progress", "Done"]),
      "Guest impact": selectProp(["Low", "Medium", "High"]),
      Priority: selectProp(["Low", "Medium", "High", "Urgent"]),
      "Doer name": rich(),
      "Due date": date(),
      "Visitor visible?": check(),
      Source: selectProp(["Telegram", "Guest", "Manual", "Website"]),
      "Telegram user": rich(),
      Notes: rich(),
    },
    samples: ["Guest reports broken light near shower block"],
    sampleStage: "New guest issues",
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
    purpose: "Bigger build / creative / garden / community projects.",
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
        "Resident",
        "Volunteer",
        "Helper",
        "Guest",
        "Steward",
      ]),
      "Display name": rich(),
      "Telegram username": rich(),
      "Telegram chat ID": rich(),
      "Phone / contact": rich(),
      "Areas of help": selectProp(AREAS),
      "Active?": check(),
      "Joined date": date(),
      Notes: rich(),
    },
    samples: [],
  },
  {
    key: "Stewards",
    parentSection: "Community",
    purpose: "Who owns each area and what needs attention.",
    properties: {
      Name: title(),
      Stage: selectProp(["Core stewards", "Backup stewards", "Needs steward", "Inactive / archived"]),
      Area: selectProp(AREAS),
      "Contact name": rich(),
      "WhatsApp / Telegram": rich(),
      "Current focus": rich(),
      "Open issues": num(),
      "Review date": date(),
      Notes: rich(),
    },
    samples: ["Water steward", "Compost steward", "Guest / campsite steward"],
    sampleStage: "Needs steward",
  },
  {
    key: "Decisions",
    parentSection: "Community",
    purpose: "Proposals and governance — discussion, consent, vote.",
    properties: {
      Name: title(),
      Stage: selectProp([
        "Proposed",
        "In discussion",
        "Needs vote",
        "Decided",
        "Rejected / parked",
      ]),
      Status: selectProp(["Open", "Decided", "Rejected"]),
      Category: selectProp([
        "Campsite",
        "Infrastructure",
        "Community",
        "Money",
        "Governance",
        "Other",
      ]),
      "Proposed by": rich(),
      "Needs vote?": check(),
      "Decision date": date(),
      Outcome: selectProp(["Approved", "Rejected", "Deferred", "Amended"]),
      Notes: rich(),
    },
    samples: ["Proposal: agree quiet hours for the campsite"],
    sampleStage: "Proposed",
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
          "Recurring Duties → Community chore rota stage holds Mon–Sun assignee columns; Frequency + Next due for checks."
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
  for (const name of ["Calendars", "Work", "Community", "Documentation hub"]) {
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
