# E6: Pre-send guard (P0, Pro)

Goal: never let an invalid B2B e-invoice reach a customer unnoticed.

## E6-S1: Hold invalid B2B invoice emails

As a B2B shop owner, I want the invoice email held when validation fails, so that the customer never gets a broken e-invoice.

- Applies only to orders classified "erforderlich" (E4) with status failed.
- The invoice email is blocked via the WooCommerce email hooks; B2C and passing orders go out normally.
- The order shows a "held by Invoqk" state with the reason.

## E6-S2: Validate before the email goes out

As a B2B shop owner, I want validation to finish before sending, so that the guard decides on a real result.

- If a result is not ready, the email waits a short time, then the guard handles it per E6-S6.
- Checkout and order pages are not slowed down.

## E6-S3: Get notified when something is held

As a shop owner, I want to be told right away, so that I can fix it.

- Email to the admin address and a WordPress admin notice with a link to the order.
- Notifications are batched so a bad run does not send hundreds of mails.

## E6-S4: Fix and resend

As a shop owner, I want to correct the problem and send the invoice from the order, so that the customer gets a valid e-invoice quickly.

- "Fix and resend" re-runs detection and validation.
- If it passes, the held email is sent; if not, the new errors are shown.

## E6-S5: Switch to warn-only

As a shop owner, I want to choose warn-only mode, so that nothing is ever blocked if I prefer that.

- Setting: block or warn-only. In warn-only, the email is sent and the admin is notified.

## E6-S6: Fall back when the API is down

As a shop owner, I want invoices not to be stuck during an outage, so that business continues.

- On API error the guard switches to warn-only for that order and retries validation.
- Admin sees that validation was skipped.

## E6-S7: Release a held invoice manually

As a shop owner, I want to send a held invoice anyway, so that I stay in control.

- "Send anyway" needs a confirmation and is logged with user and time.
