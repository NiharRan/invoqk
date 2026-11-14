
## i18n
After adding strings: `wp i18n make-pot . languages/invoqk.pot --domain=invoqk --exclude=vendor,tests,docs --skip-js`, translate `languages/invoqk-de_DE.po`, then `msgfmt -o languages/invoqk-de_DE.mo languages/invoqk-de_DE.po`.

# Invoqk

WooCommerce plugin: finds each order's e-invoice and shows its source. Needs WordPress 6.5+, WooCommerce 9.0+, PHP 8.1+.

- Tests: `composer install && vendor/bin/phpunit` (pure unit tests, no WordPress needed).
- Source adapters: see `ADAPTERS.md`.
- Integration tests against Germanized Pro, German Market and WP Overnight need licensed copies and are not yet written.

## Compatibility matrix (checked 2026-11-15 on a dev site: WP, WooCommerce 10.9.4, HPOS on, Action Scheduler 3.9.3)

| Check | Result |
| --- | --- |
| HPOS on, order list sweep, order meta | Pass |
| Activation without WooCommerce | Pass: notice only, plugin does not boot, no fatal |
| German strings (`de_DE`) | Pass |
| Status change to Action Scheduler job to cached result | Pass (real queue run) |
| Hourly sweep scheduled | Pass |
| Block and classic checkout | Neutral by construction: no checkout or front-end hooks; only `cart_checkout_blocks` compatibility is declared. Not exercised with a real checkout |
| HPOS off (legacy post-type orders): detection, order list column, meta box, sweep | Pass, after a fix: `wc_get_orders` ignores `meta_query` on the legacy store, so the sweep now filters in PHP |
| Multisite network activation (site 1 with WooCommerce, site 2 without) | Pass: site 2 shows the notice only and does not boot; site 1 works and schedules its own sweep |
| Polylang active | Pass (detection unaffected). WPML not tested; the plugin stores no translated content |
