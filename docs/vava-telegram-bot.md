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
Then what it’s for (reply 1–4):
  1. Breakfast  2. Bar  3. Dinner  4. Kids Program
Or add Alcateia / Povoado and breakfast / bar / dinner / kids in the caption. In the group: tag the bot or /receipt
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
• Group topic: morning meeting theme (Mon general, Tue kids, Wed villagers, Thu commercial, Fri creative), events, campsite, meals, childcare count, kids programme shifts, yesterday’s highlights
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
