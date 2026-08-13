# TranslatePress setup

Vila Akoya uses English as the source language and Portuguese (Portugal) as the translated language.

## Local wp-env

Run:

```bash
npm run wp:install-plugins
```

That script installs and activates `translatepress-multilingual`, then configures:

- Default language: English (`en_US`)
- Translation language: Portuguese, Portugal (`pt_PT`)
- English remains on the root URLs, without `/en/`
- Portuguese uses `/pt/` URLs

Do not add TranslatePress to `.wp-env.json` and do not commit the plugin files. It is a WordPress.org plugin installed into the wp-env WordPress volume, like WooCommerce and Secure Custom Fields.

## Production on Hostinger

Install the same free plugin on the production WordPress site:

```bash
wp plugin install translatepress-multilingual --activate
```

Alternatively, use WordPress admin: Plugins -> Add New -> search for "TranslatePress" -> Install -> Activate.

Then open Settings -> TranslatePress and confirm:

- Default Language: English
- All Languages: English and Portuguese (Portugal)
- Use subdirectory for default language: off
- Portuguese URL slug: `pt`

## Translating content

ACF/SCF fields are translated after they render on the front end. Edit the original copy in English, view the page, then use the admin bar "Translate Page" action to review or adjust the Portuguese string.

For automatic Portuguese drafts when English ACF fields change, enable Settings -> TranslatePress -> Automatic Translation and add a Google Translate API key. The free TranslatePress plugin supports one extra language, which covers English plus Portuguese for this site.

If the Portuguese page still shows English text, that means no Portuguese translation has been saved yet. TranslatePress does not translate the site by only adding `/pt/`; it needs either manual translations through "Translate Page" or an automatic translation engine.

If a raw SQL query mentioning `wp_trp_gettext_pt_pt` appears at the top of the page in local wp-env, run `npm run wp:install-plugins` again. The install script creates the TranslatePress tables that are normally generated when saving Settings -> TranslatePress in wp-admin.
