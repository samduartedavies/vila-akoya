/**
 * Seed Algarve regional markets into Notion External Events (optional sync).
 * Bot also reads scripts/lib/regional-markets.js directly.
 *
 * Run: npm run notion:seed-algarve-markets
 */

"use strict";

const { nextOccurrence, normalizeRecurrenceField } = require("./lib/event-recurrence");
const regionalMarkets = require("./lib/regional-markets");
const notion = require("./lib/notion");

function todayISO(tz = "Europe/Lisbon") {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: tz,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}

function marketItemName(entry) {
  const kind =
    entry.kind === regionalMarkets.MARKET_KIND.FLEA ? "Flea market" : "Farmers market";
  return `${kind} — ${entry.place}`;
}

async function main() {
  notion.assertKey();
  console.log("\n=== Algarve markets → External Events ===");

  const { pages } = await notion.queryDatabase(notion.DB.EXTERNAL_EVENTS);
  const existing = pages.filter((p) => /market\s*[—–-]/i.test(notion.pageTitle(p)));

  const algarveOnly = regionalMarkets.STATIC_REGIONAL_MARKETS.filter(
    (m) => m.source === "My Guide Algarve"
  );

  for (const market of algarveOnly) {
    const info = normalizeRecurrenceField(market.recurrenceText);
    if (!info.rule) throw new Error(`Bad recurrence: ${market.recurrenceText}`);

    const name = marketItemName(market);
    const next = nextOccurrence(info.rule, todayISO());
    const match = existing.find(
      (p) =>
        notion.pageTitle(p) === name &&
        notion.pagePropText(p, "Recurrence") === info.canonical
    );

    const notes = [
      "My Guide Algarve",
      `Market kind: ${market.kind}`,
      market.locale ? `Venue: ${market.locale}` : "",
      market.hours ? `Hours: ${market.hours}` : "",
      `Recurrence: ${info.canonical} (${info.label})`,
      `Next: ${next || "n/a"}`,
    ]
      .filter(Boolean)
      .join("\n");

    const props = {
      Stage: "Recurring",
      Status: "Confirmed",
      "Event type": "Market",
      Date: next || undefined,
      Recurrence: info.canonical,
      Location: market.place,
      "Announce on Telegram?": true,
      Source: "Seed",
      Notes: notes,
    };

    if (match) {
      await notion.updatePageProps(notion.DB.EXTERNAL_EVENTS, match.id, props);
      console.log(`  Updated: ${name} [${info.canonical}]`);
    } else {
      await notion.createPage({
        databaseName: notion.DB.EXTERNAL_EVENTS,
        title: name,
        properties: props,
      });
      console.log(`  Created: ${name} [${info.canonical}]`);
    }
  }

  console.log(`\nDone. ${algarveOnly.length} Algarve market rules processed.`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
