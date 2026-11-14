# Invoqk E-Rechnung QA & Archive: Epics

Source: PRD v0.1 (14 Nov 2026). Priorities and tiers come from the PRD. Phase 1 (MVP) is all P0.

## Overview

| Epic | Name | PRD items | Priority | Tier |
| --- | --- | --- | --- | --- |
| E1 | Validation API & infrastructure | F2, architecture, NFRs | P0 | Free + Pro |
| E2 | Plugin foundation & source detection | F1, compatibility | P0 | Free |
| E3 | Validation results & plain-German errors | F2 (plugin side), F3 | P0 | Free |
| E4 | B2B/B2C classifier | F4 | P0 | Free |
| E5 | Readiness dashboard & onboarding | F8, onboarding flow | P0 | Free |
| E6 | Pre-send guard | F5 | P0 | Pro |
| E7 | Archive & Steuerberater export | F6, F7 | P0 | Pro |
| E8 | Licensing, payments & trial | Pricing section | P0 | Pro |
| E9 | Free web checker & launch | GTM 1-3 | P0 (ships Dec 2026) | Free |
| E10 | B2G & credit notes | F9, F10 | P1 | Pro |
| E11 | Agency dashboard | F11 | P1 | Agency |
| E12 | Fallback generator | F12 | P2 | Pro |
| E13 | Go-to-market & partnerships | GTM 4-6 | Ongoing | n/a |
| E14 | Later phases (Peppol, DATEV, Shopify) | Non-goals / roadmap | Phase 3+ | n/a |

Suggested build order: E1 → E9 (web checker first, deadline 1 Jan 2027) → E2 → E3 → E4 → E5 → E8 → E6 → E7 → launch (end Mar 2027) → E10 → E11 → E12.

---

## E1: Validation API & infrastructure (P0)

Goal: one EU-hosted API that validates XML with the official KoSIT rules; shared by plugin, web checker, Sheets add-on, later Shopify.

- Extend the existing `nodejs/ai-invoice` app (spec: `nodejs/ai-invoice/docs/specs/invoqk-validation-api.md`). It already runs the compiled KoSIT Schematron in Node (Saxon-JS, no Java) behind `POST /api/v1/einvoice/validate`.
- Add XSD validation, BT codes and German messages, rule set version in every response.
- Per-site API keys, monthly metering, rate limits, no admin credentials stored.
- Deploy on cPanel (standalone Node build): health endpoint, cron jobs, memory check, restart and rollback steps.
- Pinned validator versions; update rule sets within 2 weeks of a KoSIT/ZUGFeRD release.
- Delete invoice XML after validation unless server-side archive is on (already true for validation calls).
- speedata/einvoice as a CI cross-check only; any pass/fail difference is reviewed.
- Availability 99.5%; validation under 10 s at p95.

Acceptance: sample XRechnung and ZUGFeRD files return correct reports; marketing claim "official KoSIT rules" only once XSD validation is live.

Open: XSD library for cPanel; plan mapping and per-site key creation; ZUGFeRD PDF/A-3 scope; cPanel memory limit.

## E2: Plugin foundation & source detection (P0)

Goal: thin PHP plugin that finds each order's e-invoice regardless of generator.

- Plugin scaffold: WP 6.5+, WooCommerce 9.0+, PHP 8.1+, HPOS declared, block checkout, multisite-safe, WPML/Polylang neutral, German and English UI.
- Adapters for Germanized Pro, German Market, WP Overnight (attachment or order meta); one adapter per plugin, integration tests against each plugin's latest version.
- Show which plugin produced the invoice; flag orders with no e-invoice.
- Action Scheduler for background work; checkout and order pages never wait on the API.
- Admin shell (React admin screens), "no legal or tax advice" notice.

## E3: Validation results & plain-German errors (P0)

Goal: merchant sees pass/fail per invoice and understands why.

- Plugin sends XML to API, stores and shows result per order.
- Free tier limit: last 20 invoices; Pro unlimited (enforced via license).
- Each failed rule shows BT code, German sentence ("BT-10 Käuferreferenz fehlt") and where to fix it in WooCommerce.
- Optional AI rewrite behind a toggle.
- All error texts translatable.

## E4: B2B/B2C classifier (P0)

