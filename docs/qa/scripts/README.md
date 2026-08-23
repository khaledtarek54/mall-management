# Pre-staging QA harness

Scenario scripts that drive the **real services against MySQL**, used for the
[pre-staging QA run](../PRE-STAGING-QA.md). They exist because the Pest suite runs on SQLite
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

## Gate mutation audit — does a green gate mean anything?

```bash
python3 docs/qa/scripts/gate-audit.py docs/qa/scripts/gate-mutations.json /tmp/gate-audit.json
```

Different from everything else here: it runs against the ordinary **SQLite Pest suite**, not the QA
database, and it does not test the app — it tests the **tests**.

There are 67 conformance gates, and until 2026-08-23 exactly one
(`ReconciliationChecksCanFailConformanceTest`) proved the checks it covers could actually go red. The
rest were trusted. That matters because a gate is how this project convinces itself an invariant
holds, and *"a gate can report on a set it has silently stopped collecting"* is its most repeated
defect — recorded five times before this audit, and twice more on the day it was written.

For each entry in the manifest the harness applies one mutation — **the real defect the gate names**,
never a syntax break — runs that gate alone, and requires it to fail. Then it restores the file. A
gate that stays green is a `HOLE`. It verifies each mutation actually LANDED before believing the
result, because a substitution that silently does not apply reports a false PASS, which has happened
twice in this project.

**Result of the first run: 19 mutations across 18 gates, one hole.**
`ValueSetCoverageConformanceTest`'s hand-written suffix list had drifted behind the registry it
guards — 10 of 156 registered columns were invisible to it, so a new column of any of those shapes
would have shipped unenforced with the gate silent. Fixed, and the list is now self-checking.

Two things worth knowing before adding a mutation:

- **An invalid mutation reads exactly like a weak gate.** Removing `abort_unless` from an action that
  also carries `->authorize()` leaves it gated, so `ActionAuthz` passed and looked holed. The
  double-gate is deliberate; the mutation has to remove both.
- **A gate can bucket its own target as noise.** The extended reorderable-columns sweep filed the
  blank-label exception it exists to catch under "could not mount" and passed. If a gate catches
  exceptions, check where its own defect lands.

The manifest is not exhaustive — 18 of 67 gates, chosen for money, authorization and property
isolation. Adding a gate to it is four strings.

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
| `A1_meters_inventory_assets.php` | modules 10 · 22 · 23 — dated tariffs, weighted-average cost, depreciation and disposal |
| `A2_payroll_custody_violations.php` | modules 13 · 24 · 25 · 31 — payroll, advances, عهدة, fines, marketing overspend |
| `B1_requests_facility_approvals.php` | modules 11 · 26 · 28 · 30 · 35 — request and work-order state machines, approval bands, zone routing, parking rungs |
| `C1_tenants_portal_comms.php` | modules 02 · 03 · 14 · 15 · 19 · 27 · 36 — party codes, the Arabic fold, portal draft-hiding, departments, announcements, owner requests, the shopper feed, the scans |
| `D1_api_reports_search.php` | modules 17 · 20 · 34 — every `/api/v1` endpoint, the public feed, all ten reports, global search on MySQL |
| `O01_payroll_cancel_is_by_design.php` | an observation, not a defect — see its docblock |
| `F01_…`, `F04_…`, `F08_…`, `F11_…` | isolated reproductions, one per finding |

Every refusal test is paired with a control that must succeed — a guard that refused everything would
otherwise read as a pass.
