/**
 * Community message actions and Notion routing for Vila Akoya.
 * The AI picks ONE primary action per message; the bot executes it on Notion when applicable.
 * Exception: create_task / guest_issue may return multiple tasks in one message.
 * create_recurring_duty may return multiple duties in recurring_duties[].
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
  "question_my_day",
  "create_task",
  "create_recurring_duty",
  "guest_issue",
  "create_event",
  "complete_task",
  "cancel_event",
  "create_decision",
  "create_documentation",
  "rsvp_event",
  "create_reminder",
  "update_reminder",
  "add_note",
  "offer_help",
  "help_wanted",
  "availability",
  "announcement",
  "book_meal",
  "offer_community_meal",
  "childcare_absent",
  "assign_kids_programme",
  "create_stay",
  "create_expense",
  "question_expenses",
  "question_location",
  "update_location",
  "question_faq",
  "create_faq",
];

const DECISION_CATEGORIES = [
  "Governance",
  "Money / budget",
  "Land use",
  "Community rules",
  "Process",
  "Other",
];

const TASK_TYPES = ["Villager", "Management"];
const TASK_PRIORITIES = ["Low", "Average", "High"];
const DUTY_FREQUENCIES = ["Daily", "Weekly", "Monthly", "Seasonal"];
const EXPENSE_CATEGORIES = [
  "Food / groceries",
  "Hardware",
  "Fuel",
  "Transport",
  "Utilities",
  "Maintenance",
  "Supplies",
  "Guest / campsite",
  "Other",
];
const EXPENSE_PURPOSES = [
  "Breakfast",
  "Bar",
  "Dinner",
  "Kids Program",
  "General",
];
const EXPENSE_CURRENCIES = ["EUR", "GBP", "USD", "Other"];
const LOCATION_ITEM_KINDS = [
  "Keys",
  "Tool",
  "Supplies",
  "Vehicle",
  "Document",
  "Furniture",
  "Other",
];
const LOCATION_UPDATE_KINDS = ["current", "usual", "both", "missing"];

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
  create_recurring_duty: "duty_logged",
  assign_kids_programme: "announcement",
  guest_issue: "guest_logged",
  create_decision: "decision_logged",
  create_documentation: "doc_logged",
  update_location: "location_logged",
  create_faq: "faq_logged",
  complete_task: "task_completed",
  cancel_event: "event_cancelled",
  rsvp_event: "rsvp",
  create_stay: "stay_logged",
  create_reminder: "reminder_logged",
  update_reminder: "reminder_logged",
  add_note: "note_added",
  offer_help: "offer_help",
  help_wanted: "help_wanted",
  availability: "availability",
  announcement: "announcement",
};

const NOTION_DATABASES = [
  "Tasks",
  "Jobs",
  "Internal Events",
  "External Events",
  "Reminders",
  "Projects",
  "Partnerships",
  "Members",
  "Documentation",
  "Expenses",
  "Where things are",
  "FAQs",
  "Guest Stays",
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
    "Messages may be English, Portuguese, Spanish, French, or mixed. Typos, slang, and half-finished sentences are common.",
    "Interpret MEANING and intent — never require specific keywords, commands, or perfect spelling.",
    "Paraphrases count. 'do I have anything today?' = question_my_day. 'have you seen the keys?' = question_location.",
    "'that's sorted' / 'wifi working again' = complete_task. 'keeping Maya home' = childcare_absent.",
    "'can I eat with you tonight?' = book_meal. 'what have I spent?' = question_expenses.",
    "Use recent conversation context when the latest message is short or refers to something above.",
    "Always fill task.type and task.priority yourself when creating a task — infer them; don't leave them empty.",
    "If someone is clearly chatting, greeting, or thanking you: action=chat. If they want help but the action is fuzzy,",
    "still pick the most likely action with moderate confidence rather than insisting on a stock phrase.",
    "",
    "In groups the bot only acts when @tagged or replied-to. Ambient messages are still classified",
    "but the bot gates execution. In private DMs the bot always responds.",
    "",
    "Set actionable=false ONLY for pure chit-chat (thanks, lol, emoji, banter with no community info).",
    "",
    "═══ CRITICAL: lookup vs propose ═══",
    "LOOKUP = speaker wants to READ what is already on the calendar/rota/locations (→ question_schedule, question_doc, or question_location).",
    "PROPOSE = speaker suggests or announces something NEW to log (→ create_event, create_task, etc.).",
    "",
    "question_schedule examples (LOOKUP — do NOT create_event):",
    '  EN: "is there a market today?", "market at São Martinho today?", "when is the Milfontes market?", "what\'s the rating for Loulé market?"',
    '  EN market ratings (log scores, not schedule lookup): "Loulé: 8", "Barão de S. João: 3", "FATACIL: 9" → action=create_market_rating or chat with rating payload; NOT question_schedule.',
    '  EN medical directory (lookup): "nearest hospital?", "GP near Ourique?", "paediatrician in Beja?", "SNS 24 number?" → chat (bot answers from Medical facilities in Notion).',
    '  EN medical reviews (log scores): "Hospital José Joaquim Fernandes: 8", "Centro de Saúde de Ourique: 9" → chat with rating payload; NOT question_schedule.',
    '  EN: "what\'s happening next week?", "whats happenign next week", "any events?", "who cleans toilets today?"',
    '  EN: "anyone checking in today?", "who is leaving tomorrow?", "how many for dinner?", "breakfast numbers?"',
    '  NOT book_meal: "how many for dinner?" is a lookup (question_schedule), not a booking request.',
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
    "If question mentions discount/partner/affiliate → question_doc (will also check Partnerships DB).",
    "",
    "question_location examples (LOOKUP — where a portable item is, NOT how a system works):",
    '  EN: "where are the bathroom keys?", "where is the drill?", "where did someone leave the charger?"',
    '  EN: "where do we keep the first aid kit?", "where is everything?" (list the whole board)',
    '  PT: "onde estão as chaves das casas de banho?", "onde está o berbequim?"',
    "Portable / misplace-able objects (keys, tools, chargers, ladders) → question_location + location_lookup=true.",
    "Fixtures and systems (water shutoff, valve, wifi router as procedure) → question_doc, not question_location.",
    "",
    "question_faq examples (LOOKUP — short factual village FAQs in the FAQs database):",
    '  EN: "what is the address for deliveries?", "delivery address?", "where do couriers go?"',
    '  PT: "qual é a morada para entregas?", "morada de entrega?"',
    "Set faq_lookup=true. Populate faq.question with the topic if obvious.",
    "NOT question_doc when the answer is a one-line fact stored as FAQ (address, phone, gate code).",
    "",
    "create_faq examples (WRITE — add or update a short Q&A row):",
    '  EN: "Add FAQ: What is the delivery address? → Prado, GPS …"',
    '  EN: "FAQ: where is reception? — in the main building by the gate"',
    '  PT: "nova FAQ: morada entregas → …"',
    "Populate faq.question and faq.answer. faq.category optional (Deliveries, Campsite / guest, Village, Admin, Other).",
    "",
    "update_location examples (WRITE — speaker says where something is or lives):",
    '  EN: "I left the bathroom keys on the hook by reception"',
    '  EN: "the drill is in the workshop cupboard", "the drill lives in the workshop"',
    '  EN: "I can\'t find the bathroom keys" → update_location with update_kind=missing',
    '  PT: "deixei o berbequim na cozinha", "as chaves ficam no gancho da recepção"',
    "NOT create_task: reporting where an object is (or that it is missing) is update_location, not a repair job.",
    "",
    "Pick ONE primary action (except create_task/guest_issue may list several tasks):",
    "",
    "── Questions (answer only, do NOT create Notion items) ──",
    "- question_doc: how systems work, procedures, what equipment uses (water, wifi, compost, electric, etc.)",
    "    Also handles partnership/discount questions. NOT “where did someone leave the keys/drill”.",
    "- question_location: where a portable item currently is, or where it normally lives.",
    "    Set location_lookup=true. Populate location.item (the object). Set location.list_all=true for a full list.",
    "    EN: \"where are the keys?\", \"where is the drill?\", \"where do we keep the ladder?\"",
    "    PT: \"onde estão as chaves?\", \"onde está o berbequim?\"",
    "- question_faq: short factual answers from the FAQs table (delivery address, reception phone, etc.).",
    "    Set faq_lookup=true. Populate faq.question when the topic is clear.",
    "- question_my_day: speaker asks what THEY personally should do today / their tasks & duties",
    '    EN: "what should I do today?", "what\'s on my list?", "do I have anything today?", "anything for me?",',
    '         "what\'s on for me?", "my jobs", "what have I got today?"',
    '    PT: "o que tenho de fazer hoje?", "quais são as minhas tarefas?", "tenho alguma coisa hoje?"',
    "    Use this when the speaker refers to themselves (I/me/my), NOT the whole village schedule.",
    "- question_schedule: lookup rota, chores, events, campsite check-ins/outs, meal headcounts, childcare rosters, or kids programme shifts (Coddiwomplers, Early Readers, Arete, HSH, who is teaching) already in Notion",
    "    Set schedule_lookup=true and schedule_range: today | this_week | next_week | two_weeks",
    "    Pick the range that matches what the speaker asked (use WEEK CALENDAR above).",
    "    Guest arrival/departure, breakfast/dinner cook counts, and childcare allergy/phone/roster questions → question_schedule (NOT guest_issue).",
    "    Kids programme / teacher schedule / “who is on Early Readers Thursday?” → question_schedule.",
    "    Whole-group “what’s happening / who’s on rota” → question_schedule (not question_my_day).",
    "",
    "── Notion actions (create or update databases) ──",
    `- create_event: new gathering, trip, meal, delivery, or open invite with a timeframe.`,
    `    event.event_type: ${eventTypes.join(", ")}.`,
    `    event.scope: "internal" for on-site workshops/gatherings/community meals, "external" for markets/trips/outings.`,
    `    event.recurrence: null for one-offs, OR a rule string when it repeats:`,
    `      weekly:Sun | weekly:Sat,Sun | monthly:1st-Sat | monthly:3rd-Sun | monthly:last-Fri | daily`,
    `    Recurring examples:`,
    `      EN: "yoga every Sunday" → weekly:Sun`,
    `      EN: "market first Saturday of the month" → monthly:1st-Sat`,
    `      EN: "community lunch 3rd Sunday" → monthly:3rd-Sun`,
    `      PT: "almoço no terceiro domingo do mês" → monthly:3rd-Sun`,
    `    For recurring events set event.recurrence and set event.date to the next occurrence from today.`,
    "- create_task: ONE-OFF repair, broken, leak, smell, blocked, not working, or a single cleanup job.",
    `    task.type: Villager (village work anyone can do) | Management (needs a professional or arranging).`,
    "    All one-off tasks go to the Tasks database (no separate maintenance/guest DBs).",
    "    NOT create_task when the job REPEATS on a schedule — use create_recurring_duty instead.",
    "- create_recurring_duty: a duty that REPEATS (daily, weekly, monthly, seasonal) and belongs on the rota.",
    `    recurring_duty.frequency: ${DUTY_FREQUENCIES.join(" | ")} (default Daily).`,
    "    recurring_duty.assignee: villager name (e.g. B, Sam, Moon) — who currently holds the job role.",
    "    recurring_duty.job: job ROLE name (e.g. Cleaner, Kitchen) — all duties link here; assignee lives on the Job.",
    "    When speaker lists a full rota for a job role, set the SAME job on every duty and the current assignee.",
    "    Assignee is on the Job (not each duty) — reassigning the job later updates who sees all its duties.",
    "    Goes to Recurring Duties (not Tasks). The job assignee sees duties in /mytasks + morning DM when due.",
    '    EN: "clean every day", "B cleans daily", "weekly fridge clean for Moon", "assign cleaning to B every day"',
    '    PT: "limpar todos os dias", "B limpa diariamente", "tarefa diária de limpeza para B"',
    "    STRUCTURED ROTA: speakers often send sections like “every day” / “once a week” with bullet lists.",
    '    EN example: "every day: toilets, kitchen, reception. once a week: shared house, deep clean bathroom.',
    '      Cleaner job role, B is doing it" →',
    '      recurring_duties: [{title:"Sanitary and toilet",frequency:"Daily",job:"Cleaner",assignee:"B"},',
    '        {title:"Kitchen",frequency:"Daily",job:"Cleaner",assignee:"B"},',
    '        {title:"Shared house",frequency:"Weekly",job:"Cleaner",assignee:"B"}, …]',
    "    Map section headers: every day/daily/diariamente → Daily; once a week/weekly/semanalmente → Weekly;",
    "    once a month/monthly/mensalmente → Monthly.",
    "    MULTIPLE DUTIES: if the speaker lists several rota jobs (daily toilets, weekly fridge, monthly deep clean…),",
    "    set action=create_recurring_duty and return ALL of them in recurring_duties[] — one object per duty",
    "    with its own title/frequency/assignee. Use the same job role (e.g. Cleaner) for all items in a roster.",
    "    Also set recurring_duty to the first item (compat). Never collapse a list into one title.",
    '    Example: "my cleaning rota: toilets daily, fridge weekly, windows monthly" →',
    '      recurring_duties: [{title:"Clean toilets",frequency:"Daily"},{title:"Clean fridge",frequency:"Weekly"},…]',
    "    NOT create_recurring_duty for one-off jobs (create_task) or calendar gatherings (create_event).",
    '    If the task belongs to a project, set task.project to the project name (e.g. "swimming pool", "shaded seating").',
    '    Examples: "add a task to the pool project — buy tiles", "for the seating project we need to order wood".',
    "    MULTIPLE TASKS: if the speaker lists several jobs (often a voice note: “assign X to A, Y to B…”),",
    "    set action=create_task and return ALL of them in tasks[] — one object per job with its own title/assignee/type/priority/project.",
    "    Also set task to the first item (compat). Never drop later tasks; do not collapse them into one title.",
    '    Example: "assign Sammy create solar eclipse event, Moon order tiles, Lore fix tap" →',
    '      tasks: [{title, assignee:"Sammy"}, {title, assignee:"Moon"}, {title, assignee:"Lore"}]',
    "- guest_issue: camper/visitor/guest-facing (shower, pitch, booking, facilities) — also goes to Tasks with area noted",
    "    Same multi-task rule: use tasks[] when several guest issues are listed.",
    "- complete_task: reports a job is DONE or fixed — populate target.title",
    '    EN: "mark the wifi task done", "changing the Wi-Fi is done", "you can mark that as done",',
    '         "I\'ve done that — please mark it complete", "the leak is fixed"',
    '    PT: "marca a tarefa do wifi como feita", "já está feito"',
    "    Use recent context when speaker says \"mark it as done\" after naming the job above.",
    "- cancel_event: gathering called off — populate target.title (event name to match).",
    '    One event: "cancel yoga on Sunday", "the BBQ is off".',
    '    ALL matching events: "remove all djembe events", "no more djembe workshop" → target.title="djembe", target.cancel_all=true.',
    "    NOT delete from Notion — marks events cancelled/done.",
    "- update_reminder: change an existing Reminders row (usually Post to Telegram broadcasts).",
    '    EN: "morning meetings are only Monday to Friday, never weekends" → reminder.match_title="morning meeting",',
    '         reminder.recurrence="weekly:Mon,Tue,Wed,Thu,Fri".',
    '    EN: "change the parents lunch reminder to the 1st and 2nd only" → reminder.match_title + reminder.recurrence.',
    "    Set reminder.match_title to words from the reminder title/notes; reminder.recurrence when schedule changes.",
    "- create_decision: governance item needing discussion or vote",
    "- create_documentation: speaker wants to WRITE or RECORD knowledge for others — how a system works,",
    "    a procedure, troubleshooting guide, guest process, or steward notes (often a voice brain dump).",
    "    NOT a one-off repair job (create_task) and NOT a question (question_doc).",
    "- book_meal: speaker wants to sign up for communal weekday dinner (campsite meal booking on the website).",
    '    EN: "I want dinner tonight", "book me in for dinner", "can I have dinner?", "sign me up for dinner"',
    '    PT: "quero jantar", "quero jantar hoje", "posso jantar?", "marcar jantar"',
    '    NOT book_meal: headcount questions ("how many for dinner?"), organising a new meal/event (create_event),',
    '    or reporting a kitchen issue (create_task / guest_issue).',
    "- offer_community_meal: villager will COOK a one-off shared meal on a specific day (not recurring, not website dinner booking).",
    '    EN: "I\'m cooking a bolognese on Wednesday", "Hans is cooking lasagne today" → meal_offer.cook=Hans,',
    '    "making vegan curry tomorrow at 19:30".',
    '    PT: "vou fazer lasanha na quarta".',
    "    Set meal_offer.dish, meal_offer.date (YYYY-MM-DD or wednesday/today/tomorrow). Optional meal_offer.time (default 19:30),",
    "    meal_offer.diet (meat|veggie|vegan), meal_offer.price_eur (number, 0=free). No recurrence.",
    "    NOT create_event unless they are scheduling a generic gathering without cooking themselves.",
    "    NOT book_meal (that signs them up to eat campsite dinner).",
    "- create_stay: log a GUEST STAY (campsite pitch or accommodation) booked off-site — usually by email or phone,",
    "    not through the website. Voice notes of email bookings are common. One row per stay.",
    '    EN: "the Martins booked the cabin 20 to 24 Sept via email, two adults one child"',
    '    EN: "email booking: João arriving Friday leaving Monday, pitch 4"',
    '    EN: "add a campsite booking for the Silva family check-in 3 Oct check-out 7 Oct"',
    '    PT: "reserva por email: família Costa, bungalow, 12 a 15 de Outubro, 2 adultos"',
    "    Populate stay.guest_name, stay.check_in, stay.check_out (departure date), stay.kind",
    '    (Campsite | Accommodation), stay.unit (pitch/cabin name), stay.adults, stay.children,',
    '    stay.party (free text if easier), stay.source (Email | Phone | Telegram | Walk-in | Other).',
    "    If they list several stays in one message, return ALL in stays[] and set stay to the first.",
    "    NOT create_event (that is yoga/BBQ/workshops). NOT book_meal. NOT guest_issue.",
    "    Looking up who is already checking in → question_schedule, not create_stay.",
    "- childcare_absent: parent says their child/kid will not attend childcare today.",
    '    EN: "Sam won\'t attend childcare today", "my kid Maya is sick", "Luna won\'t come today"',
    '    PT: "o meu filho Sam não vai hoje", "a Maya está doente"',
    "    Populate childcare_absence.child_name when the child is named; date defaults to today unless explicit.",
    "- assign_kids_programme: assign or update who leads a kids programme slot (Kids Programme Shifts in Notion).",
    "    NOT question_schedule. Set schedule_lookup=false.",
    "    Programmes: Coddiwomplers, Early Readers, Arete, Home Schooling Hub, Parent / villager activity.",
    '    kids_programme_shift: { "programme", "date", "lead", "support", "status", "notes", "assign_as", "clear" }',
    '    EN: "put Amber on Early Readers Friday", "Moon covering Arete tomorrow", "Tamara support on HSH Monday",',
    '         "Coddiwomplers TBC on the 10th", "cancel parent activity today"',
    '    PT: "coloca a Amber nos Early Readers na sexta"',
    "- rsvp_event: speaker says they are coming to an event, RSVP, or \"I'm in\" for a known event.",
    '    Examples: "I\'m coming to the BBQ", "count me in for yoga", "eu vou ao almoço"',
    "    Populate rsvp.title with the event name to match.",
    "- create_reminder: personal deadline OR a scheduled community reminder posted on Telegram.",
    '    Personal: "remind me in 2 weeks to check the pump", "lembra-me em 3 dias" → reminder.broadcast=false.',
    '    Community broadcast: remind parents/a group on recurring calendar days, post to a Telegram group/topic.',
    '    Examples: "reminder on the 1st 2nd 3rd each month for parents to pay lunch, post on Parents and Teachers topic"',
    "    Set reminder.broadcast=true, reminder.recurrence (e.g. monthly:1,2,3), reminder.audiences as array",
    '    (\"Telegram chat\", \"Members by role\"), reminder.telegram_chat (e.g. \"Parents & Teachers\"),',
    "    reminder.topic_id if known, reminder.member_role (e.g. Parent) for DMs.",
    "    Populate reminder.title, reminder.notes (message body); due_date optional for one-shot only.",
    '    NOT add_note — \"add a reminder\" is create_reminder, not a note on another page.',
    '- add_note: speaker wants to append info to an existing task or project page.',
    '    Examples: "add to the swimming pool project: tiles arrived", "note on compost task: needs new bin"',
    "    Populate note.target_title and note.text.",
    "- create_expense: speaker is submitting a RECEIPT or expense for reimbursement / tax records.",
    "    Photo of a till receipt, factura, talão, invoice, OR pasted receipt text, OR “I spent €X at Y”.",
    '    EN: "here\'s my receipt", "expense 12.40 Continente", "I spent 8 euros on fuel", "reimburse me for this"',
    '    PT: "aqui está o recibo", "despesa 12,40", "gastei 8 euros em gasóleo", "reembolso"',
    "    Populate expense.title, expense.amount, expense.currency (EUR default), expense.date, expense.merchant, expense.category, expense.purpose, expense.notes.",
    "    expense.purpose is what the receipt is for, only if they said it: Breakfast, Bar, Dinner, Kids Program, General.",
    "    NOT create_expense for a repair job that happens to mention buying parts (create_task) unless they are clearly submitting the receipt.",
    "- update_location: speaker says WHERE they left something, where it currently is, or where it normally lives.",
    "    Goes to the Where things are database (not Tasks, not Documentation).",
    "    location.update_kind: current (I left it / last seen) | usual (where it lives) | both | missing.",
    "    location.item = object name (bathroom keys, drill). location.location = place string.",
    "    location.item_kind: Keys | Tool | Supplies | Vehicle | Document | Furniture | Other.",
    "    If they list several objects, set action=update_location and return ALL in locations[].",
    "    NOT create_task and NOT create_documentation.",
    "- create_faq: add or update a row in the FAQs database (question + short answer).",
    "    faq.question and faq.answer required. faq.aliases optional comma-separated phrases.",
    "    NOT create_documentation (that is long-form docs, not one-line FAQs).",
    "- question_expenses: speaker asks how much THEY or the village spent, who is owed, or to list receipts.",
    '    EN: "how much did I spend this month?", "who needs reimbursing?", "show receipts"',
    '    PT: "quanto gastei este mês?", "quem precisa de reembolso?"',
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
    `task.type: ${TASK_TYPES.join(", ")}`,
    `task.priority: ${TASK_PRIORITIES.join(", ")}`,
    `Notion databases: ${NOTION_DATABASES.join(", ")}`,
    "",
    "Return JSON:",
    '{ "actionable": bool, "action": string, "confidence": 0.0-1.0,',
    '  "schedule_lookup": bool,',
    '  "schedule_range": "today" | "this_week" | "next_week" | "two_weeks",',
    '  "doc_lookup": bool,',
    '  "location_lookup": bool,',
    '  "faq_lookup": bool,',
    '  "task": { "title", "type", "priority", "assignee", "project" } | null,',
    '  "tasks": [ { "title", "type", "priority", "assignee", "project" } ] | null,',
    '  "recurring_duty": { "title", "frequency", "assignee", "job" } | null,',
    '  "recurring_duties": [ { "title", "frequency", "assignee", "job" } ] | null,',
    '  "event": { "title", "date", "event_type", "scope", "recurrence" } | null,',
    '  "target": { "title", "item_id" } | null,',
    '  "decision": { "title", "category", "summary" } | null,',
    '  "documentation": { "title", "area", "summary" } | null,',
    '  "community": { "title", "summary", "date" } | null,',
    '  "meal_offer": { "dish", "date", "time", "diet", "price_eur", "cook" } | null,',
    '  "rsvp": { "title" } | null,',
    '  "reminder": { "title", "due_date", "notes", "broadcast", "recurrence", "audiences", "telegram_chat", "topic_id", "member_role" } | null,',
    '  "note": { "target_title", "text" } | null,',
    '  "expense": { "title", "amount", "currency", "date", "merchant", "category", "purpose", "notes" } | null,',
    '  "childcare_absence": { "child_name", "date" } | null,',
    '  "kids_programme_shift": { "programme", "date", "lead", "support", "status", "notes", "assign_as", "clear" } | null,',
    '  "stay": { "guest_name", "check_in", "check_out", "nights", "kind", "unit", "adults", "children", "party", "source", "notes" } | null,',
    '  "stays": [ { "guest_name", "check_in", "check_out", "nights", "kind", "unit", "adults", "children", "party", "source", "notes" } ] | null,',
    '  "location": { "item", "location", "usual_place", "update_kind", "item_kind", "area", "aliases", "notes", "list_all" } | null,',
    '  "locations": [ { "item", "location", "usual_place", "update_kind", "item_kind", "area", "aliases" } ] | null,',
    '  "faq": { "question", "answer", "category", "aliases", "list_all" } | null }',
    "",
    "For create_event always provide event.title; provide event.date for one-offs.",
    "For repeating events always provide event.recurrence (canonical form above) and event.date = next occurrence.",
    "Dates for \"next week\" must fall in the Next week range from WEEK CALENDAR.",
    "For question_schedule set schedule_lookup=true; never populate event for lookups.",
    "For question_doc set doc_lookup=true.",
    "For question_location set location_lookup=true and populate location.item (or list_all=true).",
    "For question_faq set faq_lookup=true and populate faq.question when possible.",
    "For create_faq populate faq.question and faq.answer.",
    "For update_location always populate location.item and location.location (except missing, where location may be empty).",
    "task.type: Villager = village work anyone can pitch in; Management = needs a professional or arranging.",
    "Tasks store: title, type, priority (Low/Average/High), assignee, created by, status, notes, optional project link.",
    "Recurring duties store: title, frequency, assignee, optional job role — shown daily in /mytasks + morning DM.",
    "When logging more than one recurring duty, recurring_duties[] is required and recurring_duty = recurring_duties[0].",
    "When logging more than one task, tasks[] is required (every job) and task should equal tasks[0].",
    "For create_stay always provide stay.guest_name and stay.check_in; stay.check_out is the leaving date.",
    "When logging more than one stay, stays[] is required and stay = stays[0].",
  ].join("\n");
}

function normalizeTaskFields(raw) {
  if (!raw || typeof raw !== "object") return null;
  const title = String(raw.title || "").trim().slice(0, 80);
  if (!title) return null;
  return {
    title,
    type: TASK_TYPES.includes(raw.type) ? raw.type : null,
    priority: TASK_PRIORITIES.includes(raw.priority) ? raw.priority : null,
    assignee: raw.assignee ? String(raw.assignee).slice(0, 80) : null,
    project: raw.project ? String(raw.project).slice(0, 120) : null,
  };
}

function normalizeDutyFrequency(raw) {
  const f = String(raw || "Daily").trim().toLowerCase();
  if (f === "weekly" || f.includes("week")) return "Weekly";
  if (f === "monthly" || f.includes("month")) return "Monthly";
  if (f === "seasonal") return "Seasonal";
  if (DUTY_FREQUENCIES.includes(raw)) return raw;
  return "Daily";
}

function normalizeKidsProgrammeShiftFields(raw) {
  if (!raw || typeof raw !== "object") return null;
  const programme = String(raw.programme || raw.program || raw.session || "").trim();
  if (!programme) return null;
  const date = raw.date ? String(raw.date).trim().slice(0, 32) : "";
  const lead = raw.lead ? String(raw.lead).trim().slice(0, 120) : "";
  const support = raw.support ? String(raw.support).trim().slice(0, 120) : "";
  const status = raw.status ? String(raw.status).trim().slice(0, 32) : "";
  const notes = raw.notes ? String(raw.notes).trim().slice(0, 500) : "";
  const assign_as = raw.assign_as ? String(raw.assign_as).trim().toLowerCase() : "";
  const clear = raw.clear === true || raw.cancelled === true;
  if (!lead && !support && !clear && !/tbc/i.test(status)) return null;
  return {
    programme,
    date,
    lead,
    support,
    status,
    notes,
    assign_as: assign_as === "support" ? "support" : "lead",
    clear,
  };
}

function normalizeRecurringDutyFields(raw) {
  if (!raw || typeof raw !== "object") return null;
  const title = String(raw.title || "").trim().slice(0, 80);
  if (!title) return null;
  return {
    title,
    frequency: normalizeDutyFrequency(raw.frequency),
    assignee: raw.assignee ? String(raw.assignee).slice(0, 80) : null,
    job: raw.job ? String(raw.job).slice(0, 80) : null,
  };
}

function normalizeExpenseFields(raw) {
  if (!raw || typeof raw !== "object") return null;
  const amountRaw = String(raw.amount ?? "").replace(",", ".");
  const amount = Number(amountRaw);
  const currency = String(raw.currency || "EUR").toUpperCase();
  return {
    title: String(raw.title || raw.merchant || "").trim().slice(0, 80) || null,
    amount: Number.isFinite(amount) ? amount : null,
    currency: EXPENSE_CURRENCIES.includes(currency) ? currency : "EUR",
    date: raw.date ? String(raw.date).slice(0, 10) : null,
    merchant: raw.merchant ? String(raw.merchant).slice(0, 80) : null,
    category: EXPENSE_CATEGORIES.includes(raw.category) ? raw.category : "Other",
    purpose:
      EXPENSE_PURPOSES.find(
        (p) => p.toLowerCase() === String(raw.purpose || "").trim().toLowerCase()
      ) || null,
    notes: raw.notes ? String(raw.notes).slice(0, 500) : null,
  };
}

function normalizeStayFields(raw) {
  if (!raw || typeof raw !== "object") return null;
  const guest = String(raw.guest_name || raw.name || raw.title || "").trim().slice(0, 80);
  const checkIn = String(raw.check_in || raw.date || "").slice(0, 10);
  if (!guest && !/^\d{4}-\d{2}-\d{2}$/.test(checkIn)) return null;
  let checkOut = String(raw.check_out || "").slice(0, 10);
  const nights = Number(raw.nights);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(checkOut) && /^\d{4}-\d{2}-\d{2}$/.test(checkIn) && nights > 0) {
    checkOut = addDaysISO(checkIn, nights);
  }
  const kind = ["Campsite", "Accommodation", "Other"].includes(raw.kind)
    ? raw.kind
    : null;
  const source = ["Email", "Phone", "Telegram", "Walk-in", "Other"].includes(raw.source)
    ? raw.source
    : null;
  const adults = Number(raw.adults);
  const children = Number(raw.children);
  return {
    guest_name: guest || "Guest",
    check_in: /^\d{4}-\d{2}-\d{2}$/.test(checkIn) ? checkIn : null,
    check_out: /^\d{4}-\d{2}-\d{2}$/.test(checkOut) ? checkOut : null,
    nights: Number.isFinite(nights) && nights > 0 ? nights : null,
    kind,
    unit: raw.unit ? String(raw.unit).slice(0, 80) : "",
    adults: Number.isFinite(adults) ? adults : null,
    children: Number.isFinite(children) ? children : null,
    party: raw.party ? String(raw.party).slice(0, 120) : "",
    source,
    notes: raw.notes ? String(raw.notes).slice(0, 800) : "",
  };
}

function normalizeLocationFields(raw, { allowEmptyItem = false } = {}) {
  if (!raw || typeof raw !== "object") return null;
  const item = String(raw.item || raw.title || "").trim().slice(0, 80);
  const listAll = raw.list_all === true;
  if (!item && !allowEmptyItem && !listAll) return null;
  const updateKind = LOCATION_UPDATE_KINDS.includes(raw.update_kind)
    ? raw.update_kind
    : raw.missing === true
      ? "missing"
      : "current";
  return {
    item,
    location: String(raw.location || "").trim().slice(0, 200),
    usual_place: raw.usual_place ? String(raw.usual_place).trim().slice(0, 200) : null,
    update_kind: updateKind,
    item_kind: LOCATION_ITEM_KINDS.includes(raw.item_kind) ? raw.item_kind : "Other",
    area: raw.area ? String(raw.area).slice(0, 80) : null,
    aliases: raw.aliases ? String(raw.aliases).slice(0, 200) : null,
    notes: raw.notes ? String(raw.notes).slice(0, 400) : null,
    list_all: listAll,
  };
}

const FAQ_CATEGORIES = [
  "Deliveries",
  "Campsite / guest",
  "Village",
  "Admin",
  "Other",
];

function normalizeFaqFields(raw, { allowEmptyQuestion = false } = {}) {
  if (!raw || typeof raw !== "object") return null;
  const question = String(raw.question || raw.title || "").trim().slice(0, 200);
  const listAll = raw.list_all === true;
  if (!question && !allowEmptyQuestion && !listAll) return null;
  return {
    question,
    answer: String(raw.answer || "").trim().slice(0, 1800),
    category: FAQ_CATEGORIES.includes(raw.category) ? raw.category : null,
    aliases: raw.aliases ? String(raw.aliases).slice(0, 500) : null,
    list_all: listAll,
  };
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
    rsvp: "rsvp_event",
    reminder: "create_reminder",
    update_reminder: "update_reminder",
    note: "add_note",
    book_dinner: "book_meal",
    community_meal: "offer_community_meal",
    cook_meal: "offer_community_meal",
    offer_meal: "offer_community_meal",
    recurring_duty: "create_recurring_duty",
    create_duty: "create_recurring_duty",
    expense: "create_expense",
    receipt: "create_expense",
    receipts: "question_expenses",
    child_absent: "childcare_absent",
    childcare_absence: "childcare_absent",
    kids_programme: "assign_kids_programme",
    kids_programme_shift: "assign_kids_programme",
    assign_kids_shift: "assign_kids_programme",
    stay: "create_stay",
    booking: "create_stay",
    create_booking: "create_stay",
    guest_stay: "create_stay",
    where_is: "question_location",
    location: "update_location",
    inventory: "update_location",
    faq: "create_faq",
    add_faq: "create_faq",
  };
  let action = ACTIONS.includes(legacyMap[rawAction] || rawAction)
    ? legacyMap[rawAction] || rawAction
    : "chat";
  const confidence = Number(result.confidence) || 0;

  if (result.doc_lookup === true && action !== "question_location" && action !== "question_faq") {
    action = "question_doc";
  }
  if (
    result.schedule_lookup === true &&
    action !== "question_my_day" &&
    action !== "book_meal" &&
    action !== "question_location" &&
    action !== "question_faq" &&
    action !== "assign_kids_programme"
  ) {
    action = "question_schedule";
  }
  // Prefer personal agenda when action is my_day (don't let schedule_lookup steal it)
  if (rawAction === "question_my_day" || result.my_day === true) {
    action = "question_my_day";
  }
  if (result.location_lookup === true || rawAction === "question_location") {
    action = "question_location";
  }
  if (result.faq_lookup === true || rawAction === "question_faq") {
    action = "question_faq";
  }
  if (rawAction === "update_location") {
    action = "update_location";
  }
  if (rawAction === "create_faq") {
    action = "create_faq";
  }
  if (rawAction === "offer_community_meal") {
    action = "offer_community_meal";
  }
  if (
    result.meal_offer &&
    typeof result.meal_offer === "object" &&
    result.meal_offer.dish &&
    action === "chat"
  ) {
    action = "offer_community_meal";
  }

  let tasks = [];
  if (["create_task", "guest_issue"].includes(action)) {
    const fromArray = Array.isArray(result.tasks)
      ? result.tasks.map(normalizeTaskFields).filter(Boolean)
      : [];
    const single = normalizeTaskFields(result.task);
    if (fromArray.length) {
      tasks = fromArray;
      // Ensure single task is included if model put it only in `task`
      if (single && !tasks.some((t) => t.title.toLowerCase() === single.title.toLowerCase())) {
        tasks.unshift(single);
      }
    } else if (single) {
      tasks = [single];
    }
  }
  const task = tasks[0] || null;

  let recurringDuties = [];
  if (action === "create_recurring_duty") {
    const fromArray = Array.isArray(result.recurring_duties)
      ? result.recurring_duties.map(normalizeRecurringDutyFields).filter(Boolean)
      : [];
    const single = normalizeRecurringDutyFields(result.recurring_duty);
    if (fromArray.length) {
      recurringDuties = fromArray;
      if (
        single &&
        !recurringDuties.some((d) => d.title.toLowerCase() === single.title.toLowerCase())
      ) {
        recurringDuties.unshift(single);
      }
    } else if (single) {
      recurringDuties = [single];
    }
  }
  const recurringDuty = recurringDuties[0] || null;

  let kidsProgrammeShift = null;
  if (action === "assign_kids_programme") {
    kidsProgrammeShift = normalizeKidsProgrammeShiftFields(
      result.kids_programme_shift || result.kids_programme || result.shift
    );
    if (!kidsProgrammeShift) {
      action = "chat";
    }
  }

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
      scope: eventRaw.scope === "internal" ? "internal" : "external",
      recurrence: recurrenceInfo.canonical || null,
      recurrence_label: recurrenceInfo.label || null,
    };
  }

  const target =
    result.target && ["complete_task", "cancel_event"].includes(action)
      ? {
          title: String(result.target.title || "").slice(0, 80),
          item_id: result.target.item_id ? String(result.target.item_id).replace(/\D/g, "") : null,
          cancel_all: Boolean(result.target.cancel_all),
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

  const rsvp =
    result.rsvp && action === "rsvp_event"
      ? { title: String(result.rsvp.title || "").slice(0, 80) }
      : null;

  const communityCook = require("./community-cook-offer");
  const meal_offer =
    result.meal_offer && action === "offer_community_meal"
      ? communityCook.normalizeMealOffer(result.meal_offer, {
          text: "",
          todayISO: todayISOForParse(),
          tz: DEFAULT_TZ,
        })
      : null;

  const reminderActions = ["create_reminder", "update_reminder"];
  const reminder =
    result.reminder && reminderActions.includes(action)
      ? {
          title: String(result.reminder.title || "").slice(0, 80),
          match_title: String(result.reminder.match_title || result.reminder.title || "").slice(
            0,
            80
          ),
          due_date: result.reminder.due_date ? String(result.reminder.due_date).slice(0, 10) : null,
          notes: String(result.reminder.notes || ""),
          broadcast: Boolean(result.reminder.broadcast),
          recurrence: result.reminder.recurrence
            ? String(result.reminder.recurrence).slice(0, 80)
            : null,
          audiences: Array.isArray(result.reminder.audiences)
            ? result.reminder.audiences.map((a) => String(a).slice(0, 60))
            : result.reminder.audience
              ? [String(result.reminder.audience)]
              : null,
          telegram_chat: result.reminder.telegram_chat
            ? String(result.reminder.telegram_chat).slice(0, 120)
            : null,
          topic_id: result.reminder.topic_id != null
            ? String(result.reminder.topic_id).slice(0, 20)
            : null,
          member_role: result.reminder.member_role
            ? String(result.reminder.member_role).slice(0, 80)
            : null,
        }
      : null;

  const note =
    result.note && action === "add_note"
      ? {
          target_title: String(result.note.target_title || "").slice(0, 80),
          text: String(result.note.text || ""),
        }
      : null;

  const expenseRaw = result.expense || result.receipt;
  const expense =
    action === "create_expense" ? normalizeExpenseFields(expenseRaw || {}) : null;

  const childcareAbsenceRaw = result.childcare_absence || {};
  const childcareAbsence =
    action === "childcare_absent"
      ? {
          child_name: String(childcareAbsenceRaw.child_name || "").trim().slice(0, 100),
          date: childcareAbsenceRaw.date
            ? String(childcareAbsenceRaw.date).slice(0, 10)
            : todayISOForParse(),
        }
      : null;

  let stayItems = [];
  if (action === "create_stay") {
    const fromArray = Array.isArray(result.stays)
      ? result.stays.map(normalizeStayFields).filter(Boolean)
      : [];
    const single = normalizeStayFields(result.stay);
    if (fromArray.length) {
      stayItems = fromArray;
      if (
        single &&
        !stayItems.some(
          (row) =>
            row.guest_name.toLowerCase() === single.guest_name.toLowerCase() &&
            row.check_in === single.check_in
        )
      ) {
        stayItems.unshift(single);
      }
    } else if (single) {
      stayItems = [single];
    }
  }
  const stay = stayItems[0] || null;

  let locationItems = [];
  if (action === "update_location") {
    const fromArray = Array.isArray(result.locations)
      ? result.locations.map((row) => normalizeLocationFields(row)).filter(Boolean)
      : [];
    const single = normalizeLocationFields(result.location);
    if (fromArray.length) {
      locationItems = fromArray;
      if (
        single &&
        !locationItems.some((row) => row.item.toLowerCase() === single.item.toLowerCase())
      ) {
        locationItems.unshift(single);
      }
    } else if (single) {
      locationItems = [single];
    }
  } else if (action === "question_location") {
    const single = normalizeLocationFields(result.location, { allowEmptyItem: true });
    if (single) locationItems = [single];
  }
  const location = locationItems[0] || null;

  let faq = null;
  if (action === "create_faq") {
    faq = normalizeFaqFields(result.faq);
  } else if (action === "question_faq") {
    faq = normalizeFaqFields(result.faq, { allowEmptyQuestion: true });
  }

  const scheduleDays = Number(result.schedule_days);
  const scheduleRange = normalizeScheduleRange(result);
  const isQuestion =
    action === "question_doc" ||
    action === "question_schedule" ||
    action === "question_my_day" ||
    action === "question_expenses" ||
    action === "question_location" ||
    action === "question_faq";

  return {
    actionable: result.actionable !== false && action !== "chat",
    action,
    confidence,
    is_question: isQuestion,
    is_task: (action === "create_task" || action === "guest_issue") && Boolean(task?.title),
    is_recurring_duty:
      action === "create_recurring_duty" && recurringDuties.length > 0,
    is_event: action === "create_event" && Boolean(event?.title),
    is_complete: action === "complete_task" && Boolean(target?.title || target?.item_id),
    is_cancel_event:
      action === "cancel_event" &&
      Boolean(target?.title || target?.item_id || target?.cancel_all),
    is_documentation: action === "create_documentation",
    is_rsvp: action === "rsvp_event" && Boolean(rsvp?.title),
    is_reminder: action === "create_reminder" && Boolean(reminder?.title),
    is_update_reminder:
      action === "update_reminder" &&
      Boolean(reminder?.match_title || reminder?.title || reminder?.recurrence),
    is_add_note: action === "add_note" && Boolean(note?.target_title),
    is_book_meal: action === "book_meal",
    is_community_meal_offer: action === "offer_community_meal",
    meal_offer,
    is_childcare_absence: action === "childcare_absent",
    is_kids_programme_assign:
      action === "assign_kids_programme" && Boolean(kidsProgrammeShift?.programme),
    is_stay: action === "create_stay" && stayItems.length > 0,
    is_expense: action === "create_expense",
    is_location_update: action === "update_location" && locationItems.length > 0,
    is_location_question: action === "question_location",
    is_faq_create: action === "create_faq" && Boolean(faq?.question && faq?.answer),
    is_faq_question: action === "question_faq",
    question_kind:
      action === "question_doc"
        ? "documentation"
        : action === "question_schedule"
          ? "schedule"
          : action === "question_my_day"
            ? "my_day"
            : action === "question_expenses"
              ? "expenses"
              : action === "question_location"
                ? "location"
                : action === "question_faq"
                  ? "faq"
                  : null,
    schedule_range: scheduleRange,
    schedule_days: scheduleDays > 0 && scheduleDays <= 30 ? Math.round(scheduleDays) : null,
    task,
    tasks,
    recurring_duty: recurringDuty,
    recurring_duties: recurringDuties,
    event,
    target,
    decision,
    documentation,
    community,
    rsvp,
    reminder,
    note,
    expense,
    childcare_absence: childcareAbsence,
    kids_programme_shift: kidsProgrammeShift,
    stay,
    stays: stayItems,
    location,
    locations: locationItems,
    faq,
  };
}

module.exports = {
  ACTIONS,
  DECISION_CATEGORIES,
  TASK_TYPES,
  TASK_PRIORITIES,
  DUTY_FREQUENCIES,
  MAINTENANCE_GROUP_BY_AREA,
  CHAT_ACTIVITY_KIND,
  EVENT_TYPES,
  MONDAY_BOARDS: NOTION_DATABASES,
  NOTION_DATABASES,
  SCHEDULE_RANGES,
  EXPENSE_CATEGORIES,
  EXPENSE_PURPOSES,
  EXPENSE_CURRENCIES,
  LOCATION_ITEM_KINDS,
  LOCATION_UPDATE_KINDS,
  buildWeekCalendarContext,
  resolveScheduleRange,
  buildClassifierPrompt,
  parseClassification,
};
