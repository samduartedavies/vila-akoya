/**
 * Import kids programme work-schedule CSV (Google Sheets export) into Notion.
 *
 *   node scripts/import-kids-programme-csv.js path/to/Work_schedule.csv
 *   IMPORT_YEAR=2026 node scripts/import-kids-programme-csv.js --apply path/to.csv
 *
 * Without --apply, prints a summary only (no Notion writes).
 */

"use strict";

const fs = require("fs");
const path = require("path");
const notion = require("./lib/notion");
const { PROGRAMME_TIMES, DB_NAME } = require("./lib/kids-programme-shifts");

const APPLY = process.argv.includes("--apply");
const csvArg = process.argv.find((a) => a.endsWith(".csv"));
const CSV_PATH =
  csvArg ||
  process.env.KIDS_PROGRAMME_CSV ||
  path.join(
    __dirname,
    "..",
    "data",
    "Work_schedule_Kids_programmes__-_KIDS.csv"
  );
const YEAR = Number(process.env.IMPORT_YEAR || new Date().getFullYear());

const MONTHS = { OCT: 10, NOV: 11, DEC: 12, JAN: 1, FEB: 2, MAR: 3 };

const PROGRAMME_PATTERNS = [
  { re: /coddiwompler/i, programme: "Coddiwomplers" },
  { re: /early\s*readers/i, programme: "Early Readers" },
  { re: /\barete\b/i, programme: "Arete" },
  { re: /home\s*schooling/i, programme: "Home Schooling Hub" },
  { re: /parent\s*\/\s*villager|parent\/villager/i, programme: "Parent / villager activity" },
  { re: /etta\s*\+\s*nova|on\s*holiday/i, programme: "Day note" },
];

function parseCsvLine(line) {
  const row = [];
  let cell = "";
  let quoted = false;
  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (ch === '"') {
      quoted = !quoted;
      continue;
    }
    if (ch === "," && !quoted) {
      row.push(cell);
      cell = "";
      continue;
    }
    cell += ch;
  }
  row.push(cell);
  return row;
}

function pad2(n) {
  return String(n).padStart(2, "0");
}

function detectProgramme(labelCell) {
  const text = String(labelCell || "").trim();
  if (!text) return null;
  for (const { re, programme } of PROGRAMME_PATTERNS) {
    if (re.test(text)) return { programme, raw: text };
  }
  return null;
}

function normalizeAssignee(raw) {
  let text = String(raw || "").trim();
  if (!text || text === "?") return { lead: "", status: "TBC", notes: "" };
  let status = "Confirmed";
  if (/\?$/.test(text) || text === "?") {
    status = "TBC";
    text = text.replace(/\?+$/, "").trim();
  }
  if (!text) return { lead: "", status: "TBC", notes: "" };
  return { lead: text, status, notes: "" };
}

function buildColumnDates(monthRow, dayRow, year) {
  let monthKey = "OCT";
  const out = new Map();
  for (let i = 0; i < dayRow.length; i++) {
    const mCell = String(monthRow[i] || "").trim();
    if (/^NOV/i.test(mCell)) monthKey = "NOV";
    if (/^DEC/i.test(mCell)) monthKey = "DEC";
    if (/^JAN/i.test(mCell)) monthKey = "JAN";
    const dayNum = parseInt(String(dayRow[i] || "").trim(), 10);
    if (!dayNum) continue;
    const month = MONTHS[monthKey];
    if (!month) continue;
    let y = year;
    if (monthKey === "JAN" || monthKey === "FEB" || monthKey === "MAR") {
      y = year + 1;
    }
    out.set(i, `${y}-${pad2(month)}-${pad2(dayNum)}`);
  }
  return out;
}

function findProgrammeRows(lines) {
  const rows = [];
  for (let i = 0; i < lines.length; i++) {
    const row = parseCsvLine(lines[i]);
    const label = row[6] || row[5] || "";
    const hit = detectProgramme(label);
    if (hit) rows.push({ lineIndex: i, row, ...hit });
  }
  return rows;
}

async function main() {
  if (!fs.existsSync(CSV_PATH)) {
    console.error(`CSV not found: ${CSV_PATH}`);
    process.exit(1);
  }
  notion.assertKey();

  const lines = fs.readFileSync(CSV_PATH, "utf8").split(/\r?\n/);
  const monthRow = parseCsvLine(lines[8] || "");
  const dayRow = parseCsvLine(lines[10] || lines[9] || "");
  const colDates = buildColumnDates(monthRow, dayRow, YEAR);
  const programmeRows = findProgrammeRows(lines);

  const shifts = [];
  for (const pr of programmeRows) {
    for (const [col, dateISO] of colDates.entries()) {
      const cell = String(pr.row[col] || "").trim();
      if (!cell) continue;
      if (pr.programme === "Day note") {
        shifts.push({
          dateISO,
          programme: "Day note",
          lead: "",
          support: "",
          status: "Confirmed",
          notes: cell,
          title: `${dateISO} · Note`,
        });
        continue;
      }
      const { lead, status, notes } = normalizeAssignee(cell);
      const title = `${dateISO} · ${pr.programme}${lead ? ` · ${lead}` : " · TBC"}`;
      shifts.push({
        dateISO,
        programme: pr.programme,
        lead,
        support: "",
        status,
        notes: notes || (cell !== lead ? cell : ""),
        title: title.slice(0, 200),
        time: PROGRAMME_TIMES[pr.programme] || "",
      });
    }
  }

  console.log(`CSV: ${CSV_PATH}`);
  console.log(`Shifts parsed: ${shifts.length} (${programmeRows.length} programme rows)`);
  if (!APPLY) {
    console.log("\nDry run — pass --apply to write to Notion.");
    console.log("Sample:", shifts.slice(0, 5));
    return;
  }

  let created = 0;
  let skipped = 0;
  for (const s of shifts) {
    try {
      const { pages } = await notion.queryDatabase(DB_NAME);
      const exists = pages.some(
        (p) =>
          notion.pageDateISO(p, "Date") === s.dateISO &&
          notion.pagePropText(p, "Programme") === s.programme &&
          notion.pagePropText(p, "Lead") === s.lead
      );
      if (exists) {
        skipped += 1;
        continue;
      }
      await notion.createPage({
        databaseName: DB_NAME,
        title: s.title,
        properties: {
          Date: s.dateISO,
          Programme: s.programme,
          Time: s.time || "",
          Lead: s.lead,
          Support: s.support,
          Status: s.status,
          Notes: s.notes,
          "Post to Telegram": true,
          Source: "Import",
        },
      });
      created += 1;
      if (created % 25 === 0) console.log(`  … ${created} created`);
    } catch (err) {
      console.warn(`Skip ${s.title}: ${err.message}`);
    }
  }
  console.log(`Done. Created ${created}, skipped ${skipped} (duplicates).`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
