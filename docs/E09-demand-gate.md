# E9-S7: Demand gate record

Records the demand gate decision for the free web checker launch (E9-S7). Fill in the tables as data arrives; do not edit the rule after launch.

## Gate rule

Launch date: not set yet. **Locked on:** (date). The rule may be edited until the launch date is filled in; from the day "Locked on" is set, do not edit it.

Measured 4 weeks after launch.

- **Go:** all of these:
  - 300+ checks
  - 50+ confirmed emails
  - 40+ plugin downloads (WordPress.org, over the 4 weeks)
  - 10+ plugin trial signups
  - at least 10 of the 20 interviews done, and 5 of the interviews done say they would pay
- **Stop or rethink:** under 100 checks and under 15 confirmed emails.
- **Between** (or Go numbers met but fewer than 10 interviews done): extend 4 weeks and measure again.

> These numbers come from the design spec (`docs/superpowers/specs/2026-11-15-web-checker-launch-design.md`), revised by the owner: installs became downloads (wp.org shows exact downloads, but active installs only as a bucket such as "10+"), trial signups became a gate input, and the interview rule needs 10 done.

## Where each number comes from

- **Checks:** `checker_upload`. **Pass / Fail:** `checker_pass` and `checker_fail`. They do not add up to checks: unreadable files and rate-limited uploads count only as uploads. **Confirmed emails:** `lead_confirmed`. All come from the `DemandCounter` collection in the site repo (`~/nodejs/ai-invoice`).
- **Trial signups:** confirmed plugin trial users (`User.authProvider = "plugin_trial"`), counted by the same script.
- Both are printed per week, plus a total, by:
  `node --env-file=.env.local scripts/demand-report.ts --since YYYY-MM-DD` (use the launch date; weeks are 7-day blocks from it, UTC).
- **Plugin downloads:** WordPress.org plugin stats page for slug `invoqk` (downloads per day). Sum by hand per week.

## Weekly counts

| Week starting | Checks | Pass | Fail | Confirmed emails | Plugin downloads | Trial signups |
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

Interviews done: __ of 20 (need 10+). Would pay yes: __ of those done (need 5+).

Decision: go / stop / extend — date:
