/**
 * Vila Akoya — Expenses (receipts) Notion page + database.
 *
 * Creates a private-by-default Expenses section under the ops parent page,
 * with a table the Telegram bot can write to. The page does NOT need to be
 * shared to the web — the V.A.V.A integration is enough.
 *
 * Safe to re-run.
 *
 *   node scripts/setup-expenses-notion.js
 *   NOTION_PARENT_PAGE_ID=... node scripts/setup-expenses-notion.js
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

const EXPENSE_CATEGORIES = [
  "Food / groceries",
  "Hardware",
  "Fuel",
  "Transport",
  "Utilities",
  "Maintenance",
  "Supplies",
  "Guest / campsite",
  "Other",
];

const EXPENSE_PURPOSES = ["Breakfast", "Bar", "Dinner", "Kids Program"];

const STATUS_OPTIONS = ["To reimburse", "Reimbursed", "Recorded"];

const ENTITY_OPTIONS = ["Aldeia Alcateia", "Povoado Gentil"];

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
          icon: { type: "emoji", emoji: "🧾" },
          rich_text: notion.richText(
            "Village receipts from Telegram. Keep this page private (do not Share to web). The bot writes via the integration — it does not need a public link. In the table: Group by Submitted by, filter Month, and Calculate → Sum on Amount."
          ),
        },
      },
      {
        object: "block",
        type: "paragraph",
        paragraph: {
          rich_text: notion.richText(
            "Each row is one receipt: who submitted it, amount, date, merchant, what it is for (Breakfast / Bar / Dinner / Kids Program), and the photo or pasted text. Status starts as To reimburse."
          ),
        },
      },
    ],
  });
  catalog.pages[title] = page.id;
  console.log(`  Created section: ${title}`);
  return page;
}

const EXPENSE_PROPERTIES = {
  Name: { title: {} },
  Amount: { number: { format: "euro" } },
  Currency: selectProp(["EUR", "GBP", "USD", "Other"]),
  Date: { date: {} },
  Month: { rich_text: {} },
  "Submitted by": { rich_text: {} },
  "Telegram username": { rich_text: {} },
  "Telegram user ID": { rich_text: {} },
  Category: selectProp(EXPENSE_CATEGORIES),
  For: selectProp(EXPENSE_PURPOSES),
  Entity: selectProp(ENTITY_OPTIONS),
  Company: { rich_text: {} },
  NIF: { rich_text: {} },
  Status: selectProp(STATUS_OPTIONS),
  Merchant: { rich_text: {} },
  VAT: { number: { format: "euro" } },
  Source: selectProp(["Photo", "PDF", "Text"]),
  "Receipt text": { rich_text: {} },
  Notes: { rich_text: {} },
  Receipt: { files: {} },
};

async function main() {
  notion.assertKey();
  if (!PARENT_PAGE_ID || PARENT_PAGE_ID.length < 32) {
    throw new Error(
      "Set NOTION_PARENT_PAGE_ID (or run setup-vila-akoya-notion.js first so the catalog has parentPageId)."
    );
  }

  console.log("Setting up Expenses…");
  console.log(`Parent page: ${PARENT_PAGE_ID}\n`);

  const catalog = notion.loadCatalog();
  catalog.parentPageId = catalog.parentPageId || PARENT_PAGE_ID;
  catalog.databases = catalog.databases || {};
  catalog.pages = catalog.pages || {};

  const section = await ensureSection(PARENT_PAGE_ID, "Expenses", catalog);
  notion.saveCatalog(catalog);

  const db = await notion.createDatabase({
    parentPageId: section.id,
    title: notion.DB.EXPENSES,
    properties: EXPENSE_PROPERTIES,
  });
  catalog.databases[notion.DB.EXPENSES] = db.id;
  await notion.primeDatabase(notion.DB.EXPENSES, db.id);
  notion.saveCatalog(catalog);
  console.log(
    db.wasCreated
      ? `  Created database ${db.id}`
      : `  Reusing database ${db.id}`
  );

  try {
    await notion.notion("PATCH", `/databases/${db.id}`, {
      properties: EXPENSE_PROPERTIES,
    });
    notion.clearDatabaseCache(notion.DB.EXPENSES);
    console.log("  Properties ensured");
  } catch (err) {
    console.warn(`  Property patch skipped: ${err.message}`);
  }

  const sorts = [{ property: "Date", direction: "descending" }];

  try {
    const all = await notion.ensureTableView(db.id, {
      name: "All receipts",
      sorts,
    });
    console.log(
      `  View “All receipts”: ${all.wasCreated ? "created" : "exists"} ${all.url || ""}`
    );
  } catch (err) {
    console.warn(`  All receipts view skipped: ${err.message}`);
  }

  try {
    const unpaid = await notion.ensureTableView(db.id, {
      name: "To reimburse",
      filter: {
        property: "Status",
        select: { equals: "To reimburse" },
      },
      sorts,
    });
    console.log(
      `  View “To reimburse”: ${unpaid.wasCreated ? "created" : "exists"} ${unpaid.url || ""}`
    );
  } catch (err) {
    console.warn(`  To reimburse view skipped: ${err.message}`);
  }

  for (const entity of ENTITY_OPTIONS) {
    try {
      const view = await notion.ensureTableView(db.id, {
        name: entity,
        filter: {
          property: "Entity",
          select: { equals: entity },
        },
        sorts,
      });
      console.log(
        `  View “${entity}”: ${view.wasCreated ? "created" : "exists"} ${view.url || ""}`
      );
    } catch (err) {
      console.warn(`  View “${entity}” skipped: ${err.message}`);
    }
  }

  for (const purpose of EXPENSE_PURPOSES) {
    try {
      const view = await notion.ensureTableView(db.id, {
        name: purpose,
        filter: {
          property: "For",
          select: { equals: purpose },
        },
        sorts,
      });
      console.log(
        `  View “${purpose}”: ${view.wasCreated ? "created" : "exists"} ${view.url || ""}`
      );
    } catch (err) {
      console.warn(`  View “${purpose}” skipped: ${err.message}`);
    }
  }

  notion.saveCatalog(catalog);
  console.log(`\nCatalog saved → ${notion.CATALOG_FILE}`);
  console.log(`Database URL: ${db.url || "(open the Expenses page in Notion)"}`);
  console.log("\nDone. Restrict sharing on the Expenses page if the rest of ops is community-visible.");
  console.log("Then restart the Telegram bot: npm run telegram:bot");
}

main().catch((err) => {
  console.error("\nSetup failed:");
  console.error(err);
  process.exit(1);
});
