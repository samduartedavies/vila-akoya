"use strict";

/**
 * Sync campsite bookings and meal headcounts to monday.com boards.
 */

const campsiteOps = require("./campsite-ops");

const MONDAY_API_TOKEN =
  process.env.MONDAY_API_TOKEN ||
  // Same fallback as setup-vila-akoya-monday.js / telegram bot for local use.
  "eyJhbGciOiJIUzI1NiJ9.eyJ0aWQiOjY3NzQ0MDk2NywiYWFpIjoxMSwidWlkIjoxMDcyNzM0NDIsImlhZCI6IjIwMjYtMDctMDFUMDk6NDU6NTYuMDAwWiIsInBlciI6Im1lOndyaXRlIiwiYWN0aWQiOjM1ODIxNzEyLCJyZ24iOiJldWMxIn0.fUMN4Zpvsm7hOx8xyo4Qb76Ovi_tDs-7nhhdu8ZIyEg";
const MONDAY_API_VERSION = process.env.MONDAY_API_VERSION || "2026-10";
const MONDAY_API_URL = "https://api.monday.com/v2";
const PACING_MS = Number(process.env.PACING_MS || 400);

const BOOKINGS_BOARD_NAME =
  process.env.CAMPSITE_BOOKINGS_BOARD || "35 — Campsite Bookings";
const MEALS_BOARD_NAME = process.env.CAMPSITE_MEALS_BOARD || "36 — Campsite Meals";

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function monday(query, variables = {}, attempt = 1) {
  if (!MONDAY_API_TOKEN) {
    throw new Error("Missing MONDAY_API_TOKEN");
  }

  const res = await fetch(MONDAY_API_URL, {
    method: "POST",
    headers: {
      Authorization: MONDAY_API_TOKEN,
      "Content-Type": "application/json",
      "API-Version": MONDAY_API_VERSION,
    },
    body: JSON.stringify({ query, variables }),
  });

  const text = await res.text();
  let json;
  try {
    json = JSON.parse(text);
  } catch {
    throw new Error(
      `monday API returned non-JSON (HTTP ${res.status}): ${text.replace(/\s+/g, " ").slice(0, 180)}`
    );
  }

  const complexityError = json.errors?.find(
    (err) => err.extensions?.code === "COMPLEXITY_BUDGET_EXHAUSTED"
  );

  if (complexityError && attempt <= 8) {
    const retrySeconds =
      Number(complexityError.extensions?.retry_in_seconds || 0) ||
      Math.min(60, attempt * 5);
    await sleep((retrySeconds + 2) * 1000);
    return monday(query, variables, attempt + 1);
  }

  if (json.errors) {
    throw new Error("monday API error: " + JSON.stringify(json.errors));
  }
  if (!res.ok) {
    throw new Error(`monday HTTP ${res.status}`);
  }

  await sleep(PACING_MS);
  return json.data;
}

const boardCache = new Map();
const WORKSPACE_ID = process.env.MONDAY_WORKSPACE_ID || "6923231";

async function resolveBoard(boardName) {
  if (boardCache.has(boardName)) return boardCache.get(boardName);

  // Light name→id lookup first (full column payloads on every board trip complexity / HTML error pages).
  const list = await monday(
    `
      query ($workspaceIds: [ID!]) {
        boards(limit: 100, workspace_ids: $workspaceIds) {
          id
          name
          state
        }
      }
    `,
    { workspaceIds: [String(WORKSPACE_ID)] }
  );

  const match = (list.boards || []).find(
    (b) => b.state !== "archived" && b.name === boardName
  );
  if (!match) throw new Error(`Board not found: ${boardName}`);

  const detail = await monday(
    `
      query ($boardIds: [ID!]) {
        boards(ids: $boardIds) {
          id
          name
          state
          columns { id title type archived }
          groups { id title }
        }
      }
    `,
    { boardIds: [String(match.id)] }
  );

  const board = detail.boards?.[0];
  if (!board) throw new Error(`Board not found: ${boardName}`);
  boardCache.set(boardName, board);
  return board;
}

