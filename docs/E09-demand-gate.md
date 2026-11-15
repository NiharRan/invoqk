# E9-S7: Demand gate record

Records the demand gate decision for the free web checker launch (E9-S7). Fill in the tables as data arrives; do not edit the rule after launch.

## Gate rule

Measured 4 weeks after launch (launch date: not set yet, fill in when known).

- **Go:** 300+ checks, 50+ confirmed emails, 20+ plugin installs, and 5 of 20 interviewees say they would pay.
- **Stop or rethink:** under 100 checks and under 15 confirmed emails.
- **Between:** extend 4 weeks and measure again.

> These numbers are the controller's proposal from the design spec (`docs/superpowers/specs/2026-11-15-web-checker-launch-design.md`). The owner may change them before launch.

## Where each number comes from

- **Checks:** `checker_upload`, with the `checker_pass` and `checker_fail` split. **Confirmed emails:** `lead_confirmed`. Both come from the `DemandCounter` collection in the site repo (`~/nodejs/ai-invoice`), read with:
  `node --env-file=.env.local scripts/demand-report.ts --since YYYY-MM-DD`
- **Plugin installs:** WordPress.org plugin stats page for slug `invoqk`.
- **Trial signups:** plugin trial signups, counted in the site DB. Owner reads from the admin/DB; no script yet.

## Weekly counts

| Week starting | Checks | Pass | Fail | Confirmed emails | Plugin installs | Trial signups |
|---|---|---|---|---|---|---|
| Week 1: | | | | | | |
| Week 2: | | | | | | |
| Week 3: | | | | | | |
| Week 4: | | | | | | |
| **Total** | | | | | | |

## Merchant interviews (20)

| # | Date | Role | Shop size | Would pay (yes/no) | Note |
|---|---|---|---|---|---|
| 1 | | | | | |
| 2 | | | | | |
| 3 | | | | | |
| 4 | | | | | |
| 5 | | | | | |
| 6 | | | | | |
| 7 | | | | | |
| 8 | | | | | |
| 9 | | | | | |
| 10 | | | | | |
| 11 | | | | | |
| 12 | | | | | |
| 13 | | | | | |
| 14 | | | | | |
| 15 | | | | | |
| 16 | | | | | |
| 17 | | | | | |
| 18 | | | | | |
| 19 | | | | | |
| 20 | | | | | |

Would pay yes: __ of 20

Decision: go / stop / extend — date:
