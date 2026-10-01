/**
 * Vila Akoya — FAQs Notion page + database.
 *
 * Short Q&A (delivery address, etc.). VAVA reads and updates via Telegram.
 * Safe to re-run.
 *
 *   node scripts/setup-faqs-notion.js
 */

"use strict";

const notion = require("./lib/notion");
const faqs = require("./lib/faqs");

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

  console.log("Setting up FAQs…");
  console.log(`Parent page: ${PARENT_PAGE_ID}\n`);

  const result = await faqs.ensureFaqsDatabase();
  console.log(
    result.wasCreated
      ? `  Created database ${result.db.id}`
      : `  Reusing database ${result.db.id}`
  );
  if (result.seeded) console.log(`  Seeded ${result.seeded} starter FAQ(s)`);
  console.log(`\nCatalog saved → ${notion.CATALOG_FILE}`);
  console.log(`Database URL: ${result.url || "(open FAQs in Notion)"}`);
  console.log(
    '\nDone. Ask VAVA “what is the address for deliveries?” or “Add FAQ: … → …”.'
  );
  console.log("Then restart the Telegram bot: npm run telegram:bot");
}

main().catch((err) => {
  console.error("\nSetup failed:");
  console.error(err);
  process.exit(1);
});
