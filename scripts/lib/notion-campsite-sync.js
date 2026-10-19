/**
 * Sync campsite bookings, meal headcounts, and childcare rosters to Notion calendar databases.
 */

"use strict";

const campsiteOps = require("./campsite-ops");
const notion = require("./notion");

const BOOKINGS_DB = process.env.CAMPSITE_BOOKINGS_DB || notion.DB.BOOKINGS;
const MEALS_DB = process.env.CAMPSITE_MEALS_DB || notion.DB.MEALS;
const CHILDCARE_DB = process.env.CHILDCARE_DB || notion.DB.CHILDCARE;

function mealDetailText(bucket) {
  if (!bucket?.parties?.length) return "";
  return bucket.parties
    .map((p) => {
      const cook = (p.adults || 0) + (p.children || 0);
      return `${p.name} · ${cook} to cook`;
    })
    .join("\n");
}

function mealItemName(day) {
  const b = day.breakfast?.cook || 0;
  const d = day.dinner?.cook || 0;
  const label = day.label || day.ymd;
  return `${label} — breakfast ${b} · dinner ${d}`;
}

function childcareRosterText(day) {
  const lines = [];
  for (const child of day.children || []) {
    const bits = [
      `${child.child_name || ""}, age ${child.child_age || 0}`,
      `parent ${child.parent_name || ""}`,
      `phone ${child.parent_phone || ""}`,
    ];
    if (child.allergies) bits.push(`allergies/medical: ${child.allergies}`);
    lines.push(bits.filter(Boolean).join(" · "));
  }
  for (const child of day.absent_children || []) {
    lines.push(`ABSENT: ${child.child_name || ""}, age ${child.child_age || 0} · parent ${child.parent_name || ""} · phone ${child.parent_phone || ""}`);
  }
  return lines.join("\n");
}

function childcareItemName(day) {
  const label = day.label || day.ymd;
  return `${label} — childcare ${day.booked_count || 0} / ${day.capacity || 0}`;
}

function stayDayItemName(day) {
  const label = day.label || day.ymd;
  const arriving =
    countLines(day.arrivals_detail) || Number(day.arriving) || 0;
  const departing =
    countLines(day.departures_detail) || Number(day.departing) || 0;
  return `${label} — ${arriving} in · ${departing} out`;
}

function countLines(text) {
  return String(text || "")
    .split("\n")
    .map((s) => s.trim())
    .filter(Boolean).length;
}

async function loadByDayId(databaseName) {
  const { pages } = await notion.queryDatabase(databaseName);
  const map = new Map();
  for (const page of pages) {
    const dayId = notion.pagePropText(page, "Day ID") || notion.pageDateISO(page, "Date");
    if (dayId) map.set(String(dayId), page);
  }
  return map;
}

async function upsertStayDay(day, byDayId) {
  const dayId = String(day.ymd);
  const existing = byDayId.get(dayId);
  const name = stayDayItemName(day);
  const props = {
    "Day ID": dayId,
    Date: day.ymd,
    "Checking in": String(day.arrivals_detail || ""),
    "Checking out": String(day.departures_detail || ""),
    Staying: String(day.staying_detail || ""),
  };

  if (existing) {
    const db = await notion.resolveDatabase(BOOKINGS_DB);
    const titleKey = notion.titlePropName(db);
    await notion.updatePage(existing.id, {
      properties: {
        ...notion.buildProps(db, props),
        [titleKey]: notion.titleValue(name),
      },
    });
    return { action: "updated", id: existing.id };
  }

  const created = await notion.createPage({
    databaseName: BOOKINGS_DB,
    title: name,
    properties: props,
  });
  byDayId.set(dayId, { id: created.id, name });
  return { action: "created", id: created.id };
}

async function upsertMealDay(day, byDayId) {
  const dayId = String(day.ymd);
  const existing = byDayId.get(dayId);
  const name = mealItemName(day);
  const props = {
    "Day ID": dayId,
    Date: day.ymd,
    "Breakfast cooks": day.breakfast?.cook || 0,
    "Dinner cooks": day.dinner?.cook || 0,
    "Breakfast detail": mealDetailText(day.breakfast),
    "Dinner detail": mealDetailText(day.dinner),
    "Weekday dinner?": Boolean(day.is_weekday),
  };

  if (existing) {
    const db = await notion.resolveDatabase(MEALS_DB);
    const titleKey = notion.titlePropName(db);
    await notion.updatePage(existing.id, {
      properties: {
        ...notion.buildProps(db, props),
        [titleKey]: notion.titleValue(name),
      },
    });
    return { action: "updated", id: existing.id };
  }

  const created = await notion.createPage({
    databaseName: MEALS_DB,
    title: name,
    properties: props,
  });
  byDayId.set(dayId, { id: created.id, name });
  return { action: "created", id: created.id };
}

async function upsertChildcareDay(day, byDayId) {
  const dayId = String(day.ymd);
  const existing = byDayId.get(dayId);
  const name = childcareItemName(day);
  const props = {
    "Day ID": dayId,
    Date: day.ymd,
    Headcount: day.booked_count || 0,
    Capacity: day.capacity || 0,
    Roster: childcareRosterText(day),
    Absent: day.absent_count || 0,
    "Open?": Boolean(day.open),
  };

  if (existing) {
    const db = await notion.resolveDatabase(CHILDCARE_DB);
    const titleKey = notion.titlePropName(db);
    await notion.updatePage(existing.id, {
      properties: {
        ...notion.buildProps(db, props),
        [titleKey]: notion.titleValue(name),
      },
    });
    return { action: "updated", id: existing.id };
  }

  const created = await notion.createPage({
    databaseName: CHILDCARE_DB,
    title: name,
    properties: props,
  });
  byDayId.set(dayId, { id: created.id, name });
  return { action: "created", id: created.id };
}

