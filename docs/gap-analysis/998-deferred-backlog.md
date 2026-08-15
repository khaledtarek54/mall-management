# Deferred Backlog (D-1 through D-60)

> Consolidated from all 20 module audits. Use this to triage what gets fixed pre-pilot vs. post-pilot vs. won't-fix.
> Generated: 2026-05-31

## How to read

Each row links back to the originating module audit doc + the finding that produced it. The **Default** column is my recommendation; the **Tier** column is the user's tiering decision (to fill in).

Tier suggestions: **P0** = before demo · **P1** = before pilot · **P2** = post-pilot · **P3** = nice to have / won't fix.

## Pre-pilot / production-readiness (heavy hitters)

| # | Module | Finding | Recommended action | Tier |
|---|---|---|---|---|
| D-15 (RETRACTED) | M05/M07 | Cron scheduling | Already in routes/console.php; verified | — |
| D-48 | M17 F-63 | Default password `'password'` on every seeded user | Env-driven password + rotate-on-first-login | P1 |
| D-49 | M17 F-64 | No user self-service profile/password | Enable Filament `EditProfile` + `->passwordReset()` | P1 |
| D-50 | M17 F-65 | No 2FA | Integrate `laravel/fortify` or `pragma/laravel-2fa` | P1 |
| D-52 | M17 F-67 | No LogsActivity on User model | Add trait + role-sync hook (~20 LOC) | P1 |
| D-53 | M18 F-68 | Reports + ArAging gate only on module flag, not permission | Add `Auth::user()->can('reports.view')` check | P1 |
| D-54 | M18 F-69 | All Exporters sync (timeout risk on large datasets) | Flip to `database` queue once queue worker is live | P1 |
| D-58 | M20 F-74 | No queue-worker deployment doc | Write INFRA.md or expand README with supervisor/systemd + `schedule:run` cron + `storage:link` | P1 |
| D-59 | M20 F-75 | No activity-log retention | `Schedule::command('activitylog:clean')->monthly()` + config TTL | P1 |
| D-60 | M20 F-76 | `php artisan storage:link` reminder | Production checklist | P1 |
| D-17 | M05 F-24 / M08 D-17 | ETA mock=true is the default | Flip to false + supply 5 env vars + 3 Settings fields | P1 |
| D-25 | M08 F-34 | SubmitInvoiceToEta uses default 3 retries with no backoff | Explicit `$tries=1` + `backoff()` array | P1 |
| D-50 / D-65 | M17 F-65 | 2FA | (duplicate row — same as D-50 above) | P1 |
| D-13 | M04 F-20 | Lease Edit form lets `base_rent_monthly` drift from `Charge::amount` | Read-only on Edit + "Change rent" action that syncs both | P1 |
| D-44 | M15 F-59 | No tax_id format validation (Vendor + Tenant) | Add regex rule before ETA cutover; surfaces bad data | P1 |

## Demo / content tweaks

| # | Module | Finding | Recommended action | Tier |
|---|---|---|---|---|
| D-1 (applied) | M01 F-1 | Plaza Annex stub vs DEMO.md "66 % occupancy" | Keep + DEMO.md "switch to Haya Walk first" pre-step | ✅ done |
| D-2 (applied) | M01 F-2/F-3 | DEMO numbers drift from seeder | Deterministic seeder (`mt_srand`) + DEMO.md locked numbers | ✅ done |
| D-3 | M01 F-4 | MallStats MRR sparkline shows billed-in-month (drops 92 % current partial) | Relabel as "Billed this month" — 1-line string change | P0 |
| D-4 | M01 F-5 | `percentDelta(_, 0)` returns 100 | Return `null` + render `—` instead of `↑ 100 %` | P1 |

## Code / consistency cleanups

