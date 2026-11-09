# Telegram copy-paste

## Group / topic description (≤255 chars)

```
VAVA (@vila_akoya_bot) — village helper. Tag it or say “Vava” in a voice note to log tasks & events, ask where things are, or write docs. /register · /questionnaire · /mytasks · /help
```

## Pinned message (paste into the topic)

```
VAVA — village bot (@vila_akoya_bot)

Say it once in Telegram → it goes on the Notion board (tasks, events, docs).

HOW TO CALL THE BOT
• Tag @vila_akoya_bot or reply to its messages
• Voice notes: start with “Vava”, “Bot”, “Robot”, “Vila akoya bot”, or “Personal assistant”
• Or send the voice note, then @vila_akoya_bot right after
• Private DM to the bot: no tag needed

TASKS
@vila_akoya_bot the kitchen tap is dripping
@vila_akoya_bot add to the pool project: order tiles
/task <description>

Villager = anyone can help · Management = needs a pro / arranging

EVENTS
@vila_akoya_bot BBQ Saturday
@vila_akoya_bot yoga every Sunday

RECEIPTS (private Expenses board — not shown on the public site)
In a DM: send a photo or PDF — I'll assume it's a receipt, then ask which NIF (reply 1 or 2):
  1. ALDEIA ALCATEIA LDA 517908794
  2. POVOADO GENTIL LDA 519423178
Then what it’s for (reply 1–5):
  1. Breakfast  2. Bar  3. Dinner  4. Kids Program  5. General
Or add Alcateia / Povoado and breakfast / bar / dinner / kids / general in the caption. In the group: tag the bot or /receipt
/receipts — this month's totals by person, company, and what it’s for

EMAIL / OFF-SITE BOOKINGS
Voice note or text a stay booked by email or phone — it goes on Guest Stays in Notion (and morning check-ins):
@vila_akoya_bot the Martins booked the cabin 20–24 Sept, 2 adults 1 child, via email
@vila_akoya_bot email booking: João arriving Friday leaving Monday, pitch 4

ASK
@vila_akoya_bot how does the water system work?
@vila_akoya_bot what’s happening next week?
@vila_akoya_bot what should I do today?  (after /register Your Name)
/ask <question> · /mytasks

WHERE THINGS ARE
@vila_akoya_bot where are the bathroom keys?
@vila_akoya_bot I left the drill in the kitchen
@vila_akoya_bot the bathroom keys live on the hook by reception
Live list: Notion page “Where things are” (Documentation hub)

BINS FULL
@vila_akoya_bot the bins are full / as papeleiras estão cheias
VAVA asks which bins (paper, plastic, glass, general — or all), confirms, then emails the Câmara de Ourique to come and empty them (max once per 48h per bin).

MEDICAL & EMERGENCY (Baixo Alentejo directory in Notion)
@vila_akoya_bot nearest hospital? · GP near Ourique? · paediatrician in Beja?
@vila_akoya_bot SNS 24 number? · where is the Ourique health centre?
Life-threatening → 112. Advice/triage → SNS 24 (808 24 24 24). SUB units are not full hospitals.
Rate a clinic/hospital (1–10): <code>Hospital José Joaquim Fernandes: 8</code>
Setup: <code>npm run notion:setup-medical</code>

CHILDCARE
Book childcare on the website: /book-childcare/
Parents can DM or tag VAVA: “Sam won’t attend childcare today”
Child minders can DM VAVA: “what are today’s allergies?” or “what is Sam’s parent’s phone number?”
Private child details are only shared in DMs with people assigned to the Child minder Job in Notion.

KIDS PROGRAMME (teachers / caregivers)
Shifts live in Notion → Kids Programme Shifts (Coddiwomplers, Early Readers, Arete, HSH, parent activities).
Each morning: roundup in Parents & Teachers (topic) + DMs to registered leads for that day.
Assign: “put Amber on Early Readers Friday” or /kids Amber · Early Readers · Friday
Ask: “who’s on Early Readers tomorrow?” · See docs/kids-programme-schedule.md

COMMUNITY PROFILE
/register Your Name — join Members + short questionnaire (skills, areas, bio)
/questionnaire — update your Community profile anytime
Skillsets & help areas: reply with numbers (e.g. 1 3 5) or other: your skill

DOCS (voice dumps welcome)
Vava, document how the compost toilet works

ALSO
• “I’m coming to the BBQ” → RSVP
• “Remind me in 2 weeks to check the pump”
• “The wifi task is done” / /done <id>
• “Cancel yoga”
• /help for the full list

MORNING ROUNDUP (~08:00 Lisbon)
• Main community group: morning meeting theme (Mon–Fri), events, meals, childcare count, kids programme shifts, yesterday’s highlights — **no** campsite check-ins/outs
• **Vila Akoya Volunteers** group (optional): recap of what was said yesterday in that group
• **Vila Akoya Management** group (optional): today’s website check-ins, check-outs, pitch occupancy + other guest stays; problems reported in the last 24h (Guests + main group); recap of yesterday on Volunteers + Management groups
• **Vila Akoya Guests** group (optional, `GUESTS_GROUP_CHAT_ID`): what’s on today (events + nearby markets — birthdays, deliveries and work parties are left out)
• If `MANAGEMENT_GROUP_CHAT_ID` is unset, managers still get campsite info and problem alerts by DM (Role / focus = Manager)

NOTION BOARD UPDATES
• “🆕 New item”, completed, assigned and status-change posts go to the Management group only (the main group only gets them if no Management group is set)
• VAVA still confirms in the chat where you asked — e.g. tag it in the main group with a task and it replies there

PROBLEM ALERTS (instant, to Management)
• VAVA reads the main group and the Guests group; when someone reports something broken, missing, unsafe, or a complaint, Management gets an alert straight away with a link to the message
• The same issue isn’t re-alerted within 24h. Turn off with `PROBLEM_ALERTS=false`

GUESTS GROUP
• VAVA reads questions without a tag. If a **Guest friendly** FAQ answers it, VAVA replies in the group
• If it can’t answer, it stays silent in the guest chat and forwards the question to Management (no “I don’t know” message)
• When someone adds or updates an FAQ (any group), VAVA asks in that same chat: “Should guests be able to see this FAQ?” — reply **yes** / **yeah** / **no** / **não** and it ticks the box in Notion
• Bot privacy mode must be off (@BotFather → /setprivacy → Disable) or the bot must be an admin, so it can see untagged messages
• Personal duties + tasks: DM only (after /register)
• Child minder roster: DM only for registered Members assigned to the Child minder Job
• Kids programme leads: DM listing today’s sessions; full list in Parents & Teachers topic

SCHEDULED REMINDERS (Notion → Calendars → Reminders)
• Tick **Post to Telegram**, set **Notes** (message body), **Recurrence** (e.g. `monthly:1,2,3` for the 1st–3rd of each month), **Time** (e.g. `08:00`)
• **Audiences** (multi-select): pick any combination — e.g. **Telegram chat** + **Members by role** for group post and parent DMs on the same row. Legacy single **Audience** still works.
• **Telegram chat ID** / **Member role** as needed for those audiences (role matches **Role / focus** on Members; people need `/register`)
• Optional **Topic ID** for forum topics. Map friendly names with env `TELEGRAM_CHAT_ALIASES` JSON, e.g. `{"Parents & Teachers":"-100…"}`
• Get chat ids: `npm run telegram:chats`. After schema changes: `npm run notion:migrate-v2` (or restart the bot — it adds columns automatically).

EN + PT fine · Be specific for best results
```

## Childcare ops setup

- Website bookings are paid through WooCommerce on `/book-childcare/`.
- WordPress syncs daily childcare rosters to the Notion `Childcare` database.
- VAVA needs `VILA_OPS_API_TOKEN` as well as `WP_SITE_URL` to mark children absent from Telegram.
- Child minders must be registered Members (`/register Name`) and assigned in a Notion Job named `Child minder`.
