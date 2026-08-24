# Atriom — the staging cutover runbook

**What this is:** the order. Nothing here is new information — every step links to the document that
owns it. It exists because the three documents that hold the real content are organised by *topic*
(`STAGING.md` = the box, `../STATUS.md` = credentials and decisions, `PRE-STAGING-QA.md` = the gates)
and nothing was organised by *sequence*. On the day, sequence is what you need.

**Read it top to bottom once before starting.** Two of the steps are decisions someone other than
the person at the keyboard has to make, and finding that out at step 6 is what turns a cutover into
a second cutover.

> **The single command:** `php artisan atriom:preflight`. It runs the health check and all three data
> gates in order and exits non-zero if any fails. Steps 5 and 8 below are both just that command.
> It is **read-only** unless you pass `--sync`.

---

## 0. Before anyone touches a box

Two things block, and neither is engineering:

| | Who answers | Where |
|---|---|---|
| The **posture** — demo data or a production restore? Everything downstream forks here | Operator | [STAGING.md §1](STAGING.md) |
| The **credentials** — backup archive password, alert email, an off-box backup disk, Sentry DSN, ops-log webhook | Operator | [../STATUS.md §1](../STATUS.md) |

Two more are decisions that do not block the box coming up, but do block the first real month, so
raise them now rather than discovering them in a month-end review:

- **Price the returned-cheque fee** (`BillingSettings::nsf_fee_amount`, per property). It ships at
  **0** on purpose — a money default the lease may not permit, and Yardi ships its NSF charge unset
  for the same reason. Price it to recover the bank's own returned-cheque charge plus an
  administrative component. [STATUS §4, C-NSF](../STATUS.md).
- **Rule which supplies carry stamp or schedule tax.** Both families are now commissioned and
  active, and both post to their own accounts. But a tax code taxes nothing until a **charge code**
  points at it, and that is the accountant's ruling — a row, no deploy.
  [STATUS §4, C-TAX](../STATUS.md).

---

## 1. Provision and configure

[INFRASTRUCTURE.md §10](INFRASTRUCTURE.md) for the box, then the `.env` deltas in
[STAGING.md §2](STAGING.md). `APP_ENV=staging` is a **third tier**, not "production-ish" — read
[STAGING.md §3](STAGING.md) for what it actually switches, because an unanticipated env name inherits
the stricter treatment.

**Ship the MySQL client in the image.** `mysqldump` must resolve for the app user or
`backup_capability` fails and no backup is ever taken. This is the one thing that has failed on
every box so far.

## 2. Bring the application up

The command sequence is [STAGING.md §4](STAGING.md). Do not skip `npm run build` — it builds the app
assets **and** the handbook, and both panels are unstyled without it.

## 3. Install cron and the queue worker

**This is the step that gets forgotten, and it is silent.** Around thirty behaviours — monthly
billing, the owner assessment run, escalations, the ledger sweep, `billing:reconcile`, `leases:expire`
— are inert without them, and none of them fails loudly when they simply never run. You find out at
month end, from a tenant.

`atriom:health` reports both (`scheduler`, `queue`) and step 5 will fail until they are up.

## 4. Load the data — posture B only

Skip on posture A (demo data). On a production restore, load in this order and run the audit named
beside each, **before** moving on:

| Load | Then run | Because |
|---|---|---|
| Tenants, vendors, units, leases | `atriom:audit-charge-schedules` | Overlapping charge rows bill **nothing**; a row with no start date never bills at all |
| Unit ownerships + their assessment schedules | *(same)* | An ownership with no schedule is reported as an unremarkable `skipped` and its owner is never billed. Import via **Import assessments** on the ownerships list |
| Opening balances / opening invoices | `atriom:audit-property-dimension` | A money document filed against no property shows on **every** mall and reaches **no** owner statement |

Both audits exit non-zero, so they compose with `&&` in a load script.

## 5. Preflight

```bash
php artisan atriom:preflight --sync     # --sync after a restore: backfills the ledger, WRITES
php artisan atriom:preflight            # then again read-only — this is the one that has to be clean
```

Run it **twice**, in that order. The first pass backfills ledger entries for documents the restore
brought in; the second pass is the actual gate, and it has to pass without writing anything. A
preflight that only ever passes *while repairing* is not a gate.

Expected failures on a correct staging box are listed in [STAGING.md §5](STAGING.md) — several
`atriom:health` rows are **supposed** to be red on posture A, and treating them as blockers is how a
cutover stalls on nothing.

## 6. Prove the backup

```bash
php artisan backup:run
php artisan atriom:backup-verify        # replays the newest archive into a scratch DB
```

`backup:monitor` only checks that an archive **exists**. `atriom:backup-verify` is the only evidence
that it can be restored, and it is scheduled weekly for that reason.

## 7. Close the demo doors — posture B only

Rotate or delete every seeded account **before the URL is shareable** ([../STATUS.md §1](../STATUS.md)).
They all share one password. `atriom:health`'s `demo_accounts` row is red until you do, and on a box
holding real data that row is not an expected failure.

## 8. Hand over

- `php artisan atriom:preflight` — clean except the `atriom:health` rows [STAGING.md §5](STAGING.md)
  names as expected.
- Point an uptime monitor at **`/health`**, not `/up`. `/up` returns 200 with the database down.
  **Not on posture A** — it is red by design there, and a monitor that is always red is a monitor
  nobody reads, including the production one beside it.
- Read [STAGING.md §6](STAGING.md), *What staging cannot tell you*, and take it seriously. A green
  staging box is not a green production one: Paymob has never charged a real card, opening balances
  are not loaded, and the test suite runs on sqlite while the box runs MySQL.

---

## What no command can check

The full-month dry run **on the operator's own data**. Everything in
[PRE-STAGING-QA.md](../qa/PRE-STAGING-QA.md) — 17 lease shapes billed through one month and one close, all
7 option types, every module — ran against generated demo data. What that cannot surface is what the
operator's own data does: their charge-code spellings, their unit areas, their historical dates,
their CAM shares.

The two audits in step 4 exist for exactly that moment, and until they have run against a real
extract they have only ever been asked about data we made up. **Budget for a month-end rehearsal on
staging with real data before the first real month runs in production**, not for a smoke test.
