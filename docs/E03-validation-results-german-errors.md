# E3: Validation results & plain-German errors (P0)

Goal: the merchant sees pass/fail per invoice and understands why, in plain German.

## E3-S1: Validate an order's invoice

As a shop owner, I want each detected e-invoice sent for validation automatically, so that I know its status without clicking.

- New invoices are queued for validation after detection.
- Status values: pending, passed, failed, error (API unreachable).
- Manual "validate again" button on the order.

## E3-S2: See the result on the order and in the order list

As a shop owner, I want a status badge on each order, so that I can scan for problems.

- Badge in the order list; full report on the order screen.
- Report shows timestamp and rule set version.

## E3-S3: Read errors in plain German

As a shop owner who is not a tax expert, I want each failed rule explained in German, so that I know what is wrong.

- Each error shows BT code, a German sentence (for example "BT-10 Käuferreferenz fehlt") and severity.
- Rules without a custom text fall back to the raw message, labeled as such.
- All texts are translatable; English second.

## E3-S4: Learn where to fix it in WooCommerce

As a shop owner, I want a pointer to where the fix lives, so that I can correct it myself.

- Each known error links to the relevant WooCommerce or invoice-plugin setting or order field.
- Top 20 most common errors covered at launch.

## E3-S5: Optional AI rewrite of errors

As a shop owner, I want an optional AI explanation, so that unusual errors are easier to read.

- Off by default, behind a toggle.
- Only the error text is sent, never invoice content or customer data.
- Falls back to the standard German text if the call fails.

## E3-S6: Free tier limit

As a free user, I want to validate my last 20 invoices, so that I can try the plugin before paying.

- Free: last 20 invoices; older or additional ones show an upgrade prompt.
- Pro: unlimited, enforced by license status.

## E3-S7: Handle API errors gracefully

As a shop owner, I want a clear "could not validate" state, so that I do not mistake an outage for a failed invoice.

- API errors show status "error" with retry, never "failed".
