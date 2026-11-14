# E5: Readiness dashboard & onboarding (P0)

Goal: first validation report in under 5 minutes after install.

## E5-S1: Run a setup wizard

As a new user, I want a short wizard after activation, so that setup takes minutes.

- Step 1: detects the invoice plugin in use and shows it.
- Step 2: asks for annual turnover (bands, for example under €800k, over €800k).
- Wizard can be skipped and reopened.

## E5-S2: Get an instant first report

As a new user, I want my last 20 B2B invoices validated automatically, so that I see value immediately.

- Validation starts when the wizard finishes.
- Progress is shown; the dashboard opens when results arrive.
- If no B2B invoices exist, an empty state explains what to do.

## E5-S3: See my readiness at a glance

As a shop owner, I want one screen showing how ready I am, so that I know where I stand.

- Share of B2B orders with a valid e-invoice in the last 30 days.
- Counts of passed, failed, missing and not-required.

## E5-S4: See the top errors

As a shop owner, I want the most frequent errors ranked, so that I fix the biggest problem first.

- Top 5 errors with count, BT code and German text, linking to the fix guidance (E3).

## E5-S5: Learn whether I must comply in 2027 or 2028

As a shop owner, I want to know my deadline, so that I can plan.

- Turnover over €800,000: "ab 1.1.2027"; otherwise "ab 1.1.2028".
- Turnover can be edited later.
- Disclaimer "Keine Rechts- oder Steuerberatung" is visible.

## E5-S6: Start the Pro trial from the dashboard

As a shop owner, I want a clear path to try Pro, so that I can turn on the guard and archive.

- Call to action leads into the trial flow (E8); locked features are labeled Pro.
