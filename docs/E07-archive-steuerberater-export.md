# E7: Archive & Steuerberater export (P0, Pro)

Goal: a complete, tamper-evident archive the tax advisor can use without extra work.

## E7-S1: Archive every sent invoice

As a shop owner, I want each sent e-invoice stored automatically, so that I have it for 8 years.

- XML and PDF are stored with order reference and upload timestamp.
- Archiving happens when the invoice is sent, in the background.
- Duplicates (resends) are stored as new versions, not overwritten.

## E7-S2: Make the archive tamper-evident

As a shop owner, I want a hash chain over the archive, so that changes can be detected.

- Each entry stores a SHA-256 hash of its files and the hash of the previous entry.
- A "verify archive" action recomputes the chain and reports any break.

## E7-S3: Prevent edits and log deletions

As a shop owner, I want archived files unchangeable from the UI, so that the archive stays trustworthy.

- No edit or replace function in the UI.
- Deletion is possible only after retention has passed or by explicit admin action, and is always logged (user, time, file).

## E7-S4: Set retention

As a shop owner, I want to set how long files are kept, so that I meet my obligations.

- Default 8 years; setting shows a note that it is not tax advice.
- Nothing is auto-deleted before the retention date.

## E7-S5: Browse and search the archive

As a shop owner, I want to find an archived invoice quickly, so that I can answer questions from customers or auditors.

- List with invoice number, date, customer, amounts and validation status; search and date filter.
- Download of single XML or PDF.

## E7-S6: Export for the Steuerberater

As a shop owner, I want a ZIP for a month or date range, so that I can send it to my tax advisor.

- ZIP contains all XML and PDF files in clear, consistent file names.
- CSV index with invoice no., date, customer, net, VAT, gross, validation status.
- Large ranges run in the background and notify when ready.

## E7-S7: Monitor storage use

As a shop owner, I want to see how much archive space I use, so that I am not surprised by limits.

- Shows used space against the 5 GB included; warning at 80%.

## E7-S8: Decide where the archive lives

As the Invoqk team, we want the storage decision made and implemented, so that privacy and sellability are both covered.

- Decision recorded: merchant WordPress, Invoqk EU storage, or both.
- If server-side: S3-compatible EU storage, covered by the AVV/DPA.
