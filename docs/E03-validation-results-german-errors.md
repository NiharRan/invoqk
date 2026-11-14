# E3: Validation results & plain-German errors (P0)

Goal: the merchant sees pass/fail per invoice and understands why, in plain German.

Status: spec, ready to build once the API contract questions below are confirmed.

## Context

E2 finds each order's e-invoice and caches the result in order meta (`_invoqk_source`, `_invoqk_file`). Nothing checks the invoice yet. E1 provides `POST /api/v1/einvoice/validate`, which returns a structured report with rule IDs, BT codes and German text. E3 connects the two: send the file, store the verdict, show it where the merchant already looks (order list, order screen) and turn each failed rule into a sentence plus a pointer to the fix.

This is the first part of the plugin a merchant will judge. A wrong "failed" on an API outage, or a wall of English Schematron text, loses them.

## Decisions

These were open when the stories were first written. Fixed here so the implementer makes no design calls.

1. **German text comes from the API.** E1-S9 delivers `messageDe` and a `fix` key per issue, and the web checker and Sheets add-on need the same text. The plugin does not keep its own rule catalog. It only maps `fix` keys to links (S4).
2. **Locale picks the language.** `de_*` locales show `messageDe`; every other locale shows the English `message`. A German locale with `messageDe = null` shows the English text under the label "Originaltext (Englisch)".
3. **The plugin enforces the free limit (S6), the API does not.** Free means 20 distinct orders. Re-validating a counted order is free. The API's own monthly cap (E1-S4, 100 calls on Free) stays as a backstop and surfaces as a normal `error`.
4. **S6 is slot based, not "last 20".** The first 20 distinct orders that get validated use a slot. Slots are stored in the `invoqk_free_slots` option (array of order IDs). This differs from the story's "last 20" wording on purpose: it is deterministic and needs no date logic. Say so in the upgrade prompt: "20 invoices included in Free".
5. **Pro is a filter.** `apply_filters( 'invoqk_is_pro', false )`. E8 hooks in later. Until then every install is Free.
6. **S5 (AI rewrite) is deferred out of E3.** It sends data off-site and needs a provider, a privacy review and a consent text. Not P0. See Out of Scope.

## Current State

Verified in the repo on 2026-11-15.

| Area | Today | File |
|------|-------|------|
| Detection result | order meta `_invoqk_source`, `_invoqk_label`, `_invoqk_file`, `_invoqk_detected_at` | `src/Detection/Result.php` |
| Reading the file | `SourceAdapter::read( $order, $file ): ?string`, bytes in memory, never saved | `src/Sources/SourceAdapter.php` |
| Jobs | Action Scheduler group `invoqk`, hooks `invoqk_detect_order` and hourly `invoqk_sweep`, backoff 60 s / 300 s / 1800 s | `src/Jobs/Scheduler.php` |
| Order list | one column `invoqk`: source label or "No e-invoice" | `src/Admin/OrderColumn.php` |
| Order screen | meta box in the `side` context: status, format, checked time | `src/Admin/OrderBox.php` |
| Settings | placeholder page with the disclaimer only | `src/Admin/SettingsPage.php` |
| Saving meta | `save_meta_data()`, never `save()`, to avoid `order.updated` webhooks | `src/Detection/Detector.php` |

Not found from this repo: the API's own spec (`nodejs/ai-invoice/docs/specs/invoqk-validation-api.md`, named in E1). The request and response shapes below come from E1-S1, S3, S4, S7 and S9. Confirm them against the API before building (see Open Questions).

## API contract the plugin relies on

Request: `POST {base}/api/v1/einvoice/validate`, API key in a header, body is the raw file with `Content-Type: application/xml` or `application/pdf`. Timeout 20 s (the API promises p95 under 10 s).

Success response (200):

```json
{
  "verdict": "pass",
  "ruleSet": { "name": "XRechnung", "version": "3.0.2", "date": "2026-08-31" },
  "issues": [
    {
      "id": "BR-DE-15",
      "bt": ["BT-10"],
      "severity": "error",
      "xpath": "/Invoice/cbc:BuyerReference",
      "message": "The element Buyer reference (BT-10) must be provided.",
      "messageDe": "BT-10 Käuferreferenz fehlt",
      "fix": "buyer_reference"
    }
  ],
  "usage": { "used": 12, "limit": 100, "period": "2026-11" }
}
```

