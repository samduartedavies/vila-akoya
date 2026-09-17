/**
 * Vila Akoya — "Where things are" Notion page + database.
 *
 * Living list of keys, tools, and other portable items. VAVA in Telegram
 * reads and updates this table when people say where they left something
 * or ask where it is.
 *
 * Safe to re-run.
 *
 *   node scripts/setup-locations-notion.js
 *   NOTION_PARENT_PAGE_ID=... node scripts/setup-locations-notion.js
 */

"use strict";

const notion = require("./lib/notion");
const locations = require("./lib/locations");

const PARENT_PAGE_ID = (process.env.NOTION_PARENT_PAGE_ID ||
  notion.loadCatalog().parentPageId ||
  "")
  .replace(/-/g, "")
  .replace(
    /^([0-9a-f]{8})([0-9a-f]{4})([0-9a-f]{4})([0-9a-f]{4})([0-9a-f]{12})$/i,
    "$1-$2-$3-$4-$5"
  );

async function main() {
  notion.assertKey();
  if (!PARENT_PAGE_ID || PARENT_PAGE_ID.length < 32) {
    throw new Error(
      "Set NOTION_PARENT_PAGE_ID (or run setup-vila-akoya-notion.js first so the catalog has parentPageId)."
    );
  }

  const catalog = notion.loadCatalog();
  catalog.parentPageId = catalog.parentPageId || PARENT_PAGE_ID;
  notion.saveCatalog(catalog);

  console.log('Setting up "Where things are"…');
  console.log(`Parent page: ${PARENT_PAGE_ID}\n`);

  const result = await locations.ensureLocationsDatabase();
  console.log(
    result.wasCreated
      ? `  Created database ${result.db.id}`
      : `  Reusing database ${result.db.id}`
  );
  if (result.seeded) console.log(`  Seeded ${result.seeded} starter items`);
  console.log(`\nCatalog saved → ${notion.CATALOG_FILE}`);
  console.log(`Database URL: ${result.url || "(open Where things are in Notion)"}`);
  console.log("\nDone. Ask VAVA “where is the drill?” or tell it “the bathroom keys are on the hook”.");
  console.log("Then restart the Telegram bot: npm run telegram:bot");
}

main().catch((err) => {
  console.error("\nSetup failed:");
  console.error(err);
  process.exit(1);
});
