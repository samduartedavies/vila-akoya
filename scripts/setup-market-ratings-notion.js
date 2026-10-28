/**
 * Market ratings database + average columns on External Events.
 *
 *   npm run notion:setup-market-ratings
 */

"use strict";

const notion = require("./lib/notion");
const marketRatings = require("./lib/market-ratings");

const PARENT_PAGE_ID = (process.env.NOTION_PARENT_PAGE_ID ||
  notion.loadCatalog().parentPageId ||
  "")
  .replace(/-/g, "")
  .replace(
    /^([0-9a-f]{8})([0-9a-f]{4})([0-9a-f]{4})([0-9a-f]{4})([0-9a-f]{12})$/i,
    "$1-$2-$3-$4-$5"
  );

function selectOpts(names) {
  const colors = ["gray", "brown", "orange", "yellow", "green", "blue", "purple", "pink", "red"];
  return names.map((name, i) => ({ name, color: colors[i % colors.length] }));
}

async function ensureSection(parentId, title, catalog) {
  if (catalog.pages?.[title]) return { id: catalog.pages[title], title };
  const found = await notion.searchByTitle(title, "page");
  if (found) {
    catalog.pages[title] = found.id;
    return { id: found.id, title };
  }
  const page = await notion.createChildPage({ parentPageId: parentId, title });
  catalog.pages[title] = page.id;
  return { id: page.id, title };
}

async function ensureExternalEventRatingColumns() {
  const db = await notion.resolveDatabase(notion.DB.EXTERNAL_EVENTS);
  const props = db.properties || {};
  const toAdd = {};
  if (!props[marketRatings.AVG_PROP]) {
    toAdd[marketRatings.AVG_PROP] = { number: { format: "number" } };
  }
  if (!props[marketRatings.COUNT_PROP]) {
    toAdd[marketRatings.COUNT_PROP] = { number: { format: "number" } };
  }
  if (Object.keys(toAdd).length) {
    await notion.notion("PATCH", `/databases/${db.id}`, { properties: toAdd });
    console.log(`  Added External Events columns: ${Object.keys(toAdd).join(", ")}`);
  } else {
    console.log("  External Events rating columns already present");
  }
}

async function main() {
  notion.assertKey();
  if (!PARENT_PAGE_ID || PARENT_PAGE_ID.length < 32) {
    throw new Error("Set NOTION_PARENT_PAGE_ID (or run full notion:setup first).");
  }

  const catalog = notion.loadCatalog();
  catalog.databases = catalog.databases || {};
  catalog.pages = catalog.pages || {};

  const calendars = await ensureSection(PARENT_PAGE_ID, "Calendars", catalog);

  console.log("\n=== Market ratings database ===");
  const db = await notion.createDatabase({
    parentPageId: calendars.id,
    title: marketRatings.DB_NAME,
    properties: {
      Name: { title: {} },
      Market: { rich_text: {} },
      "Market key": { rich_text: {} },
      Score: { number: { format: "number" } },
      Reviewer: { rich_text: {} },
      Date: { date: {} },
      Source: {
        select: { options: selectOpts(["Telegram", "Manual", "Import"]) },
      },
      Notes: { rich_text: {} },
    },
  });
  catalog.databases[marketRatings.DB_NAME] = db.id;
  await notion.primeDatabase(marketRatings.DB_NAME, db.id);
  notion.saveCatalog(catalog);
  console.log(db.wasCreated ? `  Created ${db.id}` : `  Reusing ${db.id}`);

  console.log("\n=== External Events averages ===");
  await ensureExternalEventRatingColumns();

  console.log("\nDone. Restart the Telegram bot after rating markets in chat.");
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
