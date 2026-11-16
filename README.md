
## i18n
After adding strings: `wp i18n make-pot . languages/invoqk.pot --domain=invoqk --exclude=vendor,tests,docs --skip-js`, translate `languages/invoqk-de_DE.po`, then `msgfmt -o languages/invoqk-de_DE.mo languages/invoqk-de_DE.po`.

# Invoqk

WooCommerce plugin: finds each order's e-invoice and shows its source. Needs WordPress 6.5+, WooCommerce 9.0+, PHP 8.1+.

- Tests: `composer install && vendor/bin/phpunit` (pure unit tests, no WordPress needed).
- Source adapters: see `ADAPTERS.md`.
- Fallback generator (Pro): when no invoice plugin supplies an e-invoice, Invoqk can create an XML-only e-invoice itself (XRechnung 3.0 or EN 16931 CII, ZUGFeRD XML). Limits: only domestic (DE) EUR orders; tax rates 19 % and 7 % (0 % only with the Nullsatz setting: zero-rated, not tax-exempt); small businesses under §19 UStG (Kleinunternehmer) are not supported; orders with a refund are refused; totals must reconcile to the cent; no discount that needs a document-level allowance, no multi-currency plugins, no subscriptions with split periods, partial deliveries or advance payments; no hybrid PDF (ZUGFeRD is XML only); credit notes are not created by the fallback.
- Integration tests against WP Overnight (the only adapter that ships today) and, later, Germanized Pro and German Market need licensed copies and are not yet written.

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
