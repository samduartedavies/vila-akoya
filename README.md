# Vila Akoya — WordPress (V1)

Marketing site for Vila Akoya (campsite, eco-village, campus, homeschooling collective). This repo contains a **custom block theme** and **wp-env** for local development with MySQL/MariaDB.

## Requirements

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (or compatible Docker Engine + Compose)
- [Node.js](https://nodejs.org/) 18+ (for `npx` / `npm`)

## Quick start

```bash
npm install
npm run start
```

- **Site:** [http://localhost:8888](http://localhost:8888)
- **Admin:** [http://localhost:8888/wp-admin](http://localhost:8888/wp-admin)

Default credentials (wp-env):

- **User:** `admin`
- **Password:** `password`

After the first start, activate the **Vila Akoya** theme under **Appearance → Themes** if it is not already active.

The theme seeds **default page content** (including imagery from the Stitch / AIDA preview URLs) and bumps an internal **seed version** in the database. If you already ran an older seed and want to pull in updated default layouts again, delete the `vila_akoya_seed_version` option (e.g. with WP-CLI: `wp option delete vila_akoya_seed_version`) and reload once, or raise `VILA_AKOYA_SEED_VERSION` in `inc/page-setup.php` and reload.

**Replace preview images in production:** upload your own photos to the **Media Library** and swap them in the editor — the bundled URLs are for preview only.

### Database

WordPress uses the MariaDB instance bundled with wp-env. You do not need to create a database manually for local testing.

- **Host (from your machine):** `127.0.0.1` — use the port shown by `npx wp-env info` (often `50251` or similar for `MYSQL`).
- **Database name:** `wordpress`
- **User:** `root`
- **Password:** `password`

To open a MySQL shell inside the container:

```bash
npx wp-env run cli wp db cli
```

Or use a GUI client and the host/port from `npx wp-env info`.

### Useful commands

| Command | Description |
|--------|-------------|
| `npm run start` | Start WordPress + database |
| `npm run stop` | Stop containers |
| `npm run clean` | Remove containers and volumes (fresh DB) |
| `npx wp-env run cli wp theme list` | WP-CLI via wp-env |

### Project-specific URLs

Pages are created when the theme is activated (slugs):

| Page | Slug |
|------|------|
| Home | (set as static front page — see below) |
| The Village | `the-village` |
| The Campsite | `the-campsite` |
| Book / inquire | `book` |
| About | `about` |
| Contact | `contact` |
| Homeschooling collective | `homeschooling-collective` |
| The Campus | `the-campus` |

**Settings → Reading:** set “Your homepage displays” to **A static page**, choose **Home** as the homepage (if the Home page exists), and save.

## Bundled plugins (via wp-env)

Installed automatically from WordPress.org:

- **Contact Form 7** — booking / inquiry forms
- **WP Mail SMTP** — configure real SMTP on staging/production (see below)
- **Rank Math SEO** — titles, meta, sitemap

## Email (SMTP)

WordPress `wp_mail()` often lands in spam without SMTP. After deploying to a real host:

1. Install/configure **WP Mail SMTP** with your provider (Google, SendLayer, etc.).
2. Send a test email from the plugin’s tools screen.

Locally, mail may not send unless you add a mail catcher (Mailpit) or use your host’s SMTP in `.wp-env.override.json` — optional for development.

## Booking form (Contact Form 7)

The **Book** page includes a shortcode placeholder. After activation:

1. **Contact → Add New** in wp-admin, or use the auto-created **Booking inquiry** form if present.
2. Set the recipient to **Info@vila-akoya.com** in the form’s **Mail** tab.
3. On the **Book** page, replace the placeholder block with the **Contact Form 7** block and pick your form, or paste: `[contact-form-7 id="YOUR_ID" title="Booking inquiry"]`.

## Editing content

Use the **Site Editor** and **page editor**: sections are built from **patterns** (search for “Vila Akoya” in the pattern inserter). Swap text and images in the Media Library; the design tokens live in **theme.json** (colors, type).

## Production

wp-env is for **local development** only. For production, use a PHP host (or managed WordPress), import this theme, run the installer/plugins, configure SSL, SMTP, and Rank Math.

## Rank Math (SEO baseline)

After plugins load:

1. Open **Rank Math SEO** in the admin menu and run the **setup wizard** (site type: local business / organization as fits).
2. Under **Titles & Meta**, set a default title pattern (e.g. `%title% · Vila Akoya · Alentejo`).
3. Enable the **XML sitemap** and submit the sitemap URL in Google Search Console when the domain is live.
4. On each page, fill the **focus keyword** with a natural phrase (e.g. “eco village Alentejo”, “glamping Portugal”) — avoid repeating the same keyword on every page.

Seed ideas for focus phrases: glamping Alentejo, eco village Portugal, sustainable campsite Portugal, tiny house community Portugal, nature workshops Alentejo, homeschooling collective Portugal.

## License

GPL-2.0-or-later (WordPress theme).
