# E4: B2B/B2C classifier (P0)

Goal: mark each order "E-Rechnung erforderlich" or "nicht erforderlich" so only the right orders are checked and guarded.

Status: spec, ready to build. No dependency on an API answer. Builds on E2 and E3 as they are in the repo today.

## Context

E2 detects each order's e-invoice file. E3 validates every order that has one. Nothing decides whether an order needs an e-invoice at all. `Flag::forOrder()` asks the filter `invoqk_order_requires_einvoice`, whose default is `true`, so every order without a file is flagged "No e-invoice", B2C included. E3 also spends a Free slot (20 per site) on every B2C order that happens to have a PDF, which burns the trial on orders that never needed a check.

E4 adds the missing decision: a pure function from order data to `required` yes/no plus a reason code. The result is stored on the order, shown to the merchant, overridable per order, and used by E2 (flag), E3 (what to validate and what counts against the Free limit), and later E5 and E6 (dashboard and pre-send guard, "erforderlich" orders only).

A wrong "erforderlich" on a B2C order floods the merchant with false flags. A wrong "nicht erforderlich" on a real B2B order lets a missing e-invoice through unseen. The override and the visible reason exist because the rule will be wrong sometimes.

## Decisions

Open when the stories were first written. Fixed here so the implementer makes no design calls.

1. **Threshold is on the gross order total.** `$order->get_total()` (incl. VAT, shipping, before refunds). `total > 250.00` can be required; `total <= 250.00` is "nicht erforderlich" (reason `small_amount`). Reflects the Kleinbetragsrechnung limit (gross 250 EUR). The value is a class constant `Classifier::THRESHOLD = 250.0`, filterable via `apply_filters( 'invoqk_classifier_threshold', 250.0, $order )`. No settings UI.
2. **Domestic means billing country `DE`.** Hardcoded. E-Rechnung duty is a German rule; shops outside Germany are out of scope for now. Billing country empty: use the shipping country; still empty: not required, reason `no_country`.
3. **B2B means VAT ID present, or company field present.** VAT ID alone is B2B. Company alone is B2B with a hint (S4). Neither is B2C.
4. **Classification runs inside the existing detect job.** `Detector::run` classifies first, then detects the source. No API call, no extra job type. Cheap and pure, so it also runs on demand when the stored result is missing (S1).
5. **Stored result, derived-free.** One meta key `_invoqk_class` with the auto result, and one `_invoqk_override` with the manual one. The effective value is computed from both (S3). The auto result is always kept so a cleared override falls back without recomputing.
6. **Reasons are codes, text is rendered at display time.** Locale decides the language. Same `__()` pattern and `languages/invoqk-de_DE` as E3.
7. **E4 also gates E3.** "Nicht erforderlich" orders are not validated and take no Free slot, unless the merchant overrides them to required (S6). This is the reason the epic exists; leaving E3 as it is would make the classifier cosmetic.
8. **The existing filter stays.** `invoqk_order_requires_einvoice` now receives the classified value as its first argument instead of `true`. Third-party code that returns `false` keeps working; code that returns `true` for a B2C order now overrides E4. Document this in the filter docblock.
9. **No VIES lookup.** The VAT ID is checked for shape only, offline. A hint, never an error.

## Current State

Verified in the repo on 2026-11-15.

| Area | Today | File |
|------|-------|------|
| Order flag | `Flag::forOrder()` returns `source`, `missing`, `not_required`, `pending`. `not_required` only when a filter returns `false` | `src/Admin/Flag.php:9` |
| Detection | `Detector::run( $orderId )`; trash and non-`WC_Order` (refunds) return early; writes meta with `save_meta_data()` | `src/Detection/Detector.php:12` |
| Detect triggers | `woocommerce_order_status_changed`, `invoqk_schedule_detect`, `wpo_wcpdf_save_document`, hourly sweep | `src/Jobs/Scheduler.php:19` |
| Validate trigger | `Job::handle()` validates any order with a detected file, then `Limit::allow()` | `src/Validation/Job.php:10` |
| Sweep | queues validation for orders with a file and status `pending` / retryable `error` / Pro `limit` | `src/Jobs/Scheduler.php:62` |
| Order list column | source label + validation badge, or "No e-invoice", or a dash | `src/Admin/OrderColumn.php:35` |
| Order screen | meta box (`normal` context): `Report::html`, source, format, checked time | `src/Admin/OrderBox.php:20` |
| Admin action pattern | `admin-post` + nonce + `manage_woocommerce` | `src/Admin/Revalidate.php` |
| Saving meta | `save_meta_data()`, never `save()` (avoids `order.updated` webhooks) | `src/Detection/Detector.php:30` |
| VAT ID field | none in WooCommerce core; plugins store it under their own billing meta key | not in repo |

