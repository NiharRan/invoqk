# E11: Agency dashboard (P1, Agency)

Goal: let agencies watch many client shops from one place.

## E11-S1: Create an agency account

As an agency owner, I want an account on invoqk.com, so that I can manage my client shops.

- Sign up and log in; Agency plan covers up to 25 shops.

## E11-S2: Connect a shop

As an agency owner, I want to connect a client shop with a short code or link, so that setup is quick.

- Connect from the plugin; shop appears in the account.
- Connection can be revoked by either side.
- Seat count is enforced at 25.

## E11-S3: See all shops at a glance

As an agency owner, I want a fleet view, so that I see which clients need help.

- Table with per-shop readiness percentage, failed count, last sync and plugin source.
- Sort and filter by status.

## E11-S4: Drill into one shop's errors

As an agency owner, I want to see a shop's top errors, so that I can fix them.

- Top errors with counts in German, matching the plugin view.
- No customer personal data is shown, only counts and rule info.

## E11-S5: Export a white-label readiness report

As an agency owner, I want a PDF with my branding, so that I can send it to clients.

- Upload logo and name; PDF shows readiness, top errors and next steps.
- Generated per shop or for selected shops.

## E11-S6: Get alerts across the fleet

As an agency owner, I want one alert when a client has problems, so that I react before the client panics.

- Daily or weekly digest by email; threshold configurable.

## E11-S7: Protect client data

As a shop owner whose agency connects my shop, I want clear limits on shared data, so that I trust the connection.

- Only aggregate readiness and error data leave the shop; documented in the AVV/DPA.