`verdict` is `pass` or `fail`. `severity` is `error` or `warning`. `messageDe` and `fix` may be `null`. A `pass` verdict can still carry warnings.

Error responses:

| HTTP | Code | Plugin status | Retry |
|------|------|---------------|-------|
| 401 | missing or revoked key | `error` | no, until the key changes |
| 403 | `wrong_site` | `error` | no |
| 429 | `quota_exceeded`, `Retry-After` | `error` | once, after `Retry-After` (cap 6 h) |
| 400, 415, 422 | malformed or unsupported file | `error` | no |
| 5xx, timeout, or body `retryable: true` | | `error` | yes, backoff 60 s / 300 s / 1800 s |

No HTTP response, and no response body, ever produces `failed`. Only a parsed 200 with `verdict: fail` does.

## Data model

One order meta key, `_invoqk_validation`, an array. Written with `update_meta_data` + `save_meta_data()`.

```php
[
  'status'     => 'passed' | 'failed' | 'error' | 'limit',   // absent key = never validated; 'pending' is derived, see below
  'checkedAt'  => 1763200000,        // unix time of the last finished attempt
  'hash'       => 'sha256 of the bytes sent', // empty for error before a send
  'ref'        => '123',             // detected file reference as string; lets Detector see a changed file
  'ruleSet'    => [ 'name' => 'XRechnung', 'version' => '3.0.2', 'date' => '2026-08-31' ],
  'issues'     => [ [ 'id', 'bt' => [], 'severity', 'xpath', 'message', 'messageDe', 'fix' ] ], // max 100, see below
  'issueCount' => 140,               // true total when issues was cut
  'error'      => null | [ 'code' => 'wrong_site', 'http' => 403 ],
  'attempt'    => 1,
]
```

`pending` is derived, not stored: source is not `none`, no `_invoqk_validation`, and a validate action is queued or the order is newer than the first sweep. Cheapest rule: no meta and source is not `none` and not `limit` means pending.

Issues beyond 100 are dropped and `issueCount` keeps the real number; the report says "and 40 more". Each `message` is cut at 500 characters.

Option `invoqk_api_key` (string, autoload off). Option `invoqk_free_slots` (array of order IDs, max 20). Option `invoqk_api_base` defaults to the production URL, overridable by the constant `INVOQK_API_BASE` for staging.

## Stories

### E3-S1: Validate an order's invoice

As a shop owner, I want each detected e-invoice sent for validation automatically, so that I know its status without clicking.

Behavior:

- New action `invoqk_validate_order` in group `invoqk`, args `[ $orderId, $attempt, $force ]`, handled by `Invoqk\Validation\Job::handle()`.
- Enqueued (`Scheduler::enqueueValidate`, async, skipped if the same order is already queued) from four places:
  1. `Detector::run`, after a hit, when the order has no `_invoqk_validation` or the stored file reference differs from the detected one.
  2. The existing `wpo_wcpdf_save_document` hook (WP Overnight rebuilt the invoice, so the content may have changed).
  3. The manual button (force).
  4. `Scheduler::sweep`, for recent orders (30 days) with a file and no validation meta, or status `error`; also `limit` when Pro.
- Handler steps: load order, return if source is `none` or trashed, read bytes through the adapter, return with status `error` code `unreadable` if bytes are null. Compute sha256. If not forced and the hash equals the stored hash and status is `passed` or `failed`, stop (no API call). Apply the free limit (S6). Call the API. Map the response to the meta array. Save.
- Retry on the table above using `Scheduler::backoff()`, with `$attempt + 1`. After the last backoff step the status stays `error` and the manual button is the way out. A `429` reschedules once at `Retry-After`.
- An order with no API key set gets status `error`, code `no_key`, no API call, no retry. The text points at the settings page.
- Status values: `passed`, `failed`, `error`, `limit`, plus derived `pending`.
- Manual "Validate again" button on the order screen: `admin-post` action `invoqk_revalidate`, nonce `invoqk_revalidate_{id}`, capability `manage_woocommerce`. It enqueues a forced job and redirects back. It does nothing if a job for the order is already queued.