Landscape of who consumes the result:

| Consumer | Needs from E4 | Change in E4? |
|----------|---------------|---------------|
| E2 flag (`Flag`) | required yes/no | yes, S2 |
| E3 job and sweep | skip not-required orders | yes, S6 |
| E3 Free limit | not-required orders take no slot | yes, via S6 |
| E5 dashboard, onboarding | "last 20 B2B orders" | no, reads `Classifier::isRequired()` later |
| E6 pre-send guard | "erforderlich" + failed | no, reads `Classifier::isRequired()` later |

## Rules

Evaluated top to bottom, first match wins. `country` is the billing country, falling back to the shipping country.

| # | Condition | required | reason |
|---|-----------|----------|--------|
| 1 | `total <= 0` | no | `zero_total` |
| 2 | `country` empty | no | `no_country` |
| 3 | `country != 'DE'` | no | `foreign` |
| 4 | no VAT ID and no company | no | `private` |
| 5 | `total <= threshold` | no | `small_amount` |
| 6 | VAT ID present | yes | `vat_id` |
| 7 | company present, no VAT ID | yes | `company_only` |

Order matters: rule 4 before 5 so a small private order reads "private", not "small amount". Rule 5 before 6 so a small B2B order is not required.

Hint (separate field, never changes the result): `vat_id_format` when a VAT ID is present, billing country is `DE`, and the normalized ID (uppercase, spaces and dots removed) does not match `/^DE[0-9]{9}$/`. A hint `no_vat_id` is added to rule 7.

Inputs, read once:

- VAT ID: first non-empty value among order meta keys from `apply_filters( 'invoqk_vat_id_meta_keys', [ '_billing_vat_id', '_billing_eu_vat_number', '_billing_vat_number', '_vat_number' ] )`. The defaults cover Germanized, the WooCommerce EU VAT Number plugin and common variants. Verify the list against current Germanized and EU VAT Number releases before launch (Open Questions).
- Company: `$order->get_billing_company()`, trimmed.
- Country: `$order->get_billing_country()`, then `get_shipping_country()`.
- Total: `(float) $order->get_total()`.

## Data model

Two order meta keys. Written with `update_meta_data` + `save_meta_data()`.

```php
// _invoqk_class : automatic result, always kept
[
  'required' => true,            // bool
  'reason'   => 'vat_id',        // zero_total|no_country|foreign|private|small_amount|vat_id|company_only
  'hint'     => null,            // null|'no_vat_id'|'vat_id_format'
  'at'       => 1763200000,      // unix time of the last classification
]

// _invoqk_override : absent unless the merchant set one
[
  'required' => false,           // bool: the forced value
  'by'       => 17,              // user id
  'at'       => 1763200000,
]
```

Effective value: `_invoqk_override['required']` if the key exists, else `_invoqk_class['required']`, else (never classified) computed on the fly and not stored by a read path.

`Invoqk\Classification\Classifier` is both the rule engine and the one read API: `classify( $order )` (pure rules), `effective( $order )` (override, else stored, else computed and not stored), `isRequired()`, `reason()`, `hint()`, `isOverridden()` and `reasonText( $code )` (the German/English sentence, one place). Used by `Flag`, `OrderColumn`, `OrderBox`, `Job`, `Scheduler`. Nothing else reads the meta keys directly. (Review D1: one class, not a separate `Classification` facade.)

## Stories

### E4-S1: Classify an order automatically

As a shop owner, I want each order classified when it is created or updated, so that I do not decide by hand.

Behavior:

