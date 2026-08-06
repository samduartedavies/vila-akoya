/**
 * Restructure Jobs + Recurring Duties per community notes:
 *
 * Jobs = job roles (Cleaner, Breakfast cook…), assigned by name (text).
 * Recurring Duties = duties belonging to a Job (relation), e.g. toilets daily.
 *
 * Clears existing Recurring Duties rows, slims both schemas, seeds examples.
 *
 *   node scripts/restructure-jobs-duties.js
 */

"use strict";

const notion = require("./lib/notion");

const JOBS_KEEP = new Set([
  "Name",
  "Assignee",
  "Status",
  "Description",
  "Notes",
  "Duties", // dual from Recurring Duties
]);

const DUTIES_KEEP = new Set([
  "Name",
  "Job",
  "Frequency",
  "Status",
  "Next due date",
  "Last completed",
  "Completion log",
  "Notes",
]);

const EXAMPLE_JOBS = [
  {
    name: "Cleaner",
    assignee: "",
    description: "Keeps toilets, kitchen fridge, and guest showers in shape.",
    duties: [
      { name: "Clean toilets", frequency: "Daily" },
      { name: "Clean fridge", frequency: "Monthly" },
      { name: "Clean guest shower block", frequency: "Daily" },
    ],
  },
  {
    name: "Breakfast cook",
    assignee: "",
    description: "Prepares community breakfast.",
    duties: [{ name: "Prepare breakfast", frequency: "Daily" }],
  },
  {
    name: "Kitchen",
    assignee: "",
    description: "Kitchen tidiness and washing up.",
    duties: [
      { name: "Clean up the kitchen", frequency: "Daily" },
      { name: "Bathroom / washing up", frequency: "Daily" },
    ],
  },
  {
    name: "Garden / land",
    assignee: "",
    description: "Plants, trees, outdoor tidy.",
    duties: [
      { name: "Water trees / plants", frequency: "Weekly" },
      { name: "Trash (to container)", frequency: "Weekly" },
    ],
  },
];

async function archiveAllDuties() {
  const { pages } = await notion.queryDatabase(notion.DB.RECURRING);
  let n = 0;
  for (const page of pages) {
    await notion.updatePage(page.id, { archived: true });
    n++;
  }
  console.log(`  Archived ${n} old Recurring Duties page(s)`);
}

async function ensureJobsSchema(jobsDb) {
  const patch = {};
  if (!jobsDb.properties.Assignee) {
    // Prefer renaming Primary people → Assignee if present
    if (jobsDb.properties["Primary people"]) {
      patch["Primary people"] = { name: "Assignee" };
    } else {
      patch.Assignee = { rich_text: {} };
    }
  }
  if (!jobsDb.properties.Status) {
    patch.Status = {
      select: {
        options: [
          { name: "Active" },
          { name: "Paused" },
          { name: "Archived" },
        ],
      },
    };
  }
  if (!jobsDb.properties.Description) {
    patch.Description = { rich_text: {} };
  }
  if (!jobsDb.properties.Notes) {
    patch.Notes = { rich_text: {} };
  }

  if (Object.keys(patch).length) {
    await notion.notion("PATCH", `/databases/${jobsDb.id}`, { properties: patch });
    notion.clearDatabaseCache(notion.DB.JOBS);
    console.log(`  Jobs schema: ${Object.keys(patch).join(", ")}`);
  } else {
    console.log("  Jobs schema ok");
  }
}

async function ensureDutiesSchema(dutiesDb, jobsDb) {
  const patch = {};

  // Dual relation: duty.Job ↔ job.Duties
  if (!dutiesDb.properties.Job || dutiesDb.properties.Job.type !== "relation") {
    if (dutiesDb.properties.Job?.type === "rich_text") {
      patch.Job = null; // remove text field first, then add relation in second pass
    } else {
      patch.Job = {
        relation: {
          database_id: jobsDb.id,
          type: "dual_property",
          dual_property: { name: "Duties" },
        },
      };
    }
  }

  if (!dutiesDb.properties.Frequency) {
    patch.Frequency = {
      select: {
        options: [
          { name: "Daily" },
          { name: "Weekly" },
          { name: "Monthly" },
          { name: "Seasonal" },
        ],
      },
    };
  }
  if (!dutiesDb.properties.Status) {
    patch.Status = {
      select: {
        options: [
          { name: "Active" },
          { name: "Paused" },
          { name: "Done" },
        ],
      },
    };
  }
  if (!dutiesDb.properties["Next due date"]) {
    patch["Next due date"] = { date: {} };
  }
  if (!dutiesDb.properties["Last completed"]) {
    patch["Last completed"] = { date: {} };
  }
  if (!dutiesDb.properties.Notes) {
    patch.Notes = { rich_text: {} };
  }

  if (Object.keys(patch).length) {
    await notion.notion("PATCH", `/databases/${dutiesDb.id}`, { properties: patch });
    notion.clearDatabaseCache(notion.DB.RECURRING);
    notion.clearDatabaseCache(notion.DB.JOBS);
    console.log(`  Duties schema pass 1: ${Object.keys(patch).join(", ")}`);
  }

  // Second pass: add Job relation after removing text Job
  const fresh = await notion.resolveDatabase(notion.DB.RECURRING);
  const jobs = await notion.resolveDatabase(notion.DB.JOBS);
  if (!fresh.properties.Job || fresh.properties.Job.type !== "relation") {
    await notion.notion("PATCH", `/databases/${fresh.id}`, {
      properties: {
        Job: {
          relation: {
            database_id: jobs.id,
            type: "dual_property",
            dual_property: { name: "Duties" },
          },
        },
      },
    });
    notion.clearDatabaseCache(notion.DB.RECURRING);
    notion.clearDatabaseCache(notion.DB.JOBS);
    console.log("  Duties schema: Job ↔ Duties relation");
  } else {
    console.log("  Duties Job relation ok");
  }
}

