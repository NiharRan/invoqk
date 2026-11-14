# E8: Licensing, payments & trial (P0)

Goal: sell Free, Pro (€79/yr), Pro 3 (€149/yr) and Agency (€399/yr), and gate features by plan.

## E8-S1: Choose the payment provider

As the Invoqk team, we want the provider decided before build, so that we do not rebuild checkout later.

- Confirm whether Freemius can pay out to Bangladesh.
- If not, use Lemon Squeezy plus self-hosted license checks.
- Decision and reasons recorded.

## E8-S2: Start a 14-day Pro trial in the plugin

As a shop owner, I want to try Pro without a card, so that I can see the guard and archive working.

- Trial starts from inside the plugin; Pro features unlock at once.
- Trial end date visible; reminders at day 10 and day 13.

## E8-S3: Buy a plan

As a shop owner, I want to buy a plan from the plugin, so that I keep Pro after the trial.

- Checkout offers Pro, Pro 3 and Agency with correct prices and shop counts.
- After payment the license is active without a manual step.

## E8-S4: Enter and check a license key

As a shop owner, I want to enter my key and see its status, so that I know what I have.

- Key is checked against the Invoqk API; status shows plan, shops used and renewal date.
- Per-site API key is issued on activation.

## E8-S5: Gate features by plan

As the Invoqk team, we want features locked to the right plan, so that the free tier funnels to Pro.

- Free: detection, last 20 validations, German errors, classifier, dashboard.
- Pro: unlimited validation, guard, archive, export, Leitweg-ID.
- Locked features show an upgrade prompt, not an error.

## E8-S6: Keep license status in sync by webhook

As the Invoqk team, we want payment events to update licenses automatically, so that renewals, refunds and cancellations take effect.

- Webhook handles purchase, renewal, failed payment, cancel and refund.
- Webhooks are verified and idempotent.

## E8-S7: Handle expiry gracefully

As a shop owner, I want predictable behavior when my license ends, so that nothing is lost.

- On expiry, guard and new archiving stop; existing archive stays readable and exportable.
- Renewal reminders before expiry.

## E8-S8: Move a license between sites

As a shop owner, I want to deactivate a site and use the seat elsewhere, so that staging or migrations do not cost a seat.

- Deactivate from the plugin or from the account page.