- New class `Invoqk\Classification\Classifier` with a pure static method `Classifier::classify( \WC_Order $order ): array`, returning the `_invoqk_class` shape. No I/O besides reading the order.
- `Detector::run` calls it first (after the trash and `WC_Order` guards, before `Registry::detect`) and stores `_invoqk_class` with its own `save_meta_data()` right there. It must not wait for the later save: `Detector.php:21` returns early when no adapter hits but a cached file is still valid, and that path never reaches the final save, so a combined save would drop the result for any order whose source plugin is off (Review D3). Cost: one extra meta write per detect job.
- Triggers: everything that already triggers detection (see Current State). Add a `woocommerce_update_order` callback that runs `Classifier::classify()` inline (pure, no I/O), compares `required` and `reason` with the stored `_invoqk_class`, and calls `Scheduler::enqueue( $id )` only when they differ or nothing is stored. That keeps one queue row per real change instead of one per save by any plugin (Review D4). `save_meta_data()` does not fire `woocommerce_update_order`, so the plugin's own writes cannot loop.
- Reclassification never touches `_invoqk_override`.
- When the effective value flips from not required to required and the order has a file but no validation record, the existing `Detector` enqueue (no `_invoqk_validation`) covers it. No new code path.
- Refund objects are not classified. `Detector` already returns for anything that is not a `WC_Order`.

Constraints:

- No API call. No log of VAT ID, company or address. Log order ID and reason code only, source `invoqk`.
- Total, country and company are cast and trimmed; `get_total()` returning a string or empty is handled.

### E4-S2: See the classification and the reason

As a shop owner, I want to see why an order was classified that way, so that I trust it.

- `Flag::forOrder()` order of checks: no detection result gives `pending`; effective not required gives `not_required` (before looking at the source); a source gives `source`; else `missing`. The filter `invoqk_order_requires_einvoice` is applied to the classified value.
- Order list column (`OrderColumn::render`):

| Flag | Cell |
|------|------|
| `pending` | dash |
| `not_required` | grey text "Nicht erforderlich" (no validation badge, no source label) |
| `source` | source label + validation badge, as today (the order is required here) |
| `missing` | "No e-invoice" (failed style), as today |

- Order screen, in the existing meta box above the validation report: a line "E-Rechnung erforderlich" or "E-Rechnung nicht erforderlich", the reason as one German sentence, and the hint (if any) on a second line. Overridden orders show "Manuell festgelegt von {Name} am {Datum}" instead of the automatic reason, plus the automatic result in parentheses.
- Reason and hint strings (English msgids, German in `languages/invoqk-de_DE.po`):

| Code | German text |
|------|-------------|
| `vat_id` | Inlandskunde mit USt-IdNr., Betrag über 250 €. |
| `company_only` | Inlandskunde mit Firmenname, Betrag über 250 €. |
| `private` | Keine USt-IdNr., kein Firmenname. |
| `foreign` | Rechnungsland ist nicht Deutschland. |
| `small_amount` | Gesamtbetrag 250 € oder weniger (Kleinbetragsrechnung). |
| `zero_total` | Bestellwert 0 €. |
| `no_country` | Kein Rechnungsland angegeben. |
| hint `no_vat_id` | Hinweis: Keine USt-IdNr. angegeben. |
| hint `vat_id_format` | Hinweis: Die USt-IdNr. hat kein deutsches Format (DE und 9 Ziffern). |

- The `250 €` in the texts is built from the threshold with `sprintf`, not hardcoded, so the filter stays truthful.
- The order list reads cached meta only: no adapter call, no API call, no extra query per row beyond `get_meta`. A not-yet-classified order shows the dash.
- All output escaped (`esc_html`). VAT ID and company are never printed in the reason line.

### E4-S3: Override the classification per order

As a shop owner, I want to override the result on one order, so that edge cases are handled.

- Control in the meta box: three links "Automatisch / Erforderlich / Nicht erforderlich", the current state shown as plain text instead of a link. Links, not a form: the box sits inside the WooCommerce order edit form and browsers drop nested forms (`Report.php:99` says the same for "Validate again"; Review D2).
- `admin-post` action `invoqk_override`, query args `order_id` and `value` (allowlist `required`, `not_required`, `auto`, anything else is a no-op), nonce `invoqk_override_{id}` via `wp_nonce_url`, capability `manage_woocommerce`, same shape as `Admin\Revalidate`. Class `Invoqk\Admin\Override`, booted in `Plugin::boot` under `is_admin()`.
- Choosing "Erforderlich" or "Nicht erforderlich" writes `_invoqk_override` (`required`, `by` = current user id, `at` = now). Choosing "Automatisch" deletes it.
- Every change adds a private order note: "Invoqk: E-Rechnung manuell auf {erforderlich|nicht erforderlich} gesetzt von {display name}" or "Invoqk: Überschreibung entfernt, automatisch {…}". The note is the audit log (user and time are on the note).
- An overridden order is marked: the order list cell shows a small "manuell" tag, the meta box shows who and when.
- After a change that makes the effective value required, enqueue `Scheduler::enqueue( $id )` (detect, which enqueues validation when a file exists). After a change to not required, nothing is queued; an existing validation record stays untouched and is simply no longer shown.
- No-op if the new value equals the current effective override state; no note, no redirect error.
- Unauthorized or bad nonce: `wp_die` with 403, same as `Revalidate`.