Goal: mark each order "E-Rechnung erforderlich" or "nicht erforderlich".

- Inputs: VAT ID, company field, billing country, €250 threshold.
- Per-order manual override.
- Feeds the guard (E6) and dashboard (E5).

## E5: Readiness dashboard & onboarding (P0)

Goal: first validation report in under 5 minutes.

- Setup wizard: detect invoice plugin, ask annual turnover.
- Auto-validate last 20 B2B invoices after install.
- Dashboard: share of B2B orders with a valid e-invoice (last 30 days), top errors, "required from 2027 or 2028?" from turnover.
- Entry point to the 14-day Pro trial.

## E6: Pre-send guard (P0, Pro)

Goal: no invalid B2B e-invoice reaches a customer unnoticed.

- Hook invoice emails (`woocommerce_email_*`, order status changes); hold when validation fails on a B2B order.
- Notify admin by email and admin notice; "fix and resend" action.
- Switchable to warn-only.
- API down: fall back to warn-only and retry.
- B2C orders pass straight through.

## E7: Archive & Steuerberater export (P0, Pro)

Goal: complete, tamper-evident archive the tax advisor can use.

- Store XML and PDF with SHA-256 hash chain, upload timestamp, order reference.
- No UI editing; deletion is logged; retention default 8 years.
- Export: monthly or date-range ZIP with all XML/PDF plus CSV index (invoice no., date, customer, net, VAT, gross, validation status).
- 5 GB storage per shop included.

Open: archive location (merchant WordPress, Invoqk servers, or both); whether to use the word "GoBD" in marketing.

## E8: Licensing, payments & trial (P0)

Goal: sell Free, Pro (€79), Pro 3 (€149), Agency (€399).

- Decide payment provider first (Freemius vs Lemon Squeezy plus self-hosted license checks); confirm Bangladesh payout.
- License key checked against the API; per-site API key; webhook updates license status.
- In-plugin 14-day Pro trial; plan gating of features.
- Keep license logic in own API so switching provider only touches checkout.

## E9: Free web checker & launch (P0, ships December 2026)

Goal: first deliverable, before 1 Jan 2027.

- invoqk.com page: upload XRechnung/ZUGFeRD, get German validation report via the same API; email capture.
- German SEO content pages (E-Rechnung WooCommerce 2027, XRechnung Germanized validieren, ZUGFeRD Fehler BT-10, one guide per incumbent plugin).
- WordPress.org listing (DE/EN, screenshots); submit early to reserve the slug "invoqk".
- Demand gate: 20 merchant interviews.

Open: EU trademark for Invoqk.

## E10: B2G & credit notes (P1, Pro)

- F9: Leitweg-ID / buyer reference field at checkout (block and classic), written as BT-10.
- F10: flag refunds on B2B orders needing a corrective e-invoice; validate it once created.

## E11: Agency dashboard (P1, Agency)

- Connect up to 25 shops to one invoqk.com account.
- Fleet view of readiness and errors.
- White-label PDF report.

## E12: Fallback generator (P2, Pro)

- Basic XRechnung/ZUGFeRD for orders where no invoice plugin produced one. Not a replacement for existing generators.

## E13: Go-to-market & partnerships (ongoing)

- Recruit 5-10 agencies and 2-3 Steuerberater as design partners (free Agency plan, then 30% affiliate).
- Communities: WooCommerce DACH groups, WordCamp/Meetup DE, r/woocommerce, German newsletters.
- Cross-sell to Invoqk web app and Sheets add-on users, Twinspot merchants.
- Track success metrics (3,000 installs, 100 paying shops, ~€10k ARR, 65% renewal, under 0.3 tickets/shop/month).

## E14: Later phases (out of scope for v1)

- Peppol sending, DATEV export (add-on), Shopify app (ready before 1 Jan 2028).
- Incoming supplier e-invoices stay out of scope.
- Possible offline check in plugin via en16931-php.

---

## Cross-cutting (apply to every epic)

- Privacy: EU hosting, AVV/DPA, XML deleted after validation unless archive on.
- Localization: German first, English second.
- Compatibility: HPOS, block checkout, multisite.
- Risks to track: incumbents adding validation, order-meta changes by incumbents, rule-set updates, payment payout, merchants reading results as legal advice.