function columnId(board, title, type) {
  const col = board.columns.find(
    (c) => c.title === title && (!type || c.type === type) && !c.archived
  );
  return col ? col.id : null;
}

function groupId(board, title) {
  const group = board.groups.find((g) => g.title === title);
  return group ? group.id : board.groups[0]?.id || null;
}

async function changeColumnValues(boardId, itemId, values) {
  await monday(
    `
      mutation ($boardId: ID!, $itemId: ID!, $vals: JSON!) {
        change_multiple_column_values(
          board_id: $boardId,
          item_id: $itemId,
          column_values: $vals,
          create_labels_if_missing: true
        ) { id }
      }
    `,
    { boardId: String(boardId), itemId: String(itemId), vals: JSON.stringify(values) }
  );
}

async function changeItemName(itemId, name) {
  await monday(
    `
      mutation ($itemId: ID!, $name: String!) {
        change_item_name(item_id: $itemId, name: $name) { id }
      }
    `,
    { itemId: String(itemId), name }
  );
}

async function moveItemToGroup(itemId, groupId) {
  await monday(
    `
      mutation ($itemId: ID!, $groupId: ID!) {
        move_item_to_group(item_id: $itemId, group_id: $groupId) { id }
      }
    `,
    { itemId: String(itemId), groupId: String(groupId) }
  );
}

async function fetchBoardItems(boardId) {
  const items = [];
  let cursor = null;
  for (;;) {
    const data = await monday(
      `
        query ($boardIds: [ID!], $cursor: String) {
          boards(ids: $boardIds) {
            items_page(limit: 100, cursor: $cursor) {
              cursor
              items {
                id
                name
                group { id title }
                column_values { id text value type }
              }
            }
          }
        }
      `,
      { boardIds: [String(boardId)], cursor }
    );
    const page = data.boards?.[0]?.items_page;
    if (!page) break;
    items.push(...(page.items || []));
    if (!page.cursor) break;
    cursor = page.cursor;
  }
  return items;
}

function columnTextByTitle(item, board, title) {
  const colId = columnId(board, title);
  if (!colId) return "";
  const cell = item.column_values?.find((c) => c.id === colId);
  return cell?.text || "";
}

function dateValue(ymd) {
  return { date: ymd };
}

function timelineValue(from, to) {
  return { from, to };
}

function mealDetailText(bucket) {
  if (!bucket?.parties?.length) return "";
  return bucket.parties
    .map((p) => {
      const cook = (p.adults || 0) + (p.children || 0);
      return `${p.name} · ${p.plot || "?"} · ${cook} to cook`;
    })
    .join("\n");
}

function mealItemName(day) {
  const b = day.breakfast?.cook || 0;
  const d = day.dinner?.cook || 0;
  const label = day.label || day.ymd;
  return `${label} — breakfast ${b} · dinner ${d}`;
}

function stayDayItemName(day) {
  const label = day.label || day.ymd;
  const arriving = countLines(day.arrivals_detail) || Number(day.arriving) || 0;
  const departing = countLines(day.departures_detail) || Number(day.departing) || 0;
  return `${label} — ${arriving} in · ${departing} out`;
}

function countLines(text) {
  return String(text || "")
    .split("\n")
    .map((s) => s.trim())
    .filter(Boolean).length;
}

