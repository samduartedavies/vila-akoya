/**
 * Seed Odemira regional markets into monday.com "80 — Events & Gatherings".
 *
 * From the community "Odemira Markets" poster: Nth weekday of the month rules.
 * Milfontes (Benfica) runs every Sunday → weekly:Sun.
 *
 * Run: npm run monday:seed-markets
 */

"use strict";

const {
  nextOccurrence,
  normalizeRecurrenceField,
} = require("./lib/event-recurrence");

const MONDAY_API_TOKEN =
  process.env.MONDAY_API_TOKEN ||
  "eyJhbGciOiJIUzI1NiJ9.eyJ0aWQiOjY3NzQ0MDk2NywiYWFpIjoxMSwidWlkIjoxMDcyNzM0NDIsImlhZCI6IjIwMjYtMDctMDFUMDk6NDU6NTYuMDAwWiIsInBlciI6Im1lOndyaXRlIiwiYWN0aWQiOjM1ODIxNzEyLCJyZ24iOiJldWMxIn0.fUMN4Zpvsm7hOx8xyo4Qb76Ovi_tDs-7nhhdu8ZIyEg";
const MONDAY_API_VERSION = process.env.MONDAY_API_VERSION || "2026-10";
const PACING_MS = Number(process.env.PACING_MS || 800);
const BOARD_NAME = process.env.EVENTS_BOARD_NAME || "80 — Events & Gatherings";
const GROUP_NAME = process.env.EVENT_RECURRING_GROUP || "Recurring";
const API_URL = "https://api.monday.com/v2";

/** One Monday item per market occurrence rule. */
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
  // Poster lists Milfontes under every Sunday slot → every Sunday.
  { place: "Milfontes (Benfica)", recurrence: "weekly:Sun" },
];

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function todayISO(tz = "Europe/Lisbon") {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: tz,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
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
  const complexity = json.errors?.find(
    (e) => e.extensions?.code === "COMPLEXITY_BUDGET_EXHAUSTED"
  );
  if (complexity) {
    const wait = Number(complexity.extensions?.retry_in_seconds || 5) + 2;
    await sleep(wait * 1000);
    if (attempt >= 10) throw new Error("monday complexity retry limit");
    return monday(query, variables, attempt + 1);
  }
  if (json.errors) {
    console.error(JSON.stringify(json.errors, null, 2));
    throw new Error("monday.com API request failed");
  }
  await sleep(PACING_MS);
  return json.data;
}

function col(board, title, type) {
  return board.columns.find((c) => c.title === title && (!type || c.type === type));
}

async function getBoard(name) {
  const data = await monday(`
    query {
      boards(limit: 500) {
        id name state
        columns { id title type }
        groups { id title }
      }
    }
  `);
  const board = (data.boards || []).find((b) => b.state !== "archived" && b.name === name);
  if (!board) throw new Error(`Board not found: ${name}`);
  return board;
}

async function ensureGroup(board, title) {
  const existing = board.groups.find((g) => g.title === title);
  if (existing) return existing;
  const data = await monday(
    `mutation ($boardId: ID!, $name: String!) {
      create_group(board_id: $boardId, group_name: $name) { id title }
    }`,
    { boardId: String(board.id), name: title }
  );
  board.groups.push(data.create_group);
  console.log(`  Created group: ${title}`);
  return data.create_group;
}

async function ensureTextColumn(board, title) {
  const existing = col(board, title, "text");
  if (existing) return existing;
  try {
    const data = await monday(
      `mutation ($boardId: ID!, $title: String!) {
        create_column(board_id: $boardId, title: $title, column_type: text) { id title type }
      }`,
      { boardId: String(board.id), title }
    );
    board.columns.push(data.create_column);
    console.log(`  Created column: ${title}`);
    return data.create_column;
  } catch (err) {
    console.warn(`  Could not create "${title}" column (${err.message}) — using Notes instead.`);
    return null;
  }
}

