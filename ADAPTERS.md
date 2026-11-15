# Writing a source adapter

One class per invoice plugin. If that plugin changes where it keeps its e-invoice, only its adapter changes.

## Contract (`Invoqk\Sources\SourceAdapter`)

| Method | Returns | Notes |
| --- | --- | --- |
| `id()` | `string` | Stable machine id stored in order meta, e.g. `germanized_pro` |
| `label()` | `string` | Name shown to the merchant |
| `isAvailable()` | `bool` | Source plugin is active. `find()` is not called when false |
| `find(\WC_Order $order)` | `?InvoiceFile` | The file, or `null`. Never create or save anything. Return `null` if the file is missing on disk |
| `read(\WC_Order $order, InvoiceFile $file)` | `?string` | The file's bytes. Stored-file adapters read the file; on-demand sources (WP Overnight) may build it in memory. Never save, send or attach |
| `findCreditNotes(\WC_Order $order)` | `array<int, InvoiceFile>` | Existing credit notes keyed by refund ID, `[]` when none. Never create one. `read()` is then called with the parent order and must build the credit note named by `$file->reference` (WP Overnight: `wpo:credit-note:<refund id>`) |

Optional: `Invoqk\Sources\BuyerReferenceAware::supportsBuyerReference(): bool`. Implement it when your plugin can take the buyer reference (Leitweg-ID, BT-10) through a documented filter or setting: wire `Invoqk\B2g\Reference::get( $order )` into it (only when non-empty, only for that order, never editing generated XML) and document the hook in the adapter docblock. Without it, the order box tells the merchant to enter the Leitweg-ID in the invoice plugin; when it returns `false`, the box says the current e-invoice format cannot carry it and to switch to UBL or PEPPOL BIS. Before the first detection the first available adapter is used, a supporting one first. WP Overnight: UBL 2.1 and PEPPOL BIS via `wpo_ips_edi_document_structure` and `wpo_ips_edi_ubl_buyer_reference`; its CII formats have no BuyerReference.

`WpOvernightAdapter::boot()` registers both filters once, for every request (EDI is also built for emails and downloads): `wpo_ips_edi_document_structure` switches the `buyer_reference` element on, `wpo_ips_edi_ubl_buyer_reference` sets its value to `Reference::get()` of that order (a refund is mapped to its parent order). `supportsBuyerReference()` is true only while WP Overnight's EDI format is UBL 2.1 or PEPPOL BIS 3.0 (checked against free 5.16.6). For CII, Factur-X and ZUGFeRD the order box shows a notice that this format cannot carry the Leitweg-ID and to switch WP Overnight's e-invoice format to UBL or PEPPOL BIS. Whether the Professional credit-note documents take the same filters is unverified.

`InvoiceFile(int|string $reference, string $mime, string $format, bool $onDemand = false)`: attachment ID or absolute path (a plain tag when `onDemand`, i.e. the source builds the file on request); `application/xml` or `application/pdf`; one of `ubl|cii|zugferd|facturx|unknown`. ZUGFeRD PDFs are passed as PDF; the API extracts the XML.

## Register

Only the WP Overnight adapter ships today; the registry also lists Germanized Pro and German Market adapters (planned, loaded only if their classes exist). First hit wins, in this order: Germanized Pro, German Market, WP Overnight. Add your own:

```php
add_filter( 'invoqk_source_adapters', fn( array $list ) => [ ...$list, new My_Adapter() ] );
```

Optional: call `do_action( 'invoqk_schedule_detect', $order_id )` when the source plugin creates an invoice or credit note, to detect at once instead of at the hourly sweep. The hook queues both the invoice detection and (Pro) the credit-note detection for that order.

New hook: `do_action( 'invoqk_credit_note_validated', $order_id, $refund_id, $record )` fires after a credit note was checked; the archive listens to it. Credit-note detection and validation are Pro only.

## Tests

Each adapter gets its own test file in `tests/Sources/` using fixtures recorded from the real plugin. Record the plugin version and the meta keys/attachment location in the adapter's docblock. Run: `vendor/bin/phpunit`. Real-plugin tests go in the `integration` group and run only with `INVOQK_INTEGRATION=1`.

## Order meta written by Invoqk

`_invoqk_source` (adapter id or `none`), `_invoqk_label`, `_invoqk_file` (`reference`, `mime`, `format`), `_invoqk_detected_at`, `_invoqk_buyer_reference` (Leitweg-ID / BT-10, read it with `Reference::get()`). Filter `invoqk_order_requires_einvoice` (default `true`) decides whether a missing invoice is flagged.

Credit notes and refunds: `_invoqk_refund_open` (refund IDs on a required order that have no credit note yet; the site-wide list of such orders is the option `invoqk_refund_open_orders`), `_invoqk_credit_notes` (one entry per refund ID: `source`, `file`, `detectedAt`, `validation`, `archived`). Both are written with `save_meta_data()`, not `save()`. Orders with a credit note not archived yet are listed in the option `invoqk_credit_note_pending_orders`; the hourly sweep (Pro) re-detects them and the orders in `invoqk_refund_open_orders` (at most 100 per run), validates unarchived entries again and drops refund flags whose order is gone or trashed or whose refund no longer exists.

Option `invoqk_leitweg_mode` (`off`, `always`, `business`) controls the checkout field for the Leitweg-ID. In business mode the field counts as a business customer when a company name or a VAT ID is posted; the VAT ID meta keys come from the existing filter `invoqk_vat_id_meta_keys`. `Reference::get()` also reads the block checkout value (`_wc_other/invoqk/leitweg_id`).

Archive rows have two more fields, `kind` (`invoice` or `credit_note`) and `parent_id` (archive row of the invoice the credit note refers to). A credit-note row uses the refund ID as `order_id`, so look up the parent order via the refund. The hash chain covers `kind` and `parent_id` only for credit-note rows, so existing invoice hashes are unchanged.