async function deleteEmptyStayDay(ymd, byDayId) {
  const existing = byDayId.get(String(ymd));
  if (!existing) return;
  await notion.updatePage(existing.id, { archived: true });
  byDayId.delete(String(ymd));
}

/**
 * @param {object} [opts]
 */
async function syncCampsiteToNotion(opts = {}) {
  const log = opts.log || console.log;
  const tz = process.env.RECAP_TZ || "Europe/Lisbon";
  const today = campsiteOps.todayISO(tz);

  const bookingPast =
    opts.bookingPastDays ?? Number(process.env.CAMPSITE_SYNC_BOOKING_PAST_DAYS || 14);
  const bookingFuture =
    opts.bookingFutureDays ??
    Number(process.env.CAMPSITE_SYNC_BOOKING_FUTURE_DAYS || 120);
  const mealPast =
    opts.mealPastDays ?? Number(process.env.CAMPSITE_SYNC_MEAL_PAST_DAYS || 7);
  const mealFuture =
    opts.mealFutureDays ?? Number(process.env.CAMPSITE_SYNC_MEAL_FUTURE_DAYS || 90);
  const childcarePast =
    opts.childcarePastDays ?? Number(process.env.CAMPSITE_SYNC_CHILDCARE_PAST_DAYS || 7);
  const childcareFuture =
    opts.childcareFutureDays ?? Number(process.env.CAMPSITE_SYNC_CHILDCARE_FUTURE_DAYS || 90);

  const bookingFrom = campsiteOps.addDaysISO(today, -bookingPast);
  const bookingTo = campsiteOps.addDaysISO(today, bookingFuture);
  const mealFrom = campsiteOps.addDaysISO(today, -mealPast);
  const mealTo = campsiteOps.addDaysISO(today, mealFuture);
  const childcareFrom = campsiteOps.addDaysISO(today, -childcarePast);
  const childcareTo = campsiteOps.addDaysISO(today, childcareFuture);

  log(`Syncing campsite ops → Notion (${today})`);
  log(`  Bookings ${bookingFrom} → ${bookingTo}`);
  log(`  Meals ${mealFrom} → ${mealTo}`);
  log(`  Childcare ${childcareFrom} → ${childcareTo}`);

  const [activityPayload, mealsPayload, childcarePayload] = await Promise.all([
    campsiteOps.fetchOpsActivity(bookingFrom, bookingTo),
    campsiteOps.fetchOpsMeals(mealFrom, mealTo),
    campsiteOps.fetchOpsChildcare(childcareFrom, childcareTo),
  ]);

  const stayByDay = await loadByDayId(BOOKINGS_DB);
  let bookingCreated = 0;
  let bookingUpdated = 0;
  let bookingSkipped = 0;

  for (const day of activityPayload.days || []) {
    const checkingIn = String(day.arrivals_detail || "").trim();
    const checkingOut = String(day.departures_detail || "").trim();
    const onSite = String(day.staying_detail || "").trim();
    if (!checkingIn && !checkingOut && !onSite) {
      await deleteEmptyStayDay(day.ymd, stayByDay).catch(() => {});
      bookingSkipped++;
      continue;
    }
    const result = await upsertStayDay(day, stayByDay);
    if (result.action === "created") bookingCreated++;
    else bookingUpdated++;
  }

  const mealByDay = await loadByDayId(MEALS_DB);
  let mealCreated = 0;
  let mealUpdated = 0;
  let mealSkipped = 0;

  for (const day of mealsPayload.days || []) {
    const cooks = (day.breakfast?.cook || 0) + (day.dinner?.cook || 0);
    if (cooks < 1) {
      mealSkipped++;
      continue;
    }
    const result = await upsertMealDay(day, mealByDay);
    if (result.action === "created") mealCreated++;
    else mealUpdated++;
  }

  const childcareByDay = await loadByDayId(CHILDCARE_DB);
  let childcareCreated = 0;
  let childcareUpdated = 0;
  let childcareSkipped = 0;

  for (const day of childcarePayload.days || []) {
    const count = (day.booked_count || 0) + (day.absent_count || 0);
    if (count < 1) {
      childcareSkipped++;
      continue;
    }
    const result = await upsertChildcareDay(day, childcareByDay);
    if (result.action === "created") childcareCreated++;
    else childcareUpdated++;
  }

  const summary = {
    date: today,
    bookings: {
      synced: bookingCreated + bookingUpdated,
      skippedEmpty: bookingSkipped,
      created: bookingCreated,
      updated: bookingUpdated,
      database: BOOKINGS_DB,
    },
    meals: {
      synced: mealCreated + mealUpdated,
      skippedEmpty: mealSkipped,
      created: mealCreated,
      updated: mealUpdated,
      database: MEALS_DB,
    },
    childcare: {
      synced: childcareCreated + childcareUpdated,
      skippedEmpty: childcareSkipped,
      created: childcareCreated,
      updated: childcareUpdated,
      database: CHILDCARE_DB,
    },
  };

  log(
    `Done — stay days ${summary.bookings.synced} (${bookingCreated} new, ${bookingUpdated} updated, ${bookingSkipped} empty skipped); ` +
      `meals ${summary.meals.synced} (${mealCreated} new, ${mealUpdated} updated, ${mealSkipped} empty skipped); ` +
      `childcare ${summary.childcare.synced} (${childcareCreated} new, ${childcareUpdated} updated, ${childcareSkipped} empty skipped)`
  );

  return summary;
}

module.exports = {
  syncCampsiteToNotion,
  BOOKINGS_DB,
  MEALS_DB,
  CHILDCARE_DB,
};
