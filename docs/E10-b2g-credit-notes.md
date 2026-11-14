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