function recurrenceFromItem(item, columns) {
  const cv = Object.fromEntries(item.column_values.map((c) => [c.id, c.text || ""]));
  if (columns.recurrence) {
    const direct = (cv[columns.recurrence.id] || "").trim();
    if (direct) return direct;
  }
  if (columns.notes) {
    const notes = cv[columns.notes.id] || "";
    const marked = notes.match(/Recurrence:\s*([^\n(]+)/i);
    if (marked) return marked[1].trim();
    const canonical = notes.match(/\b((?:daily|weekly|monthly):[A-Za-z0-9,_-]+)\b/i);
    if (canonical) return canonical[1];
  }
  return "";
}

async function listItemsInGroup(boardId, groupId) {
  const items = [];
  let cursor = null;
  do {
    const data = await monday(
      `query ($boardId: ID!, $groupId: String!, $cursor: String) {
        boards(ids: [$boardId]) {
          groups(ids: [$groupId]) {
            items_page(limit: 100, cursor: $cursor) {
              cursor
              items { id name column_values { id text } }
            }
          }
        }
      }`,
      { boardId: String(boardId), groupId, cursor }
    );
    const page = data.boards?.[0]?.groups?.[0]?.items_page;
    items.push(...(page?.items || []));
    cursor = page?.cursor || null;
  } while (cursor);
  return items;
}

function marketItemName(place) {
  return `Market — ${place}`;
}

async function upsertMarket(board, group, market, existingItems, columns) {
  const info = normalizeRecurrenceField(market.recurrence);
  if (!info.rule) throw new Error(`Bad recurrence: ${market.recurrence}`);

  const name = marketItemName(market.place);
  const existing = existingItems.find((item) => {
    if (item.name !== name) return false;
    return recurrenceFromItem(item, columns) === info.canonical;
  });

  const next = nextOccurrence(info.rule, todayISO());
  const values = {};
  if (columns.date && next) values[columns.date.id] = { date: next };
  if (columns.recurrence) values[columns.recurrence.id] = info.canonical;
  if (columns.type) values[columns.type.id] = { label: "Other" };
  if (columns.announce) values[columns.announce.id] = { checked: "true" };
  if (columns.location) values[columns.location.id] = market.place;
  if (columns.notes) {
    values[columns.notes.id] = {
      text:
        `Odemira Markets poster.\n` +
        `Recurrence: ${info.canonical} (${info.label})\n` +
        `Next: ${next || "n/a"}`,
    };
  }

  if (existing) {
    await monday(
      `mutation ($boardId: ID!, $itemId: ID!, $vals: JSON!) {
        change_multiple_column_values(
          board_id: $boardId, item_id: $itemId, column_values: $vals, create_labels_if_missing: true
        ) { id }
      }`,
      { boardId: String(board.id), itemId: String(existing.id), vals: JSON.stringify(values) }
    );
    console.log(`  Updated: ${name} [${info.canonical}] → next ${next}`);
    return;
  }

  const created = await monday(
    `mutation ($boardId: ID!, $groupId: String!, $name: String!) {
      create_item(board_id: $boardId, group_id: $groupId, item_name: $name) { id }
    }`,
    { boardId: String(board.id), groupId: group.id, name }
  );
  await monday(
    `mutation ($boardId: ID!, $itemId: ID!, $vals: JSON!) {
      change_multiple_column_values(
        board_id: $boardId, item_id: $itemId, column_values: $vals, create_labels_if_missing: true
      ) { id }
    }`,
    {
      boardId: String(board.id),
      itemId: String(created.create_item.id),
      vals: JSON.stringify(values),
    }
  );
  console.log(`  Created: ${name} [${info.canonical}] → next ${next}`);
  existingItems.push({
    id: created.create_item.id,
    name,
    column_values: [
      ...(columns.recurrence
        ? [{ id: columns.recurrence.id, text: info.canonical }]
        : []),
      ...(columns.notes
        ? [
            {
              id: columns.notes.id,
              text: `Recurrence: ${info.canonical} (${info.label})`,
            },
          ]
        : []),
    ],
  });
}

async function main() {
  console.log("\n=== Odemira Markets → Events & Gatherings ===");
  const board = await getBoard(BOARD_NAME);
  const group = await ensureGroup(board, GROUP_NAME);
  const recurrenceCol = await ensureTextColumn(board, "Recurrence");

  const columns = {
    date: col(board, "Date", "date"),
    recurrence: recurrenceCol,
    type: col(board, "Event type", "status"),
    announce:
      col(board, "Announce?", "checkbox") ||
      col(board, "Announce on Telegram?", "checkbox"),
    location: col(board, "Location", "text"),
    notes: col(board, "Notes", "long_text"),
  };

  const existing = await listItemsInGroup(board.id, group.id);
  for (const market of MARKETS) {
    await upsertMarket(board, group, market, existing, columns);
  }
  console.log(`\nDone. ${MARKETS.length} market rules seeded.`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
