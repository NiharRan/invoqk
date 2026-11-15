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

`InvoiceFile(int|string $reference, string $mime, string $format, bool $onDemand = false)`: attachment ID or absolute path (a plain tag when `onDemand`, i.e. the source builds the file on request); `application/xml` or `application/pdf`; one of `ubl|cii|zugferd|facturx|unknown`. ZUGFeRD PDFs are passed as PDF; the API extracts the XML.

## Register

Only the WP Overnight adapter ships today; the registry also lists Germanized Pro and German Market adapters (planned, loaded only if their classes exist). First hit wins, in this order: Germanized Pro, German Market, WP Overnight. Add your own:

```php
add_filter( 'invoqk_source_adapters', fn( array $list ) => [ ...$list, new My_Adapter() ] );
```

Optional: call `do_action( 'invoqk_schedule_detect', $order_id )` when the source plugin creates an invoice, to detect at once instead of at the hourly sweep.

## Tests

Each adapter gets its own test file in `tests/Sources/` using fixtures recorded from the real plugin. Record the plugin version and the meta keys/attachment location in the adapter's docblock. Run: `vendor/bin/phpunit`. Real-plugin tests go in the `integration` group and run only with `INVOQK_INTEGRATION=1`.

## Order meta written by Invoqk

`_invoqk_source` (adapter id or `none`), `_invoqk_label`, `_invoqk_file` (`reference`, `mime`, `format`), `_invoqk_detected_at`. Filter `invoqk_order_requires_einvoice` (default `true`) decides whether a missing invoice is flagged.
