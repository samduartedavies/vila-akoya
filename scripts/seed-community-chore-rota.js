/**
 * Seed the community chore rota from the village whiteboard into monday.com.
 *
 * Creates a weekly TIMETABLE on "10 — Recurring Duties":
 *   - One row per chore, with Mon–Sun columns for who is assigned each day.
 *   - Removes old per-day items ("Breakfast — Monday", etc.) if present.
 *
 * Also seeds ad-hoc whiteboard to-dos on "00 — Task Inbox".
 *
 * Run: npm run monday:seed-rota
 */

"use strict";

const MONDAY_API_TOKEN =
  process.env.MONDAY_API_TOKEN ||
  "eyJhbGciOiJIUzI1NiJ9.eyJ0aWQiOjY3NzQ0MDk2NywiYWFpIjoxMSwidWlkIjoxMDcyNzM0NDIsImlhZCI6IjIwMjYtMDctMDFUMDk6NDU6NTYuMDAwWiIsInBlciI6Im1lOndyaXRlIiwiYWN0aWQiOjM1ODIxNzEyLCJyZ24iOiJldWMxIn0.fUMN4Zpvsm7hOx8xyo4Qb76Ovi_tDs-7nhhdu8ZIyEg";
const WORKSPACE_ID = process.env.MONDAY_WORKSPACE_ID || 6923231;
const MONDAY_API_VERSION = process.env.MONDAY_API_VERSION || "2026-10";
const PACING_MS = Number(process.env.PACING_MS || 800);

const API_URL = "https://api.monday.com/v2";

const DAY_KEYS = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
const DAY_LABELS = {
  Mon: "Monday",
  Tue: "Tuesday",
  Wed: "Wednesday",
  Thu: "Thursday",
  Fri: "Friday",
  Sat: "Saturday",
  Sun: "Sunday",
};

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

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

async function monday(query, variables = {}, attempt = 1) {
  const res = await fetch(API_URL, {
    method: "POST",
    headers: {
      Authorization: MONDAY_API_TOKEN,
      "Content-Type": "application/json",
      "API-Version": MONDAY_API_VERSION,
    },
    body: JSON.stringify({ query, variables }),
  });
  const json = await res.json();

  const complexityError = json.errors?.find(
    (e) => e.extensions?.code === "COMPLEXITY_BUDGET_EXHAUSTED"
  );
  if (complexityError && attempt <= 8) {
    const wait =
      Number(complexityError.extensions?.retry_in_seconds || 0) ||
      Math.min(60, attempt * 5);
    await sleep((wait + 2) * 1000);
    return monday(query, variables, attempt + 1);
  }
  if (json.errors) throw new Error(JSON.stringify(json.errors));
  await sleep(PACING_MS);
  return json.data;
}

async function getBoard(name) {
  const data = await monday(`
    query {
      boards(limit: 500, workspace_ids: [${WORKSPACE_ID}]) {
        id name state
        groups { id title deleted archived }
        columns { id title type archived }
      }
    }
  `);
  const board = (data.boards || []).find((b) => b.name === name && b.state !== "archived");
  if (!board) throw new Error(`Board not found: ${name}`);
  return board;
}

function col(board, title) {
  return board.columns.find((c) => c.title === title && !c.archived);
}

async function ensureGroup(board, groupName) {
  const existing = board.groups.find(
    (g) => g.title === groupName && !g.deleted && !g.archived
  );
  if (existing) return existing;

  const data = await monday(
    `mutation ($boardId: ID!, $name: String!) {
      create_group(board_id: $boardId, group_name: $name) { id title }
    }`,
    { boardId: String(board.id), name: groupName }
  );
  const group = data.create_group;
  board.groups.push(group);
  console.log(`  Created group: ${groupName}`);
  return group;
}

async function ensureColumn(board, title) {
  const existing = col(board, title);
  if (existing) return existing;

  const data = await monday(
    `mutation ($boardId: ID!, $title: String!) {
      create_column(board_id: $boardId, title: $title, column_type: text) { id title }
    }`,
    { boardId: String(board.id), title }
  );
  const column = data.create_column;
  board.columns.push(column);
  console.log(`  Created column: ${title}`);
  return column;
}

async function listItemsInGroup(boardId, groupId) {
  const data = await monday(
    `query ($boardId: ID!, $groupId: String!) {
      boards(ids: [$boardId]) {
        groups(ids: [$groupId]) {
          items_page(limit: 500) { items { id name } }
        }
      }
    }`,
    { boardId: String(boardId), groupId }
  );
  return data.boards?.[0]?.groups?.[0]?.items_page?.items || [];
}

