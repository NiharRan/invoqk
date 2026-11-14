# E2: Plugin foundation & source detection (P0)

Goal: a thin PHP plugin that finds each order's e-invoice, whichever invoice plugin the shop runs.

## E2-S1: Install on a supported stack

As a shop owner, I want to install and activate the plugin without errors, so that I can start right away.

- Works on WordPress 6.5+, WooCommerce 9.0+, PHP 8.1+.
- HPOS declared compatible; works with block and classic checkout.
- Multisite-safe; neutral to WPML/Polylang.
- Activation on an unsupported version shows a clear notice instead of a fatal error.

## E2-S2: Use the plugin in German or English

As a German merchant, I want the UI in German (English as second language), so that I understand every message.

- All strings use translation functions; German and English files shipped.
- Language follows the site/user locale.

## E2-S3: Detect e-invoices from Germanized Pro

As a Germanized Pro user, I want Invoqk to find the e-invoice for each order, so that I do not upload anything manually.

- Adapter reads the XML from the attachment or order meta Germanized uses.
- Integration test runs against the latest Germanized Pro.

## E2-S4: Detect e-invoices from German Market

As a German Market user, I want the same detection, so that Invoqk works with my setup.

- Adapter finds the ZUGFeRD/XML file for an order.
- Integration test against the latest German Market.

## E2-S5: Detect e-invoices from WP Overnight

As a WP Overnight PDF Invoices user, I want the same detection, so that Invoqk works with the most installed plugin.

- Adapter covers UBL, CII, ZUGFeRD and Factur-X output.
- Integration test against the latest WP Overnight.

## E2-S6: See which plugin produced the invoice

As a shop owner, I want each order to show the source plugin, so that I know whose output is failing.

- Order list column and order detail show the plugin name.

## E2-S7: Flag orders with no e-invoice

As a shop owner, I want orders without an e-invoice flagged, so that I can fix them before sending.

- Flag appears in the order list and on the order screen.
- Orders that do not need an e-invoice (see E4) are not flagged.

## E2-S8: Keep the shop fast

As a shop owner, I want checkout and order pages never to wait on the API, so that sales are not slowed down.

- All API calls run in Action Scheduler jobs.
- Failed jobs retry with backoff.

## E2-S9: Add a new source adapter easily

As a developer on the team, I want one small adapter interface per source plugin, so that incumbents changing their meta only affects one class.

- Adapter interface documented; each adapter has its own tests.
