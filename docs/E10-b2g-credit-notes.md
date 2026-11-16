# E10: B2G & credit notes (P1, Pro)

Goal: serve public-sector sellers and handle refunds correctly. Ships in the first update after launch.

## E10-S1: Collect a Leitweg-ID at checkout

As a B2G seller, I want an optional checkout field for the Leitweg-ID or buyer reference, so that public bodies can pay my invoices.

- Field works in block checkout and classic checkout.
- Optional, shown only where enabled (for example for business customers).
- Stored on the order and shown in the admin.

## E10-S2: Write the reference into the invoice as BT-10

As a B2G seller, I want the value placed in the invoice, so that the XRechnung passes validation.

- Value is passed to the supported invoice plugins so it appears as BT-10.
- Validation of a sample XRechnung with a Leitweg-ID passes the KoSIT validator.
- Where a plugin cannot take the value, a clear notice explains it.

## E10-S3: Validate the Leitweg-ID format

As a B2G seller, I want typos caught, so that invoices are not rejected later.

- Format check at checkout with a helpful message.

## E10-S4: Flag refunds that need a corrective e-invoice

As a B2B shop owner, I want refunds on B2B orders flagged, so that I remember to issue a credit note.

- A full or partial refund on an "erforderlich" order shows a flag and admin notice.

## E10-S5: Validate the credit note

As a B2B shop owner, I want the corrective e-invoice checked once created, so that it is valid too.

- Credit notes are detected by the source adapters (E2), validated (E3) and archived (E7).
- Credit note is linked to the original invoice in the order view and the export.

## Status

- 2026-11-16, E10-S1: done in code, manual shop check outstanding. Optional field in block checkout (additional checkout field, location `order`) and classic checkout; setting `invoqk_leitweg_mode` (off, always, business customers); stored as `_invoqk_buyer_reference` and shown in the order box. Spike finding: conditional visibility for block fields needs WooCommerce 9.8+, and the plugin supports 9.0, so the block checkout always shows the field when enabled; only classic checkout can limit it to business customers. Not yet checked in a real shop, in either checkout.
- 2026-11-16, E10-S2: partly done. The value reaches WP Overnight as BT-10 for UBL 2.1 and PEPPOL BIS 3.0 only (free plugin 5.16.6 via `wpo_ips_edi_document_structure` and `wpo_ips_edi_ubl_buyer_reference`); CII, Factur-X and ZUGFeRD have no BuyerReference element, so those orders get a notice to switch the format to UBL or PEPPOL BIS (fallback). BT-10 for credit notes from the WP Overnight Professional extension is UNVERIFIED. The KoSIT validation of a sample XRechnung with a Leitweg-ID (integration run, `INVOQK_INTEGRATION=1`) is still to do.
- 2026-11-16, E10-S3: done in code. `B2g\LeitwegId` checks the format (characters, parts, length) with a German message, at checkout in both flows; pasted whitespace and invisible characters are tolerated. No check digit verification beyond the two-digit shape. Real-shop check outstanding.
- 2026-11-16, E10-S4: done. Refunds on required orders set `_invoqk_refund_open`; flag in the order box, order list badge and a batched admin notice, with dismiss (an order note records it). Pro only. Cleared when the credit note is found or the refund is deleted; the hourly sweep also drops flags whose order is deleted or trashed or whose refund is gone. Real-shop check outstanding.
- 2026-11-16, E10-S5: done in code, with a spike gap. Credit notes are found per refund through `SourceAdapter::findCreditNotes`, validated, archived (`kind` credit_note, refund ID as `order_id`, `parent_id` to the invoice), and linked to the invoice in the order box, the archive page and the export. Archiving re-reads the adapter's bytes and archives them only when they match the validated hash, not an email capture, as credit notes have no guaranteed email. Unarchived credit notes (lost job, error, failed archive) are revisited by the hourly sweep; a checked and archived one is not checked again, also not after a correction in the invoice plugin. The WP Overnight Professional credit-note slug (`credit-note`) and its binding to the refund are UNVERIFIED (the free 5.16.6 has no credit-note document); real-shop checks and the KoSIT run are still to do.