| # | Module | Finding | Recommended action | Tier |
|---|---|---|---|---|
| D-5 | M02 F-7 | API TenantResource exposes tax_id despite model `$hidden` | Add a one-line comment confirming intentional | P2 |
| D-9 | M02 F-11 | `Tenant::isDelinquent()` is tested but not surfaced in UI | Add badge/filter to TenantsTable OR remove the method | P1 |
| D-11 | M03 F-18 | UnitImporter status enum order doesn't match migration | Reorder for grep readability | P3 |
| D-12 (applied) | M04 F-19/F-21 | Lease form bypasses LeaseCreationService | `LeaseObserver` + `CreateLease::afterCreate` + `seedStandardCharges` static helper | ✅ done |
| D-14 | M04 | Tests for the form-bypass path | Already covered by new LeaseObserverTest 8 cases | ✅ done |
| D-18 | M06 F-25 | Per-row allocation can exceed invoice.balance | Add closure rule on `allocated_amount` repeater | P1 |
| D-19 | M06 F-26 | invoice_payment pivot has no cross-tenant guard | Model `saved` check that pivot rows are same tenant | P2 |
| D-20 | M06 F-27 | No PaymentTest | Post-sweep test pass | P2 |
| D-23 | M07 F-31 | Single CAM `total_actual_expense` — no categories | Product decision | P2 |
| D-23-bis | M07 F-30 | `cam:reconcile` annual cron is review-only (no `--auto-bill`) | Operator preference | P2 |
| D-24 | M08 F-33 | EtaCompliance Rejected tile counts invalid+rejected but filter is invalid only | Add multi-value filter OR repoint tile | P2 |
| D-26 | M08 F-35 | EtaCompliance Pending tile not clickable | Bundle with D-24 | P2 |
| D-27 | M08 | e2e assertion for new PDF ETA block text | Post-sweep test pass | P3 |
| D-28 | M09 F-36 | SlaSettings SLA props unused (service reads `config()`) | Wire service to Settings (B) — admin-tunable SLAs | P2 |
| D-29 | M09 F-37 | No maintenance notifications | Bundle notification design | P2 |
| D-30 | M09 F-38 | `auto_close_after_days` config never acted on | Add `maintenance:auto-close` command + daily schedule | P2 |
| D-36 | M12 F-48/F-50/F-51 | No void-locked declaration; no `cancelled` state; no re-submission | Product feature | P2 |
| D-37 | M12 F-49 | `declared_sales` plaintext | Out-of-scope until legal review | P3 |
| D-38 | M13 F-52 | No admin UI for MeterReadings | Add MeterReadingsRelationManager (~120 LOC) | P2 |
| D-39 | M13 F-53 | No UtilityMeter/MeterReading tests | Post-sweep test pass | P3 |
| D-40 | M13 F-54 | Q3 consumption→billing | Q3 roadmap | P3 |
| D-41 | M14 F-55 | `partially_applied` enum value referenced but unreachable | (A) remove dead filter or (B) extend enum + service | P2 |
| D-42 | M14 F-56 | No portal/owner credit-note visibility | Bundle with portal-financial detail (D-22) | P2 |
| D-43 | M15 F-58 | No scheduled auto-expire for vendor contracts | `vendors:expire-contracts` daily command | P2 |
| D-45 | M15 F-60 | No VendorTest | Post-sweep test pass | P3 |
| D-46 | M16 F-61 | No AssetTest | Post-sweep test pass | P3 |
| D-47 | M16 F-62 | No multi-property growth UX | UX decision | P3 |
| D-51 | M17 F-66 | `asset_user.role` free-form string | Product decision (enum / remove / document) | P2 |
| D-55 | M18 F-70 | No ReportService query caching | Until first scale event | P3 |

## Portal / mobile

| # | Module | Finding | Recommended action | Tier |
|---|---|---|---|---|
| D-6 | M02 F-8 | No password reset on tenant portal | Enable Filament `->passwordReset()` on portal | P1 |
| D-7 | M02 F-9 | No tenant self-service profile update | Bundle with M19 D-56 mobile API | P2 |
| D-8 | M02 F-10 | Mobile API auth-only today | Build the M19 shortlist | P2 |
| D-21 | M07 F-28 | Mid-year CAM termination accounting leak | Product decision | P2 |
| D-22 | M07 F-29 / M11 F-43 | No portal CAM allocation view | Add to portal post-pilot | P2 |
| D-31 | M10 F-40 | Owner Portal Resources have no nav badges | Apply — small change, real value | P2 |
| D-32 | M10 F-41 | Owner role granted `cam.view` but no Owner CAM resource | Add view OR remove permission | P2 |
| D-33 | M11 F-42 | "Pay Now" is a stub | Paymob / InstaPay integration before pilot | P1 |
| D-34 | M11 F-44 | No notification to tenant on sales-declaration lock | Bundle with D-29 notification design | P2 |
| D-35 | M11 F-45 | No "download 12-month statements ZIP" | Low priority | P3 |
| D-56 | M19 F-71 | `/api/v1/me/*` endpoints unbuilt | Approve shortlist + build (M19 §3 — ~1650 LOC + 30 cases) | P2 |
| D-57 | M19 F-72 | Bundle password reset across portal + API | Same flow underneath | P1 |

## ✅ Already applied during the audit

D-1, D-2, D-12, D-14 (covered by D-12). 8 inline fixes total committed across modules 01-15.

## Quick triage suggestion

If we hit a single Friday and want a focused pre-pilot stress sprint, the highest-leverage subset is:

1. **D-48** + **D-49** + **D-52** (user password + self-service + audit log) — security baseline
2. **D-58** + **D-60** (queue worker docs + storage:link) — deploy day
3. **D-17** + **D-25** (ETA prod + retry policy) — money path safety
4. **D-3** + **D-4** (MallStats MRR sparkline + percentDelta) — demo polish
5. **D-13** (Lease rent drift) — production-readiness for ops staff

That's about 1.5 dev-days of focused work and would move 7-8 Yellow findings to Green.