### E4-S4: Handle edge cases

As a shop owner, I want sensible handling of unclear data, so that I am not flooded with wrong flags.

- Company set, no VAT ID: required (rule 7) with hint `no_vat_id`.
- VAT ID that does not match the German shape on a domestic order: result unchanged, hint `vat_id_format`. Never an error, never blocks.
- Zero-value order: not required, `zero_total`.
- Refund objects: not classified, never flagged (inherited from `Detector`). A partially refunded order keeps its original total.
- Whitespace-only company or VAT ID counts as empty.
- Non-EUR currency: compared numerically, not converted (shortcut: rare for a German domestic order, convert when a real case appears). Noted in Open Questions.

### E4-S5: Classifier is tested

As a developer on the team, I want a test table of cases, so that rule changes do not cause regressions.

- `tests/Classification/ClassifierTest.php`, a data provider with one row per case (below), asserting `required`, `reason` and `hint`.
- Cases (each: country, company, VAT ID, total, expected):

| # | Country | Company | VAT ID | Total | required | reason | hint |
|---|---------|---------|--------|-------|----------|--------|------|
| 1 | DE | | | 500 | no | `private` | |
| 2 | DE | Acme GmbH | | 500 | yes | `company_only` | `no_vat_id` |
| 3 | DE | | DE123456789 | 500 | yes | `vat_id` | |
| 4 | DE | Acme GmbH | DE123456789 | 500 | yes | `vat_id` | |
| 5 | DE | Acme GmbH | DE123456789 | 250.00 | no | `small_amount` | |
| 6 | DE | Acme GmbH | DE123456789 | 250.01 | yes | `vat_id` | |
| 7 | DE | | | 100 | no | `private` | |
| 8 | DE | Acme GmbH | DE123456789 | 0 | no | `zero_total` | |
| 9 | AT | Acme GmbH | ATU12345678 | 500 | no | `foreign` | |
| 10 | DE | Acme GmbH | DE12 | 500 | yes | `vat_id` | `vat_id_format` |
| 11 | DE | Acme GmbH | de 123 456 789 | 500 | yes | `vat_id` | |
| 12 | (empty) | Acme GmbH | DE123456789 | 500 | no | `no_country` | |
| 13 | (empty, shipping DE) | Acme GmbH | DE123456789 | 500 | yes | `vat_id` | |
| 14 | DE | `"   "` | `"  "` | 500 | no | `private` | |
| 15 | DE | | VAT key `_billing_eu_vat_number` | 500 | yes | `vat_id` | |

- Separate tests for: effective value with and without override; reclassification keeps the override; override audit note and meta; `Flag` precedence; hostile company name rendered escaped; filter `invoqk_classifier_threshold` changes the cutoff; `invoqk_vat_id_meta_keys` adds a key.
- Added by review: `Detector::run` saves `_invoqk_class` on the cached-file early-return path (source plugin off); the `woocommerce_update_order` callback enqueues once on a changed result and 0 times on an unchanged one; the meta box renders the override control as `<a>` links and contains no `<form>`; `Override` rejects a `value` outside the allowlist; a legacy order with no `_invoqk_class` is classified on the fly in `Flag`, `OrderColumn` and `Job` without writing meta; E3 `JobTest` and `SchedulerTest` fixtures gain a B2B order helper.

### E4-S6: Only validate and count required orders

As a shop owner, I want orders that need no e-invoice left alone, so that my Free slots go to the orders that matter.

