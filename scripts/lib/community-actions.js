/**
 * Community message actions and Notion routing for Vila Akoya.
 * The AI picks ONE action per message; the bot executes it on Notion when applicable.
 */

"use strict";

const {
  normalizeRecurrenceField,
  nextOccurrence,
  DEFAULT_TZ,
} = require("./event-recurrence");

const EVENT_TYPES = [
  "Birthday",
  "Trip / outing",
  "Delivery / order",
  "Shared meal",
  "Work party",
  "Workshop",
  "Celebration",
  "Other",
];

const ACTIONS = [
  "chat",
  "question_doc",
  "question_schedule",
  "create_task",
  "guest_issue",
  "create_event",
  "complete_task",
  "cancel_event",
  "create_decision",
  "create_documentation",
  "offer_help",
  "help_wanted",
  "availability",
  "announcement",
];

const DECISION_CATEGORIES = [
  "Governance",
  "Money / budget",
  "Land use",
  "Community rules",
  "Process",
  "Other",
];

const MAINTENANCE_GROUP_BY_AREA = {
  Water: "Water",
  "Compost & toilets": "Compost & toilets",
  "Solar / electric": "Solar / electric",
  "Buildings / structures": "Buildings / structures",
  "Tools / workshop": "Tools / workshop",
  "Land / roads / drainage": "Land / roads / drainage",
  "Campsite / guest": "Pitch / camping area",
  Garden: "Land / roads / drainage",
  "Community / events": "Buildings / structures",
  "Admin / money": "Buildings / structures",
  Other: "Water",
};

const CHAT_ACTIVITY_KIND = {
  create_event: "event_logged",
  create_task: "task_logged",
  guest_issue: "guest_logged",
  create_decision: "decision_logged",
  create_documentation: "doc_logged",
  complete_task: "task_completed",
  cancel_event: "event_cancelled",
  offer_help: "offer_help",
  help_wanted: "help_wanted",
  availability: "availability",
  announcement: "announcement",
};

const NOTION_DATABASES = [
  "Tasks",
  "Recurring Duties",
  "Maintenance",
  "Guest Issues",
  "Projects",
  "Events",
  "Decisions",
  "Documentation",
];

const SCHEDULE_RANGES = ["today", "this_week", "next_week", "two_weeks"];

function todayISOForParse(tz = DEFAULT_TZ) {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: tz,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}

function addDaysISO(iso, days, tz = DEFAULT_TZ) {
  const [y, m, d] = iso.split("-").map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d + days, 12));
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: tz,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(dt);
}

function startOfWeekMonday(iso, tz = DEFAULT_TZ) {
  const short = new Intl.DateTimeFormat("en-US", {
    timeZone: tz,
    weekday: "short",
  }).format(new Date(iso + "T12:00:00"));
  const map = { Mon: 0, Tue: 1, Wed: 2, Thu: 3, Fri: 4, Sat: 5, Sun: 6 };
  return addDaysISO(iso, -(map[short] ?? 0), tz);
}

/** Explicit Mon–Sun ranges so the model never treats "this week" as "next 7 days". */
function buildWeekCalendarContext(todayISO, tz = DEFAULT_TZ) {
  const thisMonday = startOfWeekMonday(todayISO, tz);
  const thisSunday = addDaysISO(thisMonday, 6, tz);
  const nextMonday = addDaysISO(thisMonday, 7, tz);
  const nextSunday = addDaysISO(thisSunday, 7, tz);
  return [
    "WEEK CALENDAR (use these exact ranges):",
    `- This week = ${thisMonday} (Monday) through ${thisSunday} (Sunday)`,
    `- Next week = ${nextMonday} (Monday) through ${nextSunday} (Sunday) — starts the Monday after this Sunday`,
    'When someone says "this week" / "esta semana", they mean the first range only.',
    'When someone says "next week" / "próxima semana", they mean the second range only.',
    "Never interpret a week as rolling 7 days from today.",
  ].join("\n");
}

