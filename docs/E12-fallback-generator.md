# E12: Fallback generator (P2, Pro)

Goal: cover orders where no invoice plugin produced an e-invoice. It is not a replacement for Germanized, German Market or WP Overnight.

## E12-S1: Offer generation only for orders without an e-invoice

As a shop owner, I want a "create e-invoice" action only where none exists, so that I do not duplicate my invoice plugin.

- Action appears on orders flagged "no e-invoice" (E2-S7) and classified "erforderlich".
- Hidden when any source plugin has produced an invoice.

## E12-S2: Generate a basic XRechnung or ZUGFeRD

As a shop owner, I want a valid basic e-invoice, so that I can send something compliant.

- Uses order, customer and shop data; output profile selectable (XRechnung or ZUGFeRD).
- Output must pass KoSIT validation in tests for standard orders.

## E12-S3: Fill required shop data

As a shop owner, I want the plugin to tell me what seller data is missing, so that generation does not fail.

- Settings for seller name, address, VAT ID, bank details and payment terms.
- Missing fields are listed before generation.

## E12-S4: Validate, guard and archive generated invoices

As a shop owner, I want the generated file to go through the same checks, so that it is treated like any other invoice.

- Generated invoice is validated (E3), covered by the guard (E6) and archived (E7).
- Source is shown as "Invoqk fallback".

## E12-S5: Know the limits

As a shop owner, I want to know what the fallback does not cover, so that I choose the right tool.

- Documented limits (for example complex tax cases, discounts, multi-currency) and a notice in the UI.
