/**
 * Seed the community chore rota into Notion "Recurring Duties".
 *
 * Run: npm run notion:seed-rota
 */

"use strict";

const notion = require("./lib/notion");

const DAY_KEYS = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];

const WEEKLY_ROTA = [
  {
    chore: "Breakfast",
    area: "Community / events",
    days: {
      Mon: "Dylan",
      Tue: "Therese",
      Wed: "Moon",
      Thu: "Maaike",
      Fri: "Chanel",
      Sat: "Ana",
      Sun: "Raquel",
    },
  },
  {
    chore: "Toilets",
    area: "Compost & toilets",
    days: {
      Mon: "Chanel",
      Tue: "Dylan",
      Wed: "Moon",
      Thu: "Therese",
      Fri: "Maaike",
      Sat: null,
      Sun: "Ana",
    },
  },
  {
    chore: "Bathroom / washing up",
    area: "Buildings / structures",
    days: {
      Mon: "Sammy",
      Tue: "Therese",
      Wed: "Dylan",
      Thu: "Moon",
      Fri: "Sam",
      Sat: "Maaike",
      Sun: null,
    },
  },
  {
    chore: "Water trees / plants",
    area: "Garden",
    days: {
      Mon: "Therese",
      Tue: "Sammy",
      Wed: "Tamar",
      Thu: "Dylan",
      Fri: "Moon",
      Sat: null,
      Sun: "Maaike",
    },
  },
  {
    chore: "Clean up the kitchen",
    area: "Buildings / structures",
    days: {
      Mon: "Therese",
      Tue: "Chanel",
      Wed: "Sammy",
      Thu: "Tamar",
      Fri: "Dylan",
      Sat: "Moon",
      Sun: "Therese",
    },
  },
  {
    chore: "Fish river",
    area: "Land / roads / drainage",
    days: { Mon: "Luis", Tue: null, Wed: null, Thu: null, Fri: null, Sat: null, Sun: null },
  },
  {
    chore: "Trash (to container)",
    area: "Campsite / guest",
    days: { Mon: null, Tue: null, Wed: null, Thu: null, Fri: null, Sat: null, Sun: null },
  },
];

const ADHOC_TODOS = [
  "Clear out shelves in the kitchen",
  "Organising fridges",
  "Clean up bird poop on balcony",
  "Find what smells bad beside the breakfast fridge",
];

async function seedRota() {
  console.log("\n=== Community chore rota → Recurring Duties ===");
  const { pages } = await notion.queryDatabase(notion.DB.RECURRING);
  const byName = new Map(pages.map((p) => [notion.pageTitle(p), p]));

  for (const row of WEEKLY_ROTA) {
    const props = {
      Stage: "Community chore rota",
      Status: "Active",
      Frequency: "Weekly",
      Area: row.area,
      Notes: "Weekly community chore rota (one row per chore, days in columns).",
    };
    for (const day of DAY_KEYS) {
      props[day] = row.days[day] || "";
    }

    const existing = byName.get(row.chore);
    if (existing) {
      await notion.updatePageProps(notion.DB.RECURRING, existing.id, props);
      console.log(`  Updated: ${row.chore}`);
    } else {
      await notion.createPage({
        databaseName: notion.DB.RECURRING,
        title: row.chore,
        properties: props,
      });
      console.log(`  Created: ${row.chore}`);
    }
  }
}

async function seedAdHocTodos() {
  console.log("\n=== Whiteboard to-dos → Tasks ===");
  const { pages } = await notion.queryDatabase(notion.DB.TASKS);
  const names = new Set(pages.map((p) => notion.pageTitle(p)));

  for (const task of ADHOC_TODOS) {
    if (names.has(task)) {
      console.log(`  Reusing: ${task}`);
      continue;
    }
    await notion.createPage({
      databaseName: notion.DB.TASKS,
      title: task,
      properties: {
        Stage: "Needs review",
        Status: "Open",
        Area: "Buildings / structures",
        Notes: "From community whiteboard TO DO list.",
        Source: "Manual",
      },
    });
    console.log(`  Created: ${task}`);
  }
}

async function main() {
  notion.assertKey();
  console.log("Seeding Vila Akoya community chore rota on Notion...");
  await seedRota();
  await seedAdHocTodos();
  console.log("\nDONE.");
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
