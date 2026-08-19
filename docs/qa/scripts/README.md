# Pre-staging QA harness

Scenario scripts that drive the **real services against MySQL**, used for the
[pre-staging QA run](../../PRE-STAGING-QA.md). They exist because the Pest suite runs on SQLite
`:memory:`, where row locks compile to nothing and a single connection never interleaves — so three of
the findings in that report are unreachable from it by construction.

## Setup (once)

```bash
mysql -h127.0.0.1 -uroot -e "create database mall_management_qa character set utf8mb4 collate utf8mb4_unicode_ci"
DB_DATABASE=mall_management_qa php artisan migrate:fresh --seed --force
mysqldump -h127.0.0.1 -uroot --single-transaction --no-tablespaces mall_management_qa > docs/qa/scripts/baseline.sql
```

`baseline.sql` is **not committed** — regenerate it locally. `reset.sh` restores it so every script
starts from the same seeded state and results are deterministic.

## Running

```bash
docs/qa/scripts/run.sh 10_leasing_billing.php            # reset, then run
docs/qa/scripts/run.sh 40_month_cycle.php --no-reset     # continue on the current state
docs/qa/scripts/race.sh lease "44,1"                     # two-process concurrency race
```

`boot.php` **refuses to run unless the connection is `mall_management_qa`**, so a mistyped
`DB_DATABASE` cannot touch your working database.

## What each script covers

| Script | Covers |
|---|---|
| `00_baseline.php` | dataset shape + opening tie-out |
| `01_spacing.php` | units, dated area register, occupancy projection, deletion policy |
| `02_spacing_owners.php` · `03_resale_proration.php` | unit-owner assessments, co-ownership, resale (**F-02**) |
| `14_premises_dates.php` | date-aware occupancy (**F-05**) |
| `10_leasing_billing.php` | VAT, proration on both edges, cadence, fit-out grace, idempotency |
| `11_leasing_lifecycle.php` | escalation ladder, collars, extension, renewal, double-booking |
| `12_leasing_termination.php` | unearned credit, move-out settlement, holdover |
| `13_leasing_pctrent_relief.php` | percentage rent (4 types), bounded relief, premises change |
| `20_ar_channels.php` · `21_ar_reversals.php` | the four settlement channels; voids, write-offs, late fees, PDCs |
| `30_payables.php` · `31_procurement.php` | vendor bills, withholding tax, SLA penalties, GRNI |
| `40_month_cycle.php` | a whole month end-to-end against the financial statements, on seeded data |
| `41_lease_options.php` | every option type — record, encumber, exercise, waive, lapse, window scan, renewal hand-off |
| `42_full_month_all_shapes.php` | **the full month**: one lease of every shape + every option type, billed, collected, closed and reconciled |
| `50_page_sweep.php` · `51_search_rbac.php` · `52_rbac_matrix.php` | every page, global search, the role × screen matrix (**F-06**) |
| `53_property_isolation.php` · `54_cross_property_money.php` | isolation reads, writes and cross-property money |
| `60_cam.php` · `F08*` | CAM apportionment, caps, admin fee, and the stated-share over-recovery (**F-08**) |
| `70_form_interactions.php` | the `->live()` form callbacks |
| `90_deposits_owner.php` · `91b_owner_correct.php` | the deposit billing rail (**F-11**), owner statements |
| `race_worker.php` + `race.sh` | two-process concurrency (**F-09**, **F-10**) |
| `F01_…`, `F04_…`, `F08_…`, `F11_…` | isolated reproductions, one per finding |

Every refusal test is paired with a control that must succeed — a guard that refused everything would
otherwise read as a pass.