Constraints:

- Never call the API on a request that renders a page. Only inside the Action Scheduler handler.
- Use `wp_remote_post` with `timeout => 20`, `redirection => 0`, `sslverify` on.
- Never log the file, the API key or issue text. Log order ID, status, HTTP code only, source `invoqk`.
- Use `save_meta_data()`, not `save()`.

### E3-S2: See the result on the order and in the order list

As a shop owner, I want a status badge on each order, so that I can scan for problems.

- `OrderColumn::render()` adds a badge after the source label. Badge text and style per status:

| Status | Text (de / en source string) | Style class |
|--------|------------------------------|-------------|
| passed | Gültig / Valid | `status-completed` |
| failed | Fehlerhaft / Invalid | `status-failed` |
| error | Nicht geprüft / Could not check | `status-on-hold` |
| limit | Limit erreicht / Limit reached | `status-on-hold` |
| pending | Wird geprüft / Checking | `status-pending` |

- Order list reads cached meta only. No adapter call, no API call, no extra query per row beyond `get_meta`.
- Order screen: move the meta box context from `side` to `normal` so the issue list has room. It shows: status badge, "Checked" timestamp (`wp_date`), rule set as "XRechnung 3.0.2 (2026-08-31)", the issue list (S3, S4), "Validate again" button.
- Orders with source `none` show no validation badge (E2 flag stays as is).
- A `passed` result with warnings shows the badge `Gültig` and a line "N Hinweise" with the warnings listed below.

### E3-S3: Read errors in plain German

As a shop owner who is not a tax expert, I want each failed rule explained in German, so that I know what is wrong.

- `Invoqk\Validation\IssueText::for( array $issue, string $locale ): array` returns `[ 'code' => 'BT-10', 'text' => '…', 'raw' => bool, 'severity' => 'error'|'warning' ]`.
  - `de_*` locale and `messageDe` set: text is `messageDe`, `raw = false`.
  - `de_*` locale and `messageDe` null: text is `message`, `raw = true`, rendered with the label "Originaltext (Englisch)".
  - Other locale: text is `message`, `raw = false`.
  - `code` is the BT codes joined with ", "; if `bt` is empty it is the rule `id`.
- Rendered as one line per issue: severity label ("Fehler" / "Warnung"), code, text. Errors first, then warnings, each group in API order.
- All plugin-owned strings (labels, headings, buttons, the "Originaltext" label) use `__()` with text domain `invoqk`, English msgids, German in `languages/invoqk-de_DE`. Same pattern as `tests/I18nTest.php`.
- Output is escaped (`esc_html`). Issue text comes from a remote server and is never trusted.
- The xpath is shown inside a `<details>` under each issue, not in the main line.

### E3-S4: Learn where to fix it in WooCommerce

As a shop owner, I want a pointer to where the fix lives, so that I can correct it myself.

- `Invoqk\Validation\FixMap::link( ?string $fixKey, \WC_Order $order ): ?array` returns `[ 'label' => string, 'url' => string ]` or null.
- The map is a PHP array keyed by the API's `fix` key. Each entry is one of: a WooCommerce settings tab (`admin_url( 'admin.php?page=wc-settings&tab=…' )`), the order edit screen anchored to a billing field, or the settings page of the source plugin that produced the invoice (resolved from `Result::source`).
- Unknown or null `fix` key: no link, nothing else changes.
- Top 20 `fix` keys covered at launch. The list is owned by E1-S9; this epic builds the map for exactly the keys E1-S9 ships. Starting examples (verify against the E1-S9 list before use): `buyer_reference` (BT-10), `seller_vat_id` (BT-31), `seller_contact` (BG-6), `seller_electronic_address` (BT-34), `buyer_electronic_address` (BT-49), `payment_means` (BG-16), `buyer_address` (BG-8).
- Each map entry's label is translatable (`__()`), with a German translation.
- Test: every key in `FixMap` has a German translation; a fixture of the E1-S9 key list matches the map's keys exactly, so a key added in the API without a plugin entry fails CI.

### E3-S5: Optional AI rewrite of errors — deferred

