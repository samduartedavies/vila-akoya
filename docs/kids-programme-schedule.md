# Kids programme schedule (Notion + VAVA)

Teacher and caregiver shifts (Coddiwomplers, Early Readers, Arete, Home Schooling Hub, parent activities) live in **Notion → Calendars → Kids Programme Shifts**. VAVA reads that database for daily Telegram roundups and personal reminders.

## Notion setup

1. Run migration (creates or updates the database):
   ```bash
   npm run notion:migrate-v2
   ```
2. Open **Kids Programme Shifts** and use the **Calendar** view on **Date**.
3. One row per **session per day** (not a wide spreadsheet grid).

| Property | Use |
|----------|-----|
| **Date** | Day of the shift |
| **Programme** | Coddiwomplers, Early Readers, Arete, Home Schooling Hub, Parent / villager activity, or **Day note** (holidays / context) |
| **Time** | Optional; defaults match the usual bands (e.g. Coddiwomplers 10:00–13:00) |
| **Lead** / **Support** | Names as on Members (`/register`) |
| **Status** | Confirmed, **TBC**, or Cancelled |
| **Notes** | Projektcharter, Lego, etc. |
| **Post to Telegram** | Uncheck to hide from bots (default: on) |

## Import from the old Google Sheet CSV

```bash
# Dry run
node scripts/import-kids-programme-csv.js /path/to/Work_schedule_Kids_programmes__-_KIDS.csv

# Write to Notion (set year for Oct–Dec columns)
IMPORT_YEAR=2026 node scripts/import-kids-programme-csv.js --apply /path/to.csv
```

npm shortcut:

```bash
npm run notion:import-kids-programme -- --apply ./data/your-export.csv
```

Re-running import skips rows that already match the same date, programme, and lead.

## Telegram behaviour

### Parents & Teachers (full roundup)

Each morning (~same time as the village recap, `RECAP_TIME`, default 08:00 Lisbon), VAVA posts **today’s programme list** to the **Parents & Teachers** chat/topic when shifts exist.

Configure the destination with env (same as broadcast reminders):

```json
TELEGRAM_CHAT_ALIASES={"Parents & Teachers":"-100xxxxxxxxxx"}
TELEGRAM_TOPIC_ALIASES={"Parents & Teachers":12345}
```

Optional overrides:

- `KIDS_PROGRAMME_TELEGRAM_CHAT_ID` or `KIDS_PROGRAMME_TELEGRAM_CHAT`
- `KIDS_PROGRAMME_TOPIC_ID` or `KIDS_PROGRAMME_TOPIC`

### Personal DMs

Anyone **registered** (`/register Your Name`) whose name matches **Lead** or **Support** gets a short DM listing their sessions that day.

### TBC / cover

If any session is **TBC** or has no lead, members whose **Role / focus** matches `Kids / education` (override with `KIDS_PROGRAMME_COORDINATOR_ROLE`) get a nudge to fill gaps in Notion.

### Main village recap

The daily group recap also includes a **Kids programme today** block when there are shifts (alongside childcare, meals, etc.).

### Assigning someone to a slot (Telegram)

**Natural language** (tag VAVA in the group or DM):

- “Put Amber on Early Readers Friday”
- “Moon is covering Arete tomorrow”
- “Add Tamara as support on Home Schooling Hub Monday”
- “Mark Coddiwomplers TBC on the 10th”
- “Cancel parent activity today”

**Command** (no AI parsing needed):

```text
/kids Amber · Early Readers · Friday
/kids Moon · Arete · 2026-10-10
/kids help
```

Updates **Kids Programme Shifts** in Notion immediately (no bot restart). Leads still need `/register` for morning DMs.

### Asking VAVA

In group or DM:

- “Who’s on Early Readers Thursday?”
- “Kids programme this week?”

These use the schedule answer path (same as rota and childcare counts).

## Childcare vs kids programme

| | **Childcare** | **Kids programme shifts** |
|--|----------------|---------------------------|
| Source | Website bookings → Notion **Childcare** | Manual / import → **Kids Programme Shifts** |
| Audience | Child minder job + parents (absence) | Teachers/caregivers + Parents & Teachers topic |
| Content | Booked children, allergies, phones | Programme times and who leads |

## Checklist for go-live

- [ ] `npm run notion:migrate-v2`
- [ ] Import or enter shifts for the current month
- [ ] Every lead has `/register` with the same name as in Notion
- [ ] `TELEGRAM_CHAT_ALIASES` (+ topic aliases if forum) for Parents & Teachers
- [ ] Bot restarted after env changes