- `Job::handle`: after the guard on source and file, if the effective value is not required, return before the API key check, adapter read and `Limit::allow`. No API call, no slot taken, no `_invoqk_validation` written.
- An order with no `_invoqk_class` yet is classified on the fly inside `Job::handle` (pure call), so a never-detected legacy order is not wrongly skipped or validated.
- `Scheduler::sweep` and `Scheduler::needsValidation`: skip not-required orders. The per-run caps (100 detect, 100 validate) count only orders that were actually queued.
- Override to required on an order with a file: validated as normal and takes a Free slot like any other.
- Orders already validated before E4 and now not required: record and slot stay. The slot is not returned (Free slots are append-only, E3 decision 4). Not required orders hide the validation badge but the meta stays.
- Manual "Validate again" on a not-required order is not offered (the button is hidden with the validation report). Override first, then validate.

## Acceptance Criteria

1. A DE order, company "Acme GmbH", VAT ID `DE123456789`, total 500.00 is classified required, reason `vat_id`, within 2 minutes of creation, and the order list shows the source label and validation badge.
2. A DE order with no company and no VAT ID, total 500.00 is not required, reason `private`; the order list shows "Nicht erforderlich"; no HTTP request is made for it and no Free slot is used.
3. A DE B2B order with total 250.00 is not required (`small_amount`); with total 250.01 it is required.
4. An order with billing country AT, with a valid-looking VAT ID, is not required, reason `foreign`.
5. Company set, no VAT ID, DE, total 500: required, reason `company_only`, and the order screen shows the line "Hinweis: Keine USt-IdNr. angegeben."
6. VAT ID `DE12` on a DE order: classified by the table, hint `vat_id_format` shown, no error, validation unaffected.
7. A zero-value order is not required, reason `zero_total`. A refund object is never classified or flagged.
8. The order screen shows the German reason sentence under a "E-Rechnung erforderlich / nicht erforderlich" heading. With a non-German locale it shows the English msgid.
9. A user with `manage_woocommerce` can set "Erforderlich", "Nicht erforderlich" and "Automatisch". The order list shows a "manuell" tag while an override exists. Each change adds one private order note naming the user. A user without the capability, or with a bad nonce, gets 403 and nothing changes.
10. An override survives reclassification: editing the billing address of an overridden order leaves `_invoqk_override` intact; clearing the override restores the latest automatic result.
11. On a Free site, 25 not-required orders with a file validate 0 times and take 0 slots; the 21st required order still gets a slot only if fewer than 20 slots are used.
12. Overriding a not-required order with a file to required validates it on the next detect job and takes a slot.
13. Editing the company field of an existing order in the admin reclassifies it within one detect job, without firing `order.updated` webhooks from the plugin.
14. `Classifier::classify` passes all 15 table rows in S5.
15. The order list with 50 orders makes 0 HTTP requests and 0 adapter calls, and no extra queries beyond `get_meta` per row.
16. Company name and VAT ID containing `<script>` render as text everywhere; neither value appears in a log line (grep on a test run).
17. `invoqk_order_requires_einvoice` returning `false` still flips an order to not required; the filter docblock describes the new first argument.
18. Tests written and passing; existing E2 and E3 tests still pass (E3 tests that create orders without company or VAT ID now need a B2B fixture or an override).

## Testing Plan

| Layer | What | Count |
|-------|------|-------|
| Unit | `Classifier` table (15 rows); threshold and VAT-key filters; effective value with and without override; VAT ID normalization and format hint; German reason text for every code | +20 |
| Integration | `Detector::run` stores class meta and keeps override; `Job::handle` skips not-required (0 HTTP, 0 slots) and classifies on the fly when meta is absent; sweep skips not-required; override flips and queues detect; `woocommerce_update_order` enqueues once (dedupe) | +8 |
| Render | column cell per flag incl. "manuell" tag; meta box reason, hint, override block; hostile company name escaped; override controls hidden without capability | +4 |
| Manual | one real shop: B2C order, B2B order with and without VAT ID, order over and under 250, override round trip | 1 |

Layout: add `tests/Classification/`; update `tests/Admin/FlagTest.php`, `tests/Admin/OrderColumnTest.php`, `tests/Validation/JobTest.php`, `tests/Jobs/SchedulerTest.php`.

## Effort Estimate

| Part | Estimate |
|------|----------|
| `Classifier` (rules, read API, reason text), meta model | 3 h |
| Detector, update-order hook | 1.5 h |
| `Flag`, column cell, meta box reason block, German strings | 3 h |
| Override action, controls, order note | 3 h |
| Gate E3 job and sweep, fix E3 tests | 2 h |
| Tests | 5 h |
| Real-shop check | 1 h |
| Total | about 18.5 h |

