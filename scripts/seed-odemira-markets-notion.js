/**
 * Seed Odemira regional markets into Notion Events (Recurring stage).
 *
 * Run: npm run notion:seed-markets
 */

"use strict";

const {
  nextOccurrence,
  normalizeRecurrenceField,
} = require("./lib/event-recurrence");
const notion = require("./lib/notion");

const MARKETS = [
  { place: "São Teotónio", recurrence: "monthly:1st-Mon" },
  { place: "São Martinho das Amoreiras", recurrence: "monthly:1st-Sat" },
  { place: "Azula", recurrence: "monthly:1st-Sun" },
  { place: "Colos", recurrence: "monthly:2nd-Sat" },
  { place: "Brunheiras", recurrence: "monthly:2nd-Sat" },
  { place: "Aldeia à Vista", recurrence: "monthly:2nd-Sat" },
  { place: "Vale de Santiago (AMAR)", recurrence: "monthly:2nd-Sun" },
  { place: "Algoceira (Mira)", recurrence: "monthly:3rd-Sat" },
  { place: "Moitinhas", recurrence: "monthly:3rd-Sat" },
  { place: "São Luís", recurrence: "monthly:3rd-Sun" },
  { place: "Brunheiras", recurrence: "monthly:4th-Sat" },
  { place: "Ourique", recurrence: "monthly:4th-Sat" },
  { place: "Bicos", recurrence: "monthly:last-Sun" },
  { place: "Milfontes (Benfica)", recurrence: "weekly:Sun" },
];

function todayISO(tz = "Europe/Lisbon") {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: tz,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}

function marketItemName(place) {
  return `Market — ${place}`;
}

async function main() {
  notion.assertKey();
  console.log("\n=== Odemira Markets → Events ===");

  const { pages } = await notion.queryDatabase(notion.DB.EVENTS);
  const existing = pages.filter((p) =>
    notion.pageTitle(p).startsWith("Market — ")
  );

  for (const market of MARKETS) {
    const info = normalizeRecurrenceField(market.recurrence);
    if (!info.rule) throw new Error(`Bad recurrence: ${market.recurrence}`);

    const name = marketItemName(market.place);
    const next = nextOccurrence(info.rule, todayISO());
    const match = existing.find(
      (p) =>
        notion.pageTitle(p) === name &&
        notion.pagePropText(p, "Recurrence") === info.canonical
    );

    const props = {
      Stage: "Recurring",
      Status: "Confirmed",
      "Event type": "Market",
      Date: next || undefined,
      Recurrence: info.canonical,
      Location: market.place,
      "Announce on Telegram?": true,
      Source: "Seed",
      Notes:
        `Odemira Markets poster.\n` +
        `Recurrence: ${info.canonical} (${info.label})\n` +
        `Next: ${next || "n/a"}`,
    };

    if (match) {
      await notion.updatePageProps(notion.DB.EVENTS, match.id, props);
      console.log(`  Updated: ${name} [${info.canonical}] → next ${next}`);
    } else {
      await notion.createPage({
        databaseName: notion.DB.EVENTS,
        title: name,
        properties: props,
      });
      console.log(`  Created: ${name} [${info.canonical}] → next ${next}`);
    }
  }

  console.log(`\nDone. ${MARKETS.length} market rules seeded.`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
