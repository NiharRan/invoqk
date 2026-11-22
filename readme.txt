=== Invoqk – E-Rechnung Check & Archiv for WooCommerce ===
Contributors: [owner wordpress.org username]
Tags: e-invoice, e-rechnung, xrechnung, zugferd, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds and checks the e-invoice of every WooCommerce order and explains errors in German. Not legal or tax advice.

== Description ==

Invoqk finds the e-invoice (XRechnung, ZUGFeRD, Factur-X, UBL or CII) that your invoice plugin created for each WooCommerce order, sends it to the Invoqk validation API, and shows the result on the order and on a readiness dashboard. Errors are explained in German in plain words, with a hint on what to fix.

**Important:** Invoqk is a quality check. It is not legal or tax advice, and it does not replace advice from your tax adviser (Steuerberater). It does not guarantee that a tax authority, ELSTER, DATEV or any customer will accept an invoice.

= Supported invoice plugins =

* WP Overnight PDF Invoices (supported today)
* Germanized Pro (planned)
* German Market (planned)

Invoqk reads the invoice that these plugins already create. Today it detects invoices from WP Overnight only. It does not create or change invoices. Developers can add more sources, see `ADAPTERS.md` in the plugin repository.

= Features =

* Detects the e-invoice file of each order and shows which plugin it came from
* Checks every order with the validation API, with no order limit
* Error messages in German, with a suggested fix
* Classifies which orders need an e-invoice at all
* Readiness dashboard and report

= Requirements =

WordPress 6.5+, WooCommerce, PHP 8.1+, and one of the supported invoice plugins. Works with High-Performance Order Storage (HPOS) and the block checkout.

= External services =

This plugin connects to the Invoqk service at https://invoqk.com. Nothing is sent until you add an API key. The service is operated by Invoqk.

**1. Invoice validation API** (`https://invoqk.com/api/v1/einvoice/validate`)

* What is sent: the e-invoice file of an order (XML or PDF), as created by your invoice plugin, plus your API key and your shop's domain name. The file contains the data on the invoice, such as buyer and seller names, addresses, and line items.
* When: in the background after an order changes status or an invoice is created, and when you choose to check or re-check an order in the admin. Failed requests are retried a few times. Orders that need no e-invoice are not sent. Unchanged invoices are not sent again.
* What comes back: pass or fail and a list of issues, which the plugin turns into German messages and stores with the order.

Invoqk does not send invoice contents anywhere else, and the plugin does not write invoice contents or your key to logs.

* Terms of service: https://invoqk.com/terms
* Privacy policy: https://invoqk.com/privacy

= Translations =

The plugin is written in English and ships with German. Other languages can be contributed at translate.wordpress.org.

== Installation ==

1. Install and activate WooCommerce, and one of the supported invoice plugins.
2. Upload the plugin to `/wp-content/plugins/invoqk`, or install it from the Plugins screen, and activate it.
3. Open WooCommerce > Invoqk and follow the setup steps. To check invoices you need an Invoqk API key. Enter it in the Invoqk settings.
4. The first report shows how ready your recent orders are.

== Frequently Asked Questions ==

= Does this replace my invoice plugin? =

No. Invoqk reads the invoices your plugin creates and checks them. It does not create or change invoices.

= Is the result official? =

No. Invoqk is a quality check by the Invoqk service. It is not an official validation by a public authority, and it is not legal or tax advice. Ask your Steuerberater if you are unsure.

= Does it guarantee that ELSTER, DATEV or my customers accept the invoice? =

No. A passed check means the invoice had no issues in our check, nothing more.

= What data leaves my shop? =

Only what is listed under External services: the e-invoice file of an order for the check, your API key and your shop's domain name. See the terms and privacy policy linked there.

= Does it work without WooCommerce? =

No. The plugin shows a notice and does not run.

= Which invoice plugins are supported? =

WP Overnight PDF Invoices is supported today. Support for Germanized Pro is planned, and so is support for German Market.

== Screenshots ==

1. Readiness dashboard: how many orders have a valid e-invoice and what needs attention.
2. German error messages on an order, with a hint on how to fix each one.

== Changelog ==

= 0.1.0 =
* First release: e-invoice detection, validation, German error messages and readiness dashboard.

== Upgrade Notice ==

= 0.1.0 =
First release.