## Rollback

Revert the PR. `_invoqk_class` and `_invoqk_override` are inert without the code, `invoqk_order_requires_einvoice` goes back to a default of `true`, and E3 validates every order with a file again. No schema change, no data migration, nothing scheduled that is not already in group `invoqk`.

## Files Reference

| File | Change |
|------|--------|
| `src/Classification/Classifier.php` | new: pure rules, effective value, reason text (S1, S2) |
| `src/Admin/Override.php` | new: `admin-post` handler, nonce, capability, order note (S3) |
| `src/Detection/Detector.php:12` | classify first, own `save_meta_data()` before the early return at line 21 |
| `src/Admin/Flag.php:9` | new precedence, filter gets the classified value (S2) |
| `src/Admin/OrderColumn.php:35` | `not_required` cell, "manuell" tag |
| `src/Admin/OrderBox.php:20` | reason, hint, override controls above the report |
| `src/Validation/Job.php:10` | return early for not-required orders (S6) |
| `src/Jobs/Scheduler.php:19` | `woocommerce_update_order` callback (enqueue on change only); `sweep` and `needsValidation` skip not required |
| `src/Plugin.php` | boot `Admin\Override` |
| `languages/invoqk-de_DE.po` and `.mo`, `invoqk.pot` | new strings |
| `tests/Classification/*`, `tests/Admin/*`, `tests/Validation/JobTest.php`, `tests/Jobs/SchedulerTest.php` | new and updated tests |

## Out of Scope

- A settings screen for the threshold, the country list or the VAT ID field name. Constants and filters only.
- VIES or any online VAT ID check.
- Shops outside Germany, other EU countries' e-invoicing rules, B2G (E10).
- Currency conversion for the 250 EUR threshold.
- Bulk override, bulk reclassify and a "reclassify all old orders" tool. New and edited orders only, plus the existing 30-day sweep.
- Modelling the German phase-in dates for the issuing duty (turnover limits in 2027 and 2028). E4 answers "does this order need an e-invoice by the rules as they will apply", not "is this merchant already obliged".
- Acting on the result: holding emails (E6), dashboard counts (E5), export (E7). E4 only provides `Classifier::isRequired()`.

## Open Questions

1. Which meta keys do Germanized and the WooCommerce EU VAT Number plugin write for the VAT ID today? The default list in `invoqk_vat_id_meta_keys` is from memory of both plugins; check a real install of each and fix the list before launch.
2. Confirm with the tax advisor that the 250 EUR limit applies to the gross total of the order (not per invoice line, not net). The rules table follows the Kleinbetragsrechnung reading.
3. Should a B2B order with a valid-looking foreign EU VAT ID (reverse charge, no German VAT) stay "nicht erforderlich"? Default here: yes, `foreign`, since billing country decides.
4. Non-EUR shop with DE billing: is numeric comparison acceptable until a real case appears? Default: yes.
5. A company name entered by B2C customers as a courtesy (for example a sports club) becomes `company_only` required. Is the hint plus override enough, or should E4 add a "require VAT ID" mode later?

## GSTACK REVIEW REPORT

| Review | Runs | Status | Findings |
|--------|------|--------|----------|
| Eng Review | 1 | DONE_WITH_CONCERNS | 4 issues found, 4 resolved in this spec |

Findings (all folded into the sections above):

- D1 [scope] 3 new classes reduced to 2: `Classification` merged into `Classifier`.
- D2 [P1] (9/10) `Report.php:99`: override control is nonce links, not a form (nested form inside the order edit form).
- D3 [P1] (9/10) `Detector.php:21`: classification saved at the top of `run()`, before the cached-file early return.
- D4 [P2] (7/10) `woocommerce_update_order` callback enqueues only when the classification changed.

Test gaps added to the Testing Plan (S5): early-return save, change-only enqueue, no `<form>` in the meta box, override value allowlist, legacy orders without `_invoqk_class`, E3 test fixtures.

Not run: outside voice (codex), performance deep-dive beyond the checks above (classification is pure and cached in meta; the list view makes no extra queries).

VERDICT: ready to build.

NO UNRESOLVED DECISIONS
