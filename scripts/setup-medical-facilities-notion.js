/**
 * Vila Akoya — Medical & emergency Notion directory + community ratings.
 *
 *   npm run notion:setup-medical
 */

"use strict";

const notion = require("./lib/notion");
const medical = require("./lib/medical-facilities");

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

  console.log("Setting up Medical & emergency…");
  console.log(`Parent page: ${PARENT_PAGE_ID}\n`);

  const result = await medical.ensureMedicalFacilitiesDatabase({ syncAllSeed: true });
  console.log(
    result.wasCreated
      ? `  Created facilities database ${result.db.id}`
      : `  Reusing facilities database ${result.db.id}`
  );
  if (result.seeded) console.log(`  Added ${result.seeded} new facility row(s)`);
  if (result.updated) console.log(`  Updated ${result.updated} existing row(s)`);
  console.log(`\nCatalog saved → ${notion.CATALOG_FILE}`);
  console.log(`Database URL: ${result.url || "(open Medical & emergency in Notion)"}`);
  console.log(
    '\nDone. Ask VAVA “nearest hospital?” or “GP near Ourique?” — rate with “Hospital de Beja: 8”.'
  );
  console.log("Then restart the Telegram bot: npm run telegram:bot");
}

main().catch((err) => {
  console.error("\nSetup failed:");
  console.error(err);
  process.exit(1);
});