async function upsertStayDayItem(board, day, itemByDayId) {
  const dayId = String(day.ymd);
  const existing = itemByDayId.get(dayId);
  const itemName = stayDayItemName(day);

  const values = {};
  const set = (title, type, val) => {
    const id = columnId(board, title, type);
    if (id && val !== undefined && val !== null) values[id] = val;
  };

  const checkingIn = String(day.arrivals_detail || "");
  const checkingOut = String(day.departures_detail || "");
  const onSite = String(day.staying_detail || "");

  set("Day ID", "text", dayId);
  set("Date", "date", dateValue(day.ymd));
  set("Checking in", "long_text", { text: checkingIn });
  set("Checking out", "long_text", { text: checkingOut });
  set("Staying", "long_text", { text: onSite });
  // Back-compat with earlier day-centric column titles.
  set("Arrivals detail", "long_text", { text: checkingIn });
  set("Departures detail", "long_text", { text: checkingOut });
  set("On site detail", "long_text", { text: onSite });

  const targetGroupId =
    groupId(board, "Stay days") ||
    groupId(board, "Meal days") ||
    groupId(board, "Group Title");

  if (existing) {
    if (existing.name !== itemName) {
      await changeItemName(existing.id, itemName);
    }
    await changeColumnValues(board.id, existing.id, values);
    return { action: "updated", id: existing.id };
  }

  const created = await monday(
    `
      mutation ($boardId: ID!, $groupId: String!, $name: String!) {
        create_item(board_id: $boardId, group_id: $groupId, item_name: $name) { id }
      }
    `,
    {
      boardId: String(board.id),
      groupId: String(targetGroupId),
      name: itemName,
    }
  );
  const itemId = created.create_item.id;
  await changeColumnValues(board.id, itemId, values);
  itemByDayId.set(dayId, { id: itemId, name: itemName });
  return { action: "created", id: itemId };
}

async function upsertMealDayItem(board, day, itemByDayId) {
  const dayId = String(day.ymd);
  const existing = itemByDayId.get(dayId);
  const itemName = mealItemName(day);

  const values = {};
  const set = (title, type, val) => {
    const id = columnId(board, title, type);
    if (id && val !== undefined && val !== null) values[id] = val;
  };

  set("Day ID", "text", dayId);
  set("Date", "date", dateValue(day.ymd));
  set("Breakfast cooks", "numbers", String(day.breakfast?.cook || 0));
  set("Dinner cooks", "numbers", String(day.dinner?.cook || 0));
  set("Breakfast detail", "long_text", { text: mealDetailText(day.breakfast) });
  set("Dinner detail", "long_text", { text: mealDetailText(day.dinner) });
  set("Weekday dinner?", "checkbox", { checked: day.is_weekday ? "true" : "false" });

  const targetGroupId = groupId(board, "Meal days");

  if (existing) {
    if (existing.name !== itemName) {
      await changeItemName(existing.id, itemName);
    }
    await changeColumnValues(board.id, existing.id, values);
    return { action: "updated", id: existing.id };
  }

  const created = await monday(
    `
      mutation ($boardId: ID!, $groupId: String!, $name: String!) {
        create_item(board_id: $boardId, group_id: $groupId, item_name: $name) { id }
      }
    `,
    {
      boardId: String(board.id),
      groupId: String(targetGroupId),
      name: itemName,
    }
  );
  const itemId = created.create_item.id;
  await changeColumnValues(board.id, itemId, values);
  return { action: "created", id: itemId };
}

/**
 * @param {object} [opts]
 * @param {number} [opts.bookingPastDays]
 * @param {number} [opts.bookingFutureDays]
 * @param {number} [opts.mealPastDays]
 * @param {number} [opts.mealFutureDays]
 * @param {(msg: string) => void} [opts.log]
 */