async function stripExtraColumns(dbName, keep) {
  const db = await notion.resolveDatabase(dbName);
  const patch = {};
  for (const name of Object.keys(db.properties)) {
    if (keep.has(name)) continue;
    if (db.properties[name].type === "title") continue;
    // Never strip relation props (Notion may auto-name dual sides)
    if (db.properties[name].type === "relation") continue;
    patch[name] = null;
  }
  const keys = Object.keys(patch);
  if (!keys.length) {
    console.log(`  ${dbName}: no extra columns`);
    return;
  }
  for (let i = 0; i < keys.length; i += 8) {
    const chunk = {};
    for (const k of keys.slice(i, i + 8)) chunk[k] = null;
    await notion.notion("PATCH", `/databases/${db.id}`, { properties: chunk });
  }
  notion.clearDatabaseCache(dbName);
  console.log(`  ${dbName}: removed ${keys.join(", ")}`);
}

async function seedExamples() {
  const { pages: existingJobs } = await notion.queryDatabase(notion.DB.JOBS);
  const byName = new Map(existingJobs.map((p) => [notion.pageTitle(p), p]));

  for (const job of EXAMPLE_JOBS) {
    let jobPage = byName.get(job.name);
    if (!jobPage) {
      jobPage = await notion.createPage({
        databaseName: notion.DB.JOBS,
        title: job.name,
        properties: {
          Status: "Active",
          Assignee: job.assignee || "",
          Description: job.description,
        },
      });
      console.log(`  Job → ${job.name}`);
    } else {
      await notion.updatePageProps(notion.DB.JOBS, jobPage.id, {
        Status: "Active",
        Description: job.description,
      });
      console.log(`  Job exists → ${job.name}`);
    }

    const jobId = jobPage.id || jobPage; // createPage returns {id}, existing is full page
    const id = typeof jobId === "string" ? jobId : jobPage.id;

    for (const duty of job.duties) {
      const created = await notion.createPage({
        databaseName: notion.DB.RECURRING,
        title: duty.name,
        properties: {
          Frequency: duty.frequency,
          Status: "Active",
          Notes: `Part of job: ${job.name}`,
        },
      });
      await notion.updatePage(created.id, {
        properties: {
          Job: { relation: [{ id }] },
        },
      });
      console.log(`    Duty → ${duty.name} (${duty.frequency})`);
    }
  }
}

async function updateWorkCallout() {
  const workId = notion.loadCatalog().pages?.Work;
  if (!workId) return;
  const blocks = await notion.getBlockChildren(workId);
  const callout = blocks.find((b) => b.type === "callout");
  if (!callout) return;
  await notion.notion("PATCH", `/blocks/${callout.id}`, {
    callout: {
      rich_text: notion.richText(
        "Jobs = roles (Cleaner…) with Assignee (name). Open a job to see its Recurring Duties (toilets daily, fridge monthly…). Tasks are separate one-off work."
      ),
    },
  });
  console.log("  Work callout updated");
}

async function main() {
  notion.assertKey();
  console.log("Restructuring Jobs + Recurring Duties…\n");

  console.log("=== Clear old duties ===");
  await archiveAllDuties();

  console.log("\n=== Schema ===");
  let jobsDb = await notion.resolveDatabase(notion.DB.JOBS);
  await ensureJobsSchema(jobsDb);
  jobsDb = await notion.resolveDatabase(notion.DB.JOBS);

  let dutiesDb = await notion.resolveDatabase(notion.DB.RECURRING);
  await ensureDutiesSchema(dutiesDb, jobsDb);

  await stripExtraColumns(notion.DB.JOBS, JOBS_KEEP);
  // Duties keep set after relation exists
  await stripExtraColumns(notion.DB.RECURRING, DUTIES_KEEP);

  console.log("\n=== Seed example jobs + duties ===");
  await seedExamples();

  await updateWorkCallout();

  jobsDb = await notion.resolveDatabase(notion.DB.JOBS);
  dutiesDb = await notion.resolveDatabase(notion.DB.RECURRING);
  console.log("\nJobs props:", Object.keys(jobsDb.properties).sort().join(", "));
  console.log("Duties props:", Object.keys(dutiesDb.properties).sort().join(", "));
  console.log("\nDONE.");
  console.log(jobsDb.url);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
