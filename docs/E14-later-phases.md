# E14: Later phases (out of scope for v1)

Goal: placeholders for work after the MVP. Stories here are drafts and need refinement before planning. Target: Shopify app and DATEV add-on ready before 1 Jan 2028.

## E14-S1: Shopify app

As a Shopify merchant, I want the same validation, guard and archive, so that I can meet the e-invoice rule too.

- Uses the shared validation API (E1).
- Own detection adapters for Shopify invoice apps.

## E14-S2: DATEV export (add-on)

As a Steuerberater working with DATEV, I want an export in DATEV format, so that I can import without retyping.

- Builds on the archive index (E7).
- Format and fields confirmed with design-partner advisors.

## E14-S3: Peppol sending

As a shop owner selling to Peppol-connected buyers, I want to send invoices through the network, so that delivery is automatic.

- Access-point provider selected; send status tracked in the order.

## E14-S4: Offline pre-check inside the plugin

As a shop owner on strict privacy settings, I want a basic local check, so that data does not leave my server.

- Evaluate the pure-PHP en16931-php library for hosts with PHP 8.4.
- Labeled as a basic check, not "official KoSIT".

## E14-S5: Paid archive storage add-on

As a shop owner above 5 GB, I want to buy more storage, so that I can keep archiving.

- Priced add-on tied to the license (E8).

## Explicitly out of scope

- Receiving or processing incoming supplier e-invoices.
- Replacing Germanized, German Market or WP Overnight as the main generator.
- Legal or tax advice.