function resolveScheduleRange(range, todayISO, tz = DEFAULT_TZ) {
  const thisMonday = startOfWeekMonday(todayISO, tz);
  const thisSunday = addDaysISO(thisMonday, 6, tz);
  const nextMonday = addDaysISO(thisMonday, 7, tz);
  const nextSunday = addDaysISO(thisSunday, 7, tz);

  switch (range) {
    case "today":
      return { start: todayISO, end: todayISO, label: `Today (${todayISO})` };
    case "this_week":
      return {
        start: thisMonday,
        end: thisSunday,
        label: `This week (${thisMonday} to ${thisSunday}, Mon–Sun)`,
      };
    case "next_week":
      return {
        start: nextMonday,
        end: nextSunday,
        label: `Next week (${nextMonday} to ${nextSunday}, Mon–Sun)`,
      };
    case "two_weeks":
      return {
        start: thisMonday,
        end: nextSunday,
        label: `This and next week (${thisMonday} to ${nextSunday}, Mon–Sun)`,
      };
    default:
      return {
        start: thisMonday,
        end: nextSunday,
        label: `This and next week (${thisMonday} to ${nextSunday}, Mon–Sun)`,
      };
  }
}

function normalizeScheduleRange(result) {
  if (SCHEDULE_RANGES.includes(result.schedule_range)) return result.schedule_range;
  const d = Number(result.schedule_days);
  if (d <= 1) return "today";
  if (d <= 7) return "this_week";
  return "two_weeks";
}

function buildClassifierPrompt({ todayISO, areas, eventTypes, priorities }) {
  return [
    "You triage messages in Vila Akoya's live community Telegram (campsite + village in Portugal).",
    `Today is ${todayISO}. Resolve relative dates to ISO YYYY-MM-DD.`,
    "",
    buildWeekCalendarContext(todayISO),
    "",
    "Messages may be English, Portuguese, Spanish, French, or mixed. Typos and slang are common.",
    "Interpret MEANING and intent — never rely on specific keywords or perfect spelling.",
    "Use recent conversation context when the latest message is short or refers to something above.",
    "",
    "Set actionable=false ONLY for pure chit-chat (thanks, lol, emoji, banter with no community info).",
    "",
    "═══ CRITICAL: lookup vs propose ═══",
    "LOOKUP = speaker wants to READ what is already on the calendar/rota (→ question_schedule or question_doc).",
    "PROPOSE = speaker suggests or announces something NEW to log (→ create_event, create_task, etc.).",
    "",
    "question_schedule examples (LOOKUP — do NOT create_event):",
    '  EN: "what\'s happening next week?", "whats happenign next week", "any events?", "who cleans toilets today?"',
    '  EN: "anyone checking in today?", "who is leaving tomorrow?", "how many for dinner?", "breakfast numbers?"',
    '  PT: "o que há na próxima semana?", "que eventos temos?", "quem está de serviço hoje?"',
    '  PT: "alguém faz check-in hoje?", "quantos jantam hoje?"',
    '  ES: "qué pasa la semana que viene?", "qué eventos hay?"',
    "",
    "create_event examples (PROPOSE — log a new gathering):",
    '  EN: "who fancies the waterpark next week?", "BBQ Saturday", "we\'re going to the shop tomorrow"',
    '  PT: "quem quer ir ao parque aquático?", "vamos ao waterpark na quarta"',
    '  ES: "¿alguien quiere ir al parque acuático?"',
    "",
    "If the speaker asks what EXISTS → question_schedule + schedule_lookup=true.",
    "If the speaker invites or announces a NEW plan → create_event + schedule_lookup=false.",
    "",
    "question_doc examples (LOOKUP — answer from documentation, do NOT use chat):",
    '  EN: "what SIM does the wifi use?", "how does the water system work?", "what does the red valve do?"',
    '  PT: "que cartão SIM usa o wifi?", "como funciona o sistema de água?"',
    "Any @bot question about how something works, what something uses, or village/campsite procedures → question_doc + doc_lookup=true.",
    "",
    "Pick ONE primary action:",
    "",
    "── Questions (answer only, do NOT create Notion items) ──",
    "- question_doc: how systems work, procedures, what equipment uses (water, wifi, compost, electric, etc.)",
    "- question_schedule: lookup rota, chores, events, campsite check-ins/outs, or meal headcounts already in Notion",
    "    Set schedule_lookup=true and schedule_range: today | this_week | next_week | two_weeks",
    "    Pick the range that matches what the speaker asked (use WEEK CALENDAR above).",
    "    Guest arrival/departure and breakfast/dinner cook counts → question_schedule (NOT guest_issue).",
    "",
    "── Notion actions (create or update databases) ──",
    `- create_event: new gathering, trip, meal, delivery, or open invite with a timeframe.`,
    `    event.event_type: ${eventTypes.join(", ")}.`,
    `    event.recurrence: null for one-offs, OR a rule string when it repeats:`,
    `      weekly:Sun | weekly:Sat,Sun | monthly:1st-Sat | monthly:3rd-Sun | monthly:last-Fri | daily`,
    `    Recurring examples:`,
    `      EN: "yoga every Sunday" → weekly:Sun`,
    `      EN: "market first Saturday of the month" → monthly:1st-Sat`,
    `      EN: "community lunch 3rd Sunday" → monthly:3rd-Sun`,
    `      PT: "almoço no terceiro domingo do mês" → monthly:3rd-Sun`,
    `    For recurring events set event.recurrence and set event.date to the next occurrence from today.`,
    "- create_task: repair, broken, leak, smell, blocked, not working, cleanup job, safety issue",
    "- guest_issue: camper/visitor/guest-facing (shower, pitch, booking, facilities)",
    "- complete_task: reports a job is DONE or fixed — populate target.title",
    "- cancel_event: gathering called off — populate target.title",
    "- create_decision: governance item needing discussion or vote",
    "- create_documentation: speaker wants to WRITE or RECORD knowledge for others — how a system works,",
    "    a procedure, troubleshooting guide, guest process, or steward notes (often a voice brain dump).",
    "    NOT a one-off repair job (create_task) and NOT a question (question_doc).",
    "",
    "create_documentation examples:",
    '  EN: "document how the compost toilet works", "write up the wifi router setup",',
    '       "here\'s how the water shutoff valves work" (voice or text dump)',
    '  PT: "documenta como funciona o sistema de água", "escreve o procedimento do wifi"',
    "",
    "── Recap only (no Notion item) ──",
    "- offer_help, help_wanted, availability, announcement",
    "",
    "- chat: casual only when nothing else applies",
    "",
    `task.area: ${areas.join(", ")}`,
    `task.priority: ${priorities.join(", ")} (Urgent for safety/power/water emergencies)`,
    `decision.category: ${DECISION_CATEGORIES.join(", ")}`,
    `Notion databases: ${NOTION_DATABASES.join(", ")}`,
    "",
    "Return JSON:",
    '{ "actionable": bool, "action": string, "confidence": 0.0-1.0,',
    '  "schedule_lookup": bool,',
    '  "schedule_range": "today" | "this_week" | "next_week" | "two_weeks",',
    '  "doc_lookup": bool,',
    '  "task": { "title", "area", "priority", "safety_critical", "route_maintenance" } | null,',
    '  "event": { "title", "date", "event_type", "recurrence" } | null,',
    '  "target": { "title", "item_id" } | null,',
    '  "decision": { "title", "category", "summary" } | null,',
    '  "documentation": { "title", "area", "summary" } | null,',
    '  "community": { "title", "summary", "date" } | null }',
    "",
    "route_maintenance=true when task is infrastructure not admin.",
    "For create_event always provide event.title; provide event.date for one-offs.",
    "For repeating events always provide event.recurrence (canonical form above) and event.date = next occurrence.",
    "Dates for \"next week\" must fall in the Next week range from WEEK CALENDAR.",
    "For question_schedule set schedule_lookup=true; never populate event for lookups.",
    "For question_doc set doc_lookup=true.",
  ].join("\n");
}

function parseClassification(result, { areas, eventTypes, priorities }) {
  const rawAction = result.action;
  const legacyMap = {
    task: "create_task",
    event: "create_event",
    delivery: "create_event",
    proposal: "create_event",
    confirm: "create_event",
    decision: "create_decision",
    documentation: "create_documentation",
    write_documentation: "create_documentation",
    document: "create_documentation",
  };
  let action = ACTIONS.includes(legacyMap[rawAction] || rawAction)
    ? legacyMap[rawAction] || rawAction
    : "chat";
  const confidence = Number(result.confidence) || 0;

  if (result.schedule_lookup === true) {
    action = "question_schedule";
  }
  if (result.doc_lookup === true) {
    action = "question_doc";
  }

  const task =
    result.task && ["create_task", "guest_issue"].includes(action)
      ? {
          title: String(result.task.title || "").slice(0, 80),
          area: areas.includes(result.task.area) ? result.task.area : "Other",
          priority: priorities.includes(result.task.priority) ? result.task.priority : "Medium",
          safety_critical: Boolean(result.task.safety_critical),
          route_maintenance: Boolean(result.task.route_maintenance),
        }
      : null;

  const eventRaw = result.event || (action === "create_event" ? result.proposal : null);
  let event = null;
  if (eventRaw && action === "create_event") {
    const recurrenceInfo = normalizeRecurrenceField(eventRaw.recurrence || "");
    let date = eventRaw.date ? String(eventRaw.date).slice(0, 10) : null;
    if (recurrenceInfo.rule && (!date || !/^\d{4}-\d{2}-\d{2}$/.test(date))) {
      date = nextOccurrence(recurrenceInfo.rule, todayISOForParse(), DEFAULT_TZ);
    }
    event = {
      title: String(eventRaw.title || "").slice(0, 80),
      date,
      event_type: EVENT_TYPES.includes(eventRaw.event_type)
        ? eventRaw.event_type
        : eventRaw.event_type === "Delivery / order" || rawAction === "delivery"
          ? "Delivery / order"
          : "Other",
      recurrence: recurrenceInfo.canonical || null,
      recurrence_label: recurrenceInfo.label || null,
    };
  }

  const target =
    result.target && ["complete_task", "cancel_event"].includes(action)
      ? {
          title: String(result.target.title || "").slice(0, 80),
          item_id: result.target.item_id ? String(result.target.item_id).replace(/\D/g, "") : null,
        }
      : null;

  const decision =
    result.decision && action === "create_decision"
      ? {
          title: String(result.decision.title || "").slice(0, 80),
          category: DECISION_CATEGORIES.includes(result.decision.category)
            ? result.decision.category
            : "Other",
          summary: String(result.decision.summary || ""),
        }
      : null;

  const documentation =
    result.documentation && action === "create_documentation"
      ? {
          title: String(result.documentation.title || "").slice(0, 80),
          area: areas.includes(result.documentation.area)
            ? result.documentation.area
            : "Other",
          summary: String(result.documentation.summary || ""),
        }
      : null;

  const community =
    result.community &&
    ["offer_help", "help_wanted", "availability", "announcement"].includes(action)
      ? {
          title: String(result.community.title || "").slice(0, 80),
          summary: String(result.community.summary || ""),
          date: result.community.date ? String(result.community.date).slice(0, 10) : null,
        }
      : null;

  const scheduleDays = Number(result.schedule_days);
  const scheduleRange = normalizeScheduleRange(result);
  const isQuestion = action === "question_doc" || action === "question_schedule";

  return {
    actionable: result.actionable !== false && action !== "chat",
    action,
    confidence,
    is_question: isQuestion,
    is_task: (action === "create_task" || action === "guest_issue") && Boolean(task?.title),
    is_event: action === "create_event" && Boolean(event?.title),
    is_complete: action === "complete_task" && Boolean(target?.title || target?.item_id),
    is_cancel_event: action === "cancel_event" && Boolean(target?.title || target?.item_id),
    is_documentation: action === "create_documentation",
    question_kind:
      action === "question_doc" ? "documentation" : action === "question_schedule" ? "schedule" : null,
    schedule_range: scheduleRange,
    schedule_days: scheduleDays > 0 && scheduleDays <= 30 ? Math.round(scheduleDays) : null,
    task,
    event,
    target,
    decision,
    documentation,
    community,
  };
}

module.exports = {
  ACTIONS,
  DECISION_CATEGORIES,
  MAINTENANCE_GROUP_BY_AREA,
  CHAT_ACTIVITY_KIND,
  EVENT_TYPES,
  MONDAY_BOARDS: NOTION_DATABASES,
  NOTION_DATABASES,
  SCHEDULE_RANGES,
  buildWeekCalendarContext,
  resolveScheduleRange,
  buildClassifierPrompt,
  parseClassification,
};
