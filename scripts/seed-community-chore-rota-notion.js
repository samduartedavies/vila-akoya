/**
 * Seed Jobs + Recurring Duties (duties nested under job roles via relation).
 *
 * Prefer: npm run notion:restructure-jobs  (clears old duties + seeds examples)
 * This script only upserts the example set without archiving everything.
 *
 *   node scripts/seed-community-chore-rota-notion.js
 */

"use strict";

const notion = require("./lib/notion");

const EXAMPLE_JOBS = [
  {
    name: "Cleaner",
    description: "Keeps toilets, kitchen fridge, and guest showers in shape.",
    duties: [
      { name: "Clean toilets", frequency: "Daily" },
      { name: "Clean fridge", frequency: "Monthly" },
      { name: "Clean guest shower block", frequency: "Daily" },
    ],
  },
  {
    name: "Breakfast cook",
    description: "Prepares community breakfast.",
    duties: [{ name: "Prepare breakfast", frequency: "Daily" }],
  },
  {
    name: "Kitchen",
    description: "Kitchen tidiness and washing up.",
    duties: [
      { name: "Clean up the kitchen", frequency: "Daily" },
      { name: "Bathroom / washing up", frequency: "Daily" },
    ],
  },
  {
    name: "Garden / land",
    description: "Plants, trees, outdoor tidy.",
    duties: [
      { name: "Water trees / plants", frequency: "Weekly" },
      { name: "Trash (to container)", frequency: "Weekly" },
    ],
  },
  {
    name: "Child minder",
    description: "Receives private childcare rosters and parent absence updates from VAVA.",
    duties: [],
  },
];

async function ensureDutyLinked(jobId, dutyName, frequency) {
  const { pages } = await notion.queryDatabase(notion.DB.RECURRING);
  const existing = pages.find(
    (p) =>
      notion.pageTitle(p) === dutyName &&
      (p.properties?.Job?.relation || []).some((r) => r.id === jobId)
  );
  if (existing) {
    console.log(`    Reusing duty: ${dutyName}`);
    return existing;
  }
  const created = await notion.createPage({
    databaseName: notion.DB.RECURRING,
    title: dutyName,
    properties: {
      Frequency: frequency,
      Status: "Active",
      Notes: "Seeded from notion:seed-rota",
    },
  });
  await notion.updatePage(created.id, {
    properties: { Job: { relation: [{ id: jobId }] } },
  });
  console.log(`    Duty → ${dutyName} (${frequency})`);
  return created;
}

async function main() {
  notion.assertKey();
  console.log("Seeding Jobs + Recurring Duties…\n");

  const { pages: jobs } = await notion.queryDatabase(notion.DB.JOBS);
  const byName = new Map(jobs.map((p) => [notion.pageTitle(p), p]));

  for (const job of EXAMPLE_JOBS) {
    let page = byName.get(job.name);
    if (!page) {
      page = await notion.createPage({
        databaseName: notion.DB.JOBS,
        title: job.name,
        properties: {
          Status: "Active",
          Description: job.description,
        },
      });
      console.log(`  Job → ${job.name}`);
    } else {
      console.log(`  Job exists → ${job.name}`);
    }
    const jobId = page.id;
    for (const d of job.duties) {
      await ensureDutyLinked(jobId, d.name, d.frequency);
    }
  }

  console.log("\nDONE. Set Assignee on each Job to a community member name.");
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
