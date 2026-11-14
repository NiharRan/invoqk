
## i18n
After adding strings: `wp i18n make-pot . languages/invoqk.pot --domain=invoqk --exclude=vendor,tests,docs --skip-js`, translate `languages/invoqk-de_DE.po`, then `msgfmt -o languages/invoqk-de_DE.mo languages/invoqk-de_DE.po`.

# Invoqk

WooCommerce plugin: finds each order's e-invoice and shows its source. Needs WordPress 6.5+, WooCommerce 9.0+, PHP 8.1+.

- Tests: `composer install && vendor/bin/phpunit` (pure unit tests, no WordPress needed).
- Source adapters: see `ADAPTERS.md`.
- Integration tests against Germanized Pro, German Market and WP Overnight need licensed copies and are not yet written.

## Compatibility matrix (manual, not yet run)

HPOS on/off, block and classic checkout, multisite network activation, WPML/Polylang, de_DE and en locale: **unchecked**. Run on a real site before release.
