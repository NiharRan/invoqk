# E1: Validation API & infrastructure (P0)

Goal: one EU-hosted API that validates e-invoice XML with the official KoSIT rules, shared by the plugin, web checker, Sheets add-on and later the Shopify app.

Approach: extend the existing `nodejs/ai-invoice` app instead of building a new service. It already runs the compiled KoSIT Schematron rules in Node (Saxon-JS, no Java) behind `POST /api/v1/einvoice/validate` with API keys and quotas. It is deployed on cPanel. Full technical spec: `nodejs/ai-invoice/docs/specs/invoqk-validation-api.md` (work items W1 to W9).

Already in place: Schematron validation for XRechnung, Peppol BIS and EN 16931 (UBL and CII), ZUGFeRD PDF XML extraction, API keys, daily quota, rate limits, bad-key limiting, no storage of uploaded invoices, pinned rule set version.

## E1-S1: Validate an XML invoice

As a plugin or web client, I want to POST an invoice XML and get a structured pass/fail report, so that I can show the result to a merchant.

- Uses the existing `POST /api/v1/einvoice/validate`; accepts XRechnung (UBL), Peppol BIS, EN 16931 CII and ZUGFeRD PDF.
- Response has verdict, list of failed rules with rule ID, BT code, severity, XPath location, English text and German message where known.
- Result returned within 10 s at p95, including under a burst of requests on the cPanel host.
- Unsupported or malformed input returns a clear 4xx error, not a 500.
- Spec: W2, W6.

## E1-S2: Run the official KoSIT rules

As the Invoqk team, I want the API to run the KoSIT XRechnung and EN 16931 rules, so that results match the official validator.

- Rules come from the compiled KoSIT configuration (XRechnung 3.0.2, release 2026-08-31), as today.
- Every response reports the rule set name, version and configuration date (W3).
- Rule sets are updated within 2 weeks of a KoSIT/ZUGFeRD release, using a documented routine (W9).
- "Validated with the official KoSIT rules" is only used once XSD validation (E1-S8) is live.

## E1-S3: Authenticate sites with per-site keys

As the Invoqk team, I want one API key per connected shop, so that only licensed sites can use the API and a single shop can be cut off.

- A key is tied to a site host; use from another host returns 403 (`wrong_site`).
- Requests without a valid key return 401; revoked keys stop working at once.
- Agency accounts can hold up to 25 keys.
- No WordPress admin credentials are ever stored. Spec: W4.

## E1-S4: Rate limit and meter usage

As the Invoqk team, I want rate limits and per-site monthly usage counts, so that we can prevent abuse and enforce plan limits.

- Existing daily quota and bad-key limits stay as abuse guards.
- Monthly count per account and shop: Free 100, Pro and Agency 2000 calls. Returned as `usage: { used, limit, period }` in the validate response and in MCP replies; stored in MongoDB.
- Production uses the Redis-backed limiter, because several Node processes on cPanel do not share memory.
- Exceeding a limit returns 429 `quota_exceeded` with a `Retry-After` header. Spec: W5.

## E1-S5: Delete invoice data after validation

As a merchant, I want my invoice XML deleted after validation unless I turned on server-side archive, so that personal data is not kept needlessly.

- Validation calls store no invoice content, as today.
- Logs contain no invoice content.
- Verified by an automated test.

## E1-S6: Cross-check with speedata/einvoice in CI

As the Invoqk team, I want sample invoices run through both our validator and speedata/einvoice, so that we notice differences when rules change.

- A script compares pass/fail for a folder of samples (the existing test drafts and the Add-on sample set).
- Run before each rule-set update; any difference is reviewed.
- speedata is never part of the runtime and its results are never labeled "official KoSIT". Spec: W7.

## E1-S7: Deploy on cPanel and stay available

As a merchant, I want the API to be reliable, so that the guard does not block my invoices.

- Standalone build (`NEXT_OUTPUT=standalone`) runs as a cPanel Node.js app; the compiled rule files are included for the v1 validate route.
- Memory use of the compiled rules measured against the host's limit.
- Public `GET /api/health` checks that a rule file loads; uptime monitoring with an alert; documented restart and rollback.
- Cron jobs from `vercel.json` recreated as cPanel cron jobs.
- Failures that mean "could not check" are marked `retryable` so clients fall back to warn-only. Spec: W6, W8.

## E1-S8: Validate against the XML schema

As the Invoqk team, I want XSD validation before the Schematron rules, so that structurally broken files cannot pass.

- UBL 2.1 and CII D16B schema checks run first; failures come back as issues with id `XSD`.
- A test file that passes Schematron but violates the schema now fails.
- Library chosen in a spike and confirmed to run on the cPanel host. Spec: W1.

## E1-S9: Show errors in plain German

As a merchant, I want error messages with BT codes and German text, so that I understand what to fix.

- Each issue carries `bt` codes, `messageDe` (null if not yet written) and a `fix` key the plugin can map to a settings link.
- The 20 most common failures have German text at launch; unknown rules fall back to the English text.
- A test checks that every mapped rule id exists in the compiled rules. Spec: W2.

## Open questions

- Which XSD library runs on the cPanel host?
- Plan mapping is decided (Pro and Pro 3 = `PRO`, Agency = `BUSINESS_EU`); still open: should the plugin get a connect flow that creates the per-site keys, or do shop owners keep creating them in Settings?
- Is ZUGFeRD PDF/A-3 conformance in scope, or only the embedded XML?
- Is the per-process memory limit on the cPanel host enough for the compiled rules?