async function upsertItem(boardId, groupId, name, columnValues, existingItems) {
  const existing = existingItems.find((i) => i.name === name);
  if (existing) {
    console.log(`  Reusing: ${name}`);
    if (Object.keys(columnValues).length) {
      await monday(
        `mutation ($boardId: ID!, $itemId: ID!, $vals: JSON!) {
          change_multiple_column_values(
            board_id: $boardId, item_id: $itemId, column_values: $vals, create_labels_if_missing: true
          ) { id }
        }`,
        {
          boardId: String(boardId),
          itemId: String(existing.id),
          vals: JSON.stringify(columnValues),
        }
      );
    }
    return existing;
  }

  const data = await monday(
    `mutation ($boardId: ID!, $groupId: String!, $name: String!) {
      create_item(board_id: $boardId, group_id: $groupId, item_name: $name) { id name }
    }`,
    { boardId: String(boardId), groupId, name }
  );
  const item = data.create_item;
  if (Object.keys(columnValues).length) {
    await monday(
      `mutation ($boardId: ID!, $itemId: ID!, $vals: JSON!) {
        change_multiple_column_values(
          board_id: $boardId, item_id: $itemId, column_values: $vals, create_labels_if_missing: true
        ) { id }
      }`,
      {
        boardId: String(boardId),
        itemId: String(item.id),
        vals: JSON.stringify(columnValues),
      }
    );
  }
  console.log(`  Created: ${item.name}`);
  existingItems.push(item);
  return item;
}

async function deleteItem(itemId) {
  await monday(
    `mutation ($itemId: ID!) { delete_item(item_id: $itemId) { id } }`,
    { itemId: String(itemId) }
  );
}

function isLegacyDayItem(name) {
  return DAY_KEYS.some((k) => name.includes(` — ${DAY_LABELS[k]}`));
}

async function seedRota() {
  console.log("\n=== Community chore rota (timetable) → 10 — Recurring Duties ===");
  const board = await getBoard("10 — Recurring Duties");
  const group = await ensureGroup(board, "Community chore rota");

  const dayCols = {};
  for (const day of DAY_KEYS) {
    dayCols[day] = await ensureColumn(board, day);
  }

  const existingItems = await listItemsInGroup(board.id, group.id);

  for (const item of existingItems) {
    if (isLegacyDayItem(item.name)) {
      console.log(`  Deleting legacy item: ${item.name}`);
      await deleteItem(item.id);
    }
  }

  const freshItems = (await listItemsInGroup(board.id, group.id)).filter(
    (i) => !isLegacyDayItem(i.name)
  );

  const statusCol = col(board, "Status");
  const freqCol = col(board, "Frequency");
  const areaCol = col(board, "Area");
  const notesCol = col(board, "Notes");

  for (const row of WEEKLY_ROTA) {
    const values = {};
    if (statusCol) values[statusCol.id] = { label: "Working on it" };
    if (freqCol) values[freqCol.id] = { label: "Weekly" };
    if (areaCol) values[areaCol.id] = { label: row.area };
    if (notesCol) {
      values[notesCol.id] = {
        text: "Weekly community chore rota (one row per chore, days in columns).",
      };
    }
    for (const day of DAY_KEYS) {
      if (dayCols[day]) values[dayCols[day].id] = row.days[day] || "";
    }
    await upsertItem(board.id, group.id, row.chore, values, freshItems);
  }
  console.log(`  ${WEEKLY_ROTA.length} timetable rows processed.`);
}

async function seedAdHocTodos() {
  console.log("\n=== Whiteboard to-dos → 00 — Task Inbox ===");
  const board = await getBoard("00 — Task Inbox");
  const group = await ensureGroup(board, "Community to-dos");
  const existingItems = await listItemsInGroup(board.id, group.id);

  const statusCol = col(board, "Status");
  const areaCol = col(board, "Area");
  const notesCol = col(board, "Notes");

  for (const task of ADHOC_TODOS) {
    const values = {};
    if (statusCol) values[statusCol.id] = { label: "Working on it" };
    if (areaCol) values[areaCol.id] = { label: "Buildings / structures" };
    if (notesCol) values[notesCol.id] = { text: "From community whiteboard TO DO list." };
    await upsertItem(board.id, group.id, task, values, existingItems);
  }
}

async function main() {
  if (!MONDAY_API_TOKEN) throw new Error("Missing MONDAY_API_TOKEN");
  console.log("Seeding Vila Akoya community chore rota on monday.com...");
  await seedRota();
  await seedAdHocTodos();
  console.log("\nDONE.");
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
