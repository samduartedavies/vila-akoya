/**
 * Sync campsite stay-days + meals from WordPress ops API → Notion.
 *
 * Run: npm run notion:sync-campsite
 * Local: npm run notion:sync-campsite:local
 */

"use strict";

const { syncCampsiteToNotion } = require("./lib/notion-campsite-sync");

syncCampsiteToNotion()
  .then(() => process.exit(0))
  .catch((err) => {
    console.error(err);
    process.exit(1);
  });
