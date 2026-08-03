#!/usr/bin/env node
"use strict";

/**
 * Sync campsite bookings and meal headcounts from WordPress to monday.com.
 *
 * Requires:
 *   MONDAY_API_TOKEN
 *   VILA_OPS_API_TOKEN
 *   VILA_OPS_API_BASE (or WP_SITE_URL) — e.g. https://vilaakoya.com
 *
 * Run:
 *   npm run monday:setup
 *   npm run monday:sync-campsite
 */

const { syncCampsiteToMonday } = require("./lib/monday-campsite-sync");

syncCampsiteToMonday()
  .then((summary) => {
    console.log(JSON.stringify(summary, null, 2));
  })
  .catch((err) => {
    console.error("Campsite Monday sync failed:", err.message);
    process.exit(1);
  });
