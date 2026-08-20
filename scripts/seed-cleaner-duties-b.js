/**
 * Seed B's Cleaner recurring duties (daily + staggered weekly).
 *
 *   node scripts/seed-cleaner-duties-b.js
 */

"use strict";

const notion = require("./lib/notion");
const { staggeredWeeklyDueDate } = require("./lib/duty-schedule");

const JOB_NAME = "Cleaner";
const ASSIGNEE = "B";

const DAILY_DUTIES = [
  "Sanitary and toilet",
  "Children place",
  "Shared space",
  "Reception",
  "Kitchen",
];

const WEEKLY_DUTIES = ["Shared house", "Deep cleaning bathroom"];

function todayISO() {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: "Europe/Lisbon",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}

async function ensureCleanerJob() {
  const { pages } = await notion.queryDatabase(notion.DB.JOBS);
  let job = pages.find((p) => notion.pageTitle(p).toLowerCase() === JOB_NAME.toLowerCase());
  if (!job) {
    job = await notion.createPage({
      databaseName: notion.DB.JOBS,
      title: JOB_NAME,
      properties: {
        Status: "Active",
        Assignee: ASSIGNEE,
        Description: "Village and campsite cleaning rota.",
      },
    });
    console.log(`  Created job: ${JOB_NAME}`);
  } else {
    await notion.updatePageProps(notion.DB.JOBS, job.id, {
      Status: "Active",
      Assignee: ASSIGNEE,
    });
    console.log(`  Job exists: ${JOB_NAME} → assignee ${ASSIGNEE}`);
  }
  return job;
}

async function upsertDuty({ jobId, title, frequency, nextDue }) {
  const { pages } = await notion.queryDatabase(notion.DB.RECURRING);
  const existing = pages.find(
    (p) =>
      notion.pageTitle(p).toLowerCase() === title.toLowerCase() &&
      (p.properties?.Job?.relation || []).some((r) => r.id === jobId)
  );

  const props = {
    Frequency: frequency,
    Status: "Active",
    "Next due date": nextDue,
    Notes: `Cleaner rota — assignee via Job (${ASSIGNEE})`,
  };

  if (existing) {
    await notion.updatePageProps(notion.DB.RECURRING, existing.id, props);
    if (!(existing.properties?.Job?.relation || []).some((r) => r.id === jobId)) {
      await notion.updatePage(existing.id, {
        properties: { Job: { relation: [{ id: jobId }] } },
      });
    }
    console.log(`    Updated: ${title} (${frequency}, due ${nextDue})`);
    return existing;
  }

  const created = await notion.createPage({
    databaseName: notion.DB.RECURRING,
    title,
    properties: props,
  });
  await notion.updatePage(created.id, {
    properties: { Job: { relation: [{ id: jobId }] } },
  });
  console.log(`    Created: ${title} (${frequency}, due ${nextDue})`);
  return created;
}

async function main() {
  notion.assertKey();
  const today = todayISO();
  console.log(`Seeding Cleaner duties for ${ASSIGNEE} (today ${today})…\n`);

  const job = await ensureCleanerJob();
  const jobId = job.id;

  console.log("\n  Daily:");
  for (const title of DAILY_DUTIES) {
    await upsertDuty({ jobId, title, frequency: "Daily", nextDue: today });
  }

  console.log("\n  Weekly (staggered):");
  const weeklyTotal = WEEKLY_DUTIES.length;
  for (let i = 0; i < WEEKLY_DUTIES.length; i++) {
    const nextDue = staggeredWeeklyDueDate(today, i, weeklyTotal);
    await upsertDuty({
      jobId,
      title: WEEKLY_DUTIES[i],
      frequency: "Weekly",
      nextDue,
    });
  }

  console.log("\nDONE. Reassign Cleaner job in Notion later to change who gets these duties.");
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
