# Campsite booking (admin)

## Plot count and labels

1. Open **WooCommerce → Campsite → Settings**.
2. Set **Default plot count** if you need extra rows seeded, then open **WooCommerce → Campsite → Plots** to edit labels and sort order or add/remove plots.

## Pricing (EUR)

1. The store uses **EUR** for all WooCommerce prices and checkout. Saving **WooCommerce → Campsite → Settings** also sets **WooCommerce → Settings → General → Currency** to **Euro (€)**.
2. Under **Nightly rates (EUR)** on the same screen you can set:
   - **Adult rate (override)** — per adult, per night; leave **0** to use the campsite product's **Regular price** in WooCommerce.
   - **Children / Infants / Pets** — nightly supplements (same amounts as the theme booking form fees).
   - **Pitch supplement by unit type** — nightly add-on per selected unit (tent, caravan, camper van, 4×4), matching the theme “What are you bringing?” chips.
3. Advanced: filters `vila_cb_guest_category_nightly_rates`, `vila_akoya_vehicle_types`, and `vila_cb_adult_rate_per_night` still apply if you need code-level overrides.

## WooCommerce product

1. Create a **simple** product (not a MWB bookable type).
2. Set **Regular price** to the **per adult, per night** amount in **EUR** (or rely on **Adult rate (override)** in Campsite settings).
3. In **WooCommerce → Campsite → Settings**, save the **Campsite product ID**. The plugin marks that product with internal meta so it uses the custom booking flow.

## Booking page

1. Edit the **Booking** (or home) page.
2. Add a Shortcode block (or classic shortcode) with:

   `[vila_campsite_booking]`

3. Optional: `[vila_campsite_booking product_id="123"]` if you need to override the product from settings.

## Disabling the old MWB + theme flow

1. Deactivate **Bookings for WooCommerce** when you are ready to go live on this plugin.
2. Draft or hide legacy MWB plot products so customers cannot add them to the cart.
3. Remove any MWB-specific templates or shortcodes from the Booking page and rely on `[vila_campsite_booking]` only.

## Operations dashboard

Use **WooCommerce → Campsite** for overview KPIs and the daily list, **Campsite · Calendar** for the plot × day grid, and **Campsite · Plots** for labels.

## wp-env: “Plugin file does not exist” (WooCommerce, CF7, etc.)

Do **not** list WordPress.org ZIP plugins in `.wp-env.json` → `"plugins"`. wp-env bind-mounts each slug from `~/.wp-env` on top of `wp-content/plugins/<slug>`, which can **hide** files that WP-CLI installs and makes Apache show “Plugin file does not exist” while CLI looks fine.

Keep **only** the local plugin in `"plugins"` (e.g. `./wp-content/plugins/vila-campsite-booking`) and install the rest with **`npm run wp:install-plugins`**.

After changing `.wp-env.json`, run **`npm run wp:restart`** then **`npm run wp:install-plugins`** (and **`npm run wp:restore`** if you also need the theme sync + permalinks).

### Campsite plugin mount / cwd

wp-env records **absolute host paths** when `wp-env start` runs. Always use **`npm run wp:start`** from the repo root (via `scripts/wp-env.sh`) so paths resolve correctly.

## Legacy: filters only

Guest supplements and unit-type fees are editable in **Campsite → Settings**; use filters only for edge cases (e.g. `vila_cb_guest_category_nightly_rates`).