Not in this epic. See Out of Scope.

### E3-S6: Free tier limit

As a free user, I want to validate my first 20 invoices, so that I can try the plugin before paying.

- Check runs inside the job, before the API call, in `Invoqk\Validation\Limit::allow( int $orderId ): bool`:
  - Pro (`apply_filters( 'invoqk_is_pro', false )` true): allow.
  - Order ID already in `invoqk_free_slots`: allow.
  - Fewer than 20 slots used: add the order ID, save the option, allow.
  - Otherwise: deny. The job writes status `limit`, no API call.
- A denied order shows the badge `Limit erreicht` and one sentence: "Free enthält 20 Rechnungen. Mit Pro prüfst du alle." with an "Upgrade" link. The URL is the constant `INVOQK_UPGRADE_URL`, defaulting to the site root of the Invoqk web page until E8 sets it.
- The slot is taken when the job starts the API call, not when it succeeds, so retries after an outage do not take a second slot.
- A Pro site re-queues `limit` orders in the next sweep (S1 point 4 of the enqueue list).
- Manual "Validate again" on a counted order is always allowed and takes no new slot.

### E3-S7: Handle API errors gracefully

As a shop owner, I want a clear "could not validate" state, so that I do not mistake an outage for a failed invoice.

- Per the error table: any transport problem, 4xx and 5xx produce `error`, never `failed`.
- The report for `error` shows one German sentence per code:

| Code | German text |
|------|-------------|
| `no_key` | Es ist noch kein API-Schlüssel hinterlegt. Trage ihn unter WooCommerce → Invoqk ein. |
| `unauthorized` (401) | Der API-Schlüssel ist ungültig oder wurde widerrufen. |
| `wrong_site` (403) | Dieser API-Schlüssel gehört zu einer anderen Domain. |
| `quota_exceeded` (429) | Das Prüfkontingent ist aufgebraucht. Wir versuchen es später erneut. |
| `unreadable` | Die Rechnungsdatei konnte nicht gelesen werden. |
| `rejected` (400, 415, 422) | Die Datei ist kein unterstütztes E-Rechnungsformat. |
| `unavailable` (5xx, timeout, `retryable`) | Der Prüfdienst ist gerade nicht erreichbar. Wir versuchen es erneut. |

- `unavailable` and `quota_exceeded` show "Wir versuchen es erneut" while retries remain, then "Bitte später auf „Erneut prüfen“ klicken."
- Settings page gets an "API key" text field (password input, saved with `register_setting`, capability `manage_woocommerce`).
- API errors never block checkout, order save or any order action. The job fails alone.

## Acceptance Criteria

1. A WP Overnight order with a valid UBL invoice reaches status `passed` within 2 minutes of detection with no click, and the order list shows `Gültig`.
2. A UBL invoice missing BT-10 reaches `failed`; the order screen lists the line "BT-10 Käuferreferenz fehlt" with severity "Fehler", the rule set name and version, and the check time.
3. With site locale `en_US` the same issue shows the API's English `message`; with `de_DE` and `messageDe = null` it shows the English text labeled "Originaltext (Englisch)".
4. A stubbed API returning 500, 503, a timeout, or `retryable: true` leaves the order at `error` and never `failed`. Retries run at 60 s, 300 s and 1800 s, then stop.
5. 401, 403 and 422 responses produce `error` with no retry.
6. A 429 with `Retry-After: 120` reschedules exactly one retry about 120 s later.
7. With no API key set, the order gets `error` / `no_key` and no HTTP request is made.
8. The same unchanged invoice is sent once: a second detection or sweep makes 0 new API calls. "Validate again" always makes 1.
9. A regenerated WP Overnight invoice with different bytes is validated again on `wpo_wcpdf_save_document`.
10. On Free, the 21st distinct order gets `limit` with no HTTP request; re-validating any of the first 20 still calls the API; with `invoqk_is_pro` true, a `limit` order validates on the next sweep.
11. Opening the order list with 50 orders makes 0 HTTP requests and no adapter calls.
12. `FixMap::link()` returns a link for every key in the E1-S9 fixture and null for an unknown key; all labels have German translations.
13. A response with 140 issues stores 100, keeps `issueCount = 140`, and the report shows "und 40 weitere".
14. The meta box and the badge escape all remote text: an issue message containing `<script>` renders as text.
15. No log line, option or meta contains invoice bytes or the API key (grep on a test run).
16. Tests written and passing; existing E2 tests still pass.

