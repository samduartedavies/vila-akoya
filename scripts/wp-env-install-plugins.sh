#!/usr/bin/env bash
# Install and activate plugins inside wp-env using WP-CLI (wordpress.org slugs).
#
# IMPORTANT — .wp-env.json should list ONLY local plugins (e.g. vila-campsite-booking).
# Listing ZIP URLs under "plugins" makes wp-env bind-mount each slug from ~/.wp-env cache
# on top of wp-content/plugins/<slug>. That can hide files WP-CLI writes and breaks the
# Apache container with "Plugin file does not exist". Marketplace plugins belong in the
# shared WordPress volume; install them with this script after `npm run wp:start`.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

bash scripts/wp-env.sh start

echo "==> Syncing vila-campsite-booking into containers (wordpress-1 needs files for Plugins UI)…"
bash scripts/sync-vila-plugin-to-wp-env.sh

echo "==> Installing plugins from WordPress.org (WP-CLI)…"
bash scripts/wp-env.sh run cli sh -c '
set -e
cd /var/www/html
if ! wp core is-installed 2>/dev/null; then
  echo "error: WordPress is not installed yet. Run: npm run wp:start and wait for it to finish." >&2
  exit 1
fi
# WooCommerce first (others may depend on it)
wp plugin install woocommerce --activate
wp plugin install woocommerce-gateway-stripe --activate
wp plugin install contact-form-7 --activate
wp plugin install secure-custom-fields --activate
wp plugin install autodescription --activate
wp plugin install ultimate-addons-for-gutenberg --activate
wp plugin install mwb-bookings-for-woocommerce --activate
wp plugin install translatepress-multilingual --activate
echo "==> Configuring TranslatePress (English → Portuguese)…"
cat > /tmp/vila-configure-translatepress.php <<PHP
<?php
\$settings = get_option("trp_settings", array());
if (!is_array(\$settings)) {
	\$settings = array();
}

\$languages = array("en_US", "pt_PT");
\$url_slugs = isset(\$settings["url-slugs"]) && is_array(\$settings["url-slugs"]) ? \$settings["url-slugs"] : array();
\$url_slugs = array_intersect_key(\$url_slugs, array_flip(\$languages));
\$url_slugs["en_US"] = "en";
\$url_slugs["pt_PT"] = "pt";

\$settings["default-language"] = "en_US";
\$settings["translation-languages"] = \$languages;
\$settings["publish-languages"] = \$languages;
\$settings["add-subdirectory-to-default-language"] = "no";
\$settings["url-slugs"] = \$url_slugs;

update_option("trp_settings", \$settings);

// Direct WP-CLI option updates skip the admin settings-save path that creates tables.
if (class_exists("TRP_Gettext_Table_Creation")) {
	\$gettext_tables = new TRP_Gettext_Table_Creation(\$settings);
	\$gettext_tables->check_gettext_original_table();
	\$gettext_tables->check_gettext_original_meta_table();
	foreach (\$languages as \$language) {
		\$gettext_tables->check_gettext_table(\$language);
	}
}

if (class_exists("TRP_Translate_Press")) {
	\$trp = TRP_Translate_Press::get_trp_instance();
	\$query = \$trp->get_component("query");
	if (\$query && method_exists(\$query, "check_table")) {
		\$query->check_table("en_US", "pt_PT");
	}
}
PHP
wp eval-file /tmp/vila-configure-translatepress.php
rm -f /tmp/vila-configure-translatepress.php
echo "==> Activating Vila Campsite Booking (from .wp-env.json bind mount)…"
wp plugin activate vila-campsite-booking
echo "==> Active plugins:"
wp plugin list --status=active --format=table
'
