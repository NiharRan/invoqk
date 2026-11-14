# E4: B2B/B2C classifier (P0)

Goal: mark each order "E-Rechnung erforderlich" or "nicht erforderlich" so only the right orders are checked and guarded.

## E4-S1: Classify an order automatically

As a shop owner, I want each order classified when it is created or updated, so that I do not decide by hand.

- Inputs: VAT ID, company field, billing country, order total.
- Domestic B2B (Germany) with total over €250 is "erforderlich"; B2C, foreign, and €250-or-less are "nicht erforderlich".
- Classification result and the reason are stored on the order.

## E4-S2: See the classification and the reason

As a shop owner, I want to see why an order was classified that way, so that I trust it.

- Badge in the order list; reason shown on the order screen (for example "Keine USt-IdNr., kein Firmenname").

## E4-S3: Override the classification per order

As a shop owner, I want to override the result on one order, so that edge cases are handled.

- Override control on the order screen; an overridden order is clearly marked.
- Override is logged with user and time.

## E4-S4: Handle edge cases

As a shop owner, I want sensible handling of unclear data, so that I am not flooded with wrong flags.

- Company field set but no VAT ID: classified as B2B, with a hint.
- Invalid VAT ID format: hint shown, not a hard error.
- Refunds and zero-value orders do not produce false flags.

## E4-S5: Classifier is tested

As a developer on the team, I want a test table of cases, so that rule changes do not cause regressions.

- Unit tests for each combination of company, VAT ID, country and threshold.
