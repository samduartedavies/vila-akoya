=== Vila Campsite Booking ===
Contributors: vilaakoya
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-plot campsite inventory with WooCommerce checkout, Airbnb-style booking UI, and an operations dashboard.

== Description ==

* True inventory: one booking per plot per night; half-open stay ranges `[check_in, check_out)` in the site timezone.
* Atomic reservation at payment using unique night rows to prevent double booking under concurrency.
* REST availability endpoint for greying fully booked nights in the date picker.
* WooCommerce HPOS compatible (uses WooCommerce order APIs).
* Admin: KPIs, daily list, plot × day grid with links to orders, plot labels/sort.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/vila-campsite-booking/`.
2. Activate through the Plugins screen.
3. Ensure WooCommerce is active.
4. Create a **simple** product for the campsite stay, set regular price to **per adult per night**.
5. Go to **WooCommerce → Campsite → Settings**, enter the product ID, and save.
6. Add the shortcode `[vila_campsite_booking]` to your Booking page.

== Frequently Asked Questions ==

= Does this replace Bookings for WooCommerce (MWB)? =

Yes for inventory and booking UX. Deactivate MWB when ready and point your Booking page at the new shortcode. Legacy MWB products can be drafted manually.

= What happens on uninstall? =

Orders remain in WooCommerce. Custom tables are kept unless you filter `vila_cb_uninstall_remove_tables` to return true.

== Changelog ==

= 1.0.8 =
* Admin calendar / booking views: resolve extras labels from theme `_vila_vehicle_slugs` JSON when the “Extras” display meta is missing or locale-mismatched; fall back to the first campsite order line when `line_item_id` is unset.

= 1.0.7 =
* On load, remove deprecated `vehicle_fees` keys (e.g. four_by_four) from the saved `vila_cb_pricing` option so the database matches current extras.

= 1.0.6 =
* Campsite settings: `vehicle_fee_slugs()` always drops deprecated keys (e.g. four_by_four) after merging theme slugs, so legacy rows cannot reappear if another filter runs late.

= 1.0.5 =
* Campsite settings “Extras”: always list electricity and car from plugin defaults; strip legacy four_by_four from vehicle types and saved pricing; show plugin version on the rates panel.

= 1.0.0 =
* Initial release.