## Testing Plan

| Layer | What | Count |
|-------|------|-------|
| Unit | response to meta mapping (pass, fail, each error code); `IssueText` language choice; `Limit::allow` (Free, Pro, repeat, 21st); `FixMap` keys vs fixture and translations; issue cap at 100; hash dedupe | +10 |
| Integration | job with `pre_http_request` stub: pass, fail, 500 then 200, 429 reschedule, no key; `wpo_wcpdf_save_document` re-queues; sweep picks up `error` and (Pro) `limit` | +6 |
| Render | column badge per status; meta box report; escaping of hostile issue text | +3 |
| Manual | one real order on a site with WP Overnight against the staging API | 1 |

Follow the layout in `tests/` (`Admin`, `Detection`, `Jobs`, `Sources`); add `tests/Validation`.

## Effort Estimate

| Part | Estimate |
|------|----------|
| API client, response mapping, meta model | 4 h |
| Job, enqueue points, retry, sweep changes | 4 h |
| Limit and filter | 1.5 h |
| Badge, meta box report, button | 4 h |
| `IssueText`, `FixMap`, German strings | 3 h |
| Settings field for API key | 1 h |
| Tests | 6 h |
| Real-site check against staging API | 1.5 h |
| Total | about 25 h |

## Rollback

Revert the PR. `_invoqk_validation`, `invoqk_free_slots` and `invoqk_api_key` are inert without the code. `Scheduler::deactivate()` already unschedules everything in group `invoqk`, including the new action. No schema change, no data migration.

## Files Reference

| File | Change |
|------|--------|
| `src/Validation/Client.php` | new: build request, call API, return parsed result or typed error |
| `src/Validation/Job.php` | new: handler for `invoqk_validate_order` |
| `src/Validation/Result.php` | new: read and write `_invoqk_validation` |
| `src/Validation/Limit.php` | new: free slots |
| `src/Validation/IssueText.php` | new: locale choice and fallback label |
| `src/Validation/FixMap.php` | new: `fix` key to link |
| `src/Jobs/Scheduler.php` | add `VALIDATE` hook, `enqueueValidate`, wire `wpo_wcpdf_save_document`, extend `sweep` |
| `src/Detection/Detector.php:11` | after a hit, enqueue validation when the file changed or no validation exists |
| `src/Admin/OrderColumn.php:30` | badge after the source label |
| `src/Admin/OrderBox.php:9` | context `normal`, full report, button |
| `src/Admin/SettingsPage.php:11` | API key field |
| `src/Plugin.php` | boot the admin-post handler |
| `languages/invoqk-de_DE.po` and `.mo` | new strings |
| `tests/Validation/*` | new tests |

## Out of Scope

- S5, AI rewrite of errors. Needs a provider choice, consent text and privacy review. Its own epic later (E14 candidate).
- A connect flow that creates per-site keys (open in E1). For now the merchant pastes a key.
- Showing the API's monthly `usage` in the plugin.
- Bulk validate and a "validate all old orders" tool.
- Blocking or warning before an invoice is sent (E6).
- Deciding which orders need an e-invoice (E4). E3 validates every order that has a file.
- Archive and Steuerberater export of results (E7).
- Writing the German rule texts and the `fix` keys. They belong to E1-S9.

## Open Questions

1. Exact auth header name and request body format of `POST /api/v1/einvoice/validate`. The API spec was not reachable from this repo.
2. Confirm the response field names: `verdict`, `ruleSet`, `issues[].bt`, `messageDe`, `fix`, `retryable`.
3. Final list of the 20 `fix` keys, with the WooCommerce or source-plugin target for each.
4. For ZUGFeRD and Factur-X PDFs, does the plugin send the whole PDF (API extracts the XML) or must it extract first? E1-S1 says the API accepts ZUGFeRD PDF, so the plan is to send the PDF as is.
5. Upgrade URL for the Free limit prompt until E8 exists.