async function syncCampsiteToMonday(opts = {}) {
  const log = opts.log || console.log;
  const tz = process.env.RECAP_TZ || "Europe/Lisbon";
  const today = campsiteOps.todayISO(tz);

  const bookingPast = opts.bookingPastDays ?? Number(process.env.CAMPSITE_SYNC_BOOKING_PAST_DAYS || 14);
  const bookingFuture =
    opts.bookingFutureDays ?? Number(process.env.CAMPSITE_SYNC_BOOKING_FUTURE_DAYS || 120);
  const mealPast = opts.mealPastDays ?? Number(process.env.CAMPSITE_SYNC_MEAL_PAST_DAYS || 7);
  const mealFuture = opts.mealFutureDays ?? Number(process.env.CAMPSITE_SYNC_MEAL_FUTURE_DAYS || 90);

  const bookingFrom = campsiteOps.addDaysISO(today, -bookingPast);
  const bookingTo = campsiteOps.addDaysISO(today, bookingFuture);
  const mealFrom = campsiteOps.addDaysISO(today, -mealPast);
  const mealTo = campsiteOps.addDaysISO(today, mealFuture);

  log(`Syncing campsite ops → Monday (${today})`);
  log(`  Bookings ${bookingFrom} → ${bookingTo}`);
  log(`  Meals ${mealFrom} → ${mealTo}`);

  const [activityPayload, mealsPayload] = await Promise.all([
    campsiteOps.fetchOpsActivity(bookingFrom, bookingTo),
    campsiteOps.fetchOpsMeals(mealFrom, mealTo),
  ]);

  const bookingsBoard = await resolveBoard(BOOKINGS_BOARD_NAME);
  const mealsBoard = await resolveBoard(MEALS_BOARD_NAME);

  const bookingItems = await fetchBoardItems(bookingsBoard.id);
  const stayItemByDayId = new Map();
  for (const item of bookingItems) {
    const dayId = columnTextByTitle(item, bookingsBoard, "Day ID");
    if (dayId) stayItemByDayId.set(String(dayId), item);
  }

  let bookingCreated = 0;
  let bookingUpdated = 0;
  let bookingSkipped = 0;
  for (const day of activityPayload.days || []) {
    const checkingIn = String(day.arrivals_detail || "").trim();
    const checkingOut = String(day.departures_detail || "").trim();
    const onSite = String(day.staying_detail || "").trim();
    if (!checkingIn && !checkingOut && !onSite) {
      bookingSkipped++;
      continue;
    }
    const result = await upsertStayDayItem(bookingsBoard, day, stayItemByDayId);
    if (result.action === "created") bookingCreated++;
    else bookingUpdated++;
  }

  const mealItems = await fetchBoardItems(mealsBoard.id);
  const itemByDayId = new Map();
  for (const item of mealItems) {
    const dayId = columnTextByTitle(item, mealsBoard, "Day ID");
    if (dayId) itemByDayId.set(String(dayId), item);
  }

  let mealCreated = 0;
  let mealUpdated = 0;
  let mealSkipped = 0;
  for (const day of mealsPayload.days || []) {
    const cooks =
      (day.breakfast?.cook || 0) + (day.dinner?.cook || 0);
    if (cooks < 1) {
      mealSkipped++;
      continue;
    }
    const result = await upsertMealDayItem(mealsBoard, day, itemByDayId);
    if (result.action === "created") mealCreated++;
    else mealUpdated++;
  }

  const summary = {
    date: today,
    bookings: {
      synced: bookingCreated + bookingUpdated,
      skippedEmpty: bookingSkipped,
      created: bookingCreated,
      updated: bookingUpdated,
      board: BOOKINGS_BOARD_NAME,
    },
    meals: {
      synced: mealCreated + mealUpdated,
      skippedEmpty: mealSkipped,
      created: mealCreated,
      updated: mealUpdated,
      board: MEALS_BOARD_NAME,
    },
  };

  log(
    `Done — stay days ${summary.bookings.synced} (${bookingCreated} new, ${bookingUpdated} updated, ${bookingSkipped} empty skipped); ` +
      `meals ${summary.meals.synced} (${mealCreated} new, ${mealUpdated} updated, ${mealSkipped} empty skipped)`
  );

  return summary;
}

module.exports = {
  syncCampsiteToMonday,
  BOOKINGS_BOARD_NAME,
  MEALS_BOARD_NAME,
};
