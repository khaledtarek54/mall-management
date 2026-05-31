# Module 01 — Dashboard & Widgets

> Date: 2026-05-31
> Status: 🟡 Yellow — code/tests healthy; 3 deferred decisions about demo content & sparkline semantics
> Surface: [app/Filament/Admin/Widgets/](../../app/Filament/Admin/Widgets/) (13 widgets) + dashboard pages

## 1. Inventory

### 1.1 Widgets (registered explicitly in AdminPanelProvider:61-77, order matches the dashboard layout)

| Sort | Widget | Type | Models | Tenancy | Key methods | Tests |
|---:|---|---|---|---|---|---|
| -1 | [SetupGuide](../../app/Filament/Admin/Widgets/SetupGuide.php) | Widget (Blade) | Unit, Tenant, Lease, Invoice | Filament tenant pivot | `getViewData` (4-step onboarding checklist) | UncoveredWidgetsTest |
| 0 | [ActionRequired](../../app/Filament/Admin/Widgets/ActionRequired.php) | Widget (Blade) | Invoice, Lease, Unit, MaintenanceRequest | `TenantScope::currentAssetId` | `getViewData` (7 deep-linked cards) | ActionRequired{DeepLinks,ModuleGate}Test |
| 1 | [MallStats](../../app/Filament/Admin/Widgets/MallStats.php) | StatsOverview | Invoice, Lease, Payment, Unit | `TenantScope::currentAssetId` | `getStats` (4 KPIs w/ 6-month sparklines) | UncoveredWidgetsTest, WidgetScopingTest |
| 2 | [LeasingPipeline](../../app/Filament/Admin/Widgets/LeasingPipeline.php) | StatsOverview | Lease | `TenantScope::applyTo` | `getStats` (Draft/Pending/Active/Renewed) | UncoveredWidgetsTest |
| 3 | [ArAging](../../app/Filament/Admin/Widgets/ArAging.php) | Chart | Invoice | `TenantScope::applyTo` | `getData` (5 aging buckets + invoice count in tooltip) | UncoveredWidgetsTest |
| 4 | [TenantMix](../../app/Filament/Admin/Widgets/TenantMix.php) | Chart | Lease | `TenantScope::currentAssetId` | `getData` (doughnut by category) | UncoveredWidgetsTest |
| 5 | [ExpiringLeases](../../app/Filament/Admin/Widgets/ExpiringLeases.php) | Table | Lease | `TenantScope::applyTo` | `getColumns`/`table` (≤90-day expiring, color-coded) | UncoveredWidgetsTest |
| 6 | [TopTenants](../../app/Filament/Admin/Widgets/TopTenants.php) | Table | Lease, TenantSalesDeclaration | `TenantScope::applyTo` | `table` + `salesDensityFor` (density = sales ÷ sqm) | UncoveredWidgetsTest |
| 7 | [EtaCompliance](../../app/Filament/Admin/Widgets/EtaCompliance.php) | StatsOverview | Invoice | `TenantScope::applyTo` | `getStats` (4 stats: Valid/Submitted/Rejected/Pending) | UncoveredWidgetsTest |
| 8 | [MonthlyRevenueTrend](../../app/Filament/Admin/Widgets/MonthlyRevenueTrend.php) | Chart | Invoice, Payment | `TenantScope::currentAssetId` | `getData` (12mo bars + collection-rate line on 2ndary axis) | UncoveredWidgetsTest |
| 9 | [RecentPayments](../../app/Filament/Admin/Widgets/RecentPayments.php) | Table | Payment | `TenantScope::applyTo` | `getColumns`/`table` (last 8 captured) | UncoveredWidgetsTest |
| 10 | [OpenMaintenanceRequests](../../app/Filament/Admin/Widgets/OpenMaintenanceRequests.php) | Table | MaintenanceRequest | `TenantScope::applyTo` | `getColumns`/`table` (priority-ordered) | UncoveredWidgetsTest |
| 11 | [EnergyConsumptionTrend](../../app/Filament/Admin/Widgets/EnergyConsumptionTrend.php) | Chart | MeterReading, UtilityMeter | `TenantScope::currentAssetId` | `getData` (12mo stacked bar, 3 series) | UncoveredWidgetsTest |

All widgets share `RoleScopedWidget` trait — `allowedRoles()` determines visibility per logged-in role. `EtaCompliance` and `OpenMaintenanceRequests` also gate via `widgetModule()` against `ModulesSettings`.

### 1.2 Dashboard pages

| Page | LOC | Route | Custom view | Permission gate |
|---|---:|---|---|---|
| `Filament\Pages\Dashboard` (default) | — | `/admin` | Filament default; renders widgets above | role-based via widgets |
| [ArAging](../../app/Filament/Admin/Pages/ArAging.php) | 66 | `/admin/{tenant}/ar-aging` | `filament.pages.ar-aging` | `Modules::enabled('reports')` |
| [OccupancyMap](../../app/Filament/Admin/Pages/OccupancyMap.php) | 82 | `/admin/{tenant}/occupancy-map` | `filament.pages.occupancy-map` | always accessible |
| [Reports](../../app/Filament/Admin/Pages/Reports.php) | 104 | `/admin/{tenant}/reports` | `filament.pages.reports` | `Modules::enabled('reports')` |
| [Settings](../../app/Filament/Admin/Pages/Settings.php) | 315 | `/admin/{tenant}/settings` | `filament.pages.settings` | `settings.view` permission |
| [ActivityLog](../../app/Filament/Admin/Pages/ActivityLog.php) | 181 | `/admin/{tenant}/activity-log` | `filament.pages.activity-log` | `Modules::enabled('activity_log')` |

## 2. Spec map (FEATURES.md + DEMO.md)

DEMO.md §2 makes 8 verbatim claims I can verify against widget code:

| # | DEMO claim | Widget surface | Match against code | Match against current seed |
|---:|---|---|---|---|
| 1 | "Occupancy: 66 % — 33 of 50 units leased" | `MallStats` Stat[0] | ✅ formula correct (occupied/total × 100) | ❌ **see Finding F-1** |
| 2 | "MRR: EGP 1.63M" | `MallStats` Stat[1] | ✅ sums `base_rent_monthly + service_charge_monthly` for active leases | ✅ EGP 1,631,275 matches |
| 3 | "Collected This Month: EGP 220K" | `MallStats` Stat[2] | ✅ sums captured Payment in current month | ❌ **see Finding F-2** |
| 4 | "Outstanding AR: EGP 270K, 7 invoices overdue" | `MallStats` Stat[3] | ✅ sums `balance` on issued/partial/overdue invoices | ❌ **see Finding F-3** |
| 5 | "Revenue Trend: 12 months Billed vs Collected, terracotta line is the **collection rate** on secondary axis" | `MonthlyRevenueTrend` | ✅ correct — `rateSeries` joins `invoice_payment` to `invoices` by `period_start` (so payments-on-old-AR don't skew the ratio) | ✅ |
| 6 | "AR Aging: green/gold/orange/red buckets" | `ArAging` | ✅ 5 buckets (`#3B8C5A`, `#D8A53A`, `#E37B36`, `#C8453A`, `#7A1F1F`); tooltip shows EGP + count | ✅ |
| 7 | "Tenant Mix: by category" | `TenantMix` | ✅ doughnut on `unit.category` for active leases | ✅ |
| 8 | "Below the fold: Expiring Leases (90d), Top Tenants by rent, Recent Payments" | `ExpiringLeases`, `TopTenants`, `RecentPayments` | ✅ all three present, in that visual order | ✅ |

FEATURES.md widget list (12 widgets numbered -1 → 11) matches `AdminPanelProvider::widgets()` exactly.

## 3. Findings

### 🔴 F-1. DEMO §2 occupancy claim "33 of 50 units" is wrong in "All Properties" view

- DEMO.md says "Occupancy: 66 % — 33 of 50 units leased".
- The seeder creates **58 units total**: 50 on Haya Walk (33 occupied + 17 vacant) + 8 on **Plaza Annex** (all vacant), the latter stubbed at [HayaWalkSeeder:103-113](../../database/seeders/HayaWalkSeeder.php) with the comment *"Strip annex; scoping demo asset."*
- When the demo lands on the synthetic **"All Properties" pseudo-tenant**, `MallStats::getStats()` aggregates both assets → **33/58 = 56.9 % occupancy**, not 66 %.
- When the demo first switches to **Haya Walk's own tenant context**, occupancy is the claimed 66 %.
- The DEMO script does not explicitly call out "switch to Haya Walk first" — the audience may see 56.9 % on the very first screen.

**Fix options (deferred for your call):**
- **A**: Remove the Plaza Annex stub from the seeder (loses the multi-property tenancy demo).
- **B**: Update DEMO.md to start with "Switch to Haya Walk" as step 0 (keeps the scoping demo).
- **C**: Make Plaza Annex have its own occupancy story (1-2 leased units) so combined view stays >60 %.

### 🔴 F-2. DEMO §2 "Collected EGP 220K" drifts from seeded value

- DEMO says "Collected This Month: EGP 220K — note the month-over-month delta".
- Current seed produces **EGP 186,853**. Earlier seed runs produced 186,852 → 186,853 (consistent within a run, but drift vs DEMO).
- The widget code is correct; the seeder has randomness in `commencement` dates, payment selection, and lease counts.

**Fix options (deferred):**
- **A**: Update DEMO.md to drop the specific EGP number, say "Collected This Month (varies — currently ~EGP 190K)".
- **B**: Make `HayaWalkSeeder` deterministic (`srand()` + Carbon::setTestNow), so the EGP 220K number stays stable.
- **C**: Pin a "demo-perfect" snapshot via a separate `DemoPerfectSeeder` that hand-crafts the numbers DEMO.md quotes.

### 🔴 F-3. DEMO §2 "AR EGP 270K, 7 overdue" drifts from seeded value (largest delta)

- DEMO says "Outstanding AR: EGP 270K, 7 invoices overdue".
- Current seed produces **EGP 492,849 AR · 13 overdue** (and varied 533K–584K across earlier runs).
- AR is 1.8–2.2× higher than the script claims; overdue count is ~2× higher.

**Same fix options as F-2.** The AR delta is the most jarring because the warning icon in the widget keys off `overdueAR > 0`, so 13 vs 7 also changes how loudly the icon "screams" during the demo.

### 🟡 F-4. MallStats MRR sparkline shows billed-in-month, not MRR-over-time — misleading

- The KPI value "EGP 1,631,275" is **contractual MRR** (sum of `base_rent_monthly + service_charge_monthly` on active leases). This is stable month-over-month unless leases churn.
- The sparkline below it is `monthlySeries(Invoice::query()->whereNotIn('status', ['cancelled', 'credited']), 'period_start', 'total', 6)` — sum of **billed-in-month invoice totals** over the last 6 months. Current value `[814547.6, 1203202.5, 1246465.5, 1734479.5, 1711537, 131493.3]`. The last bar (current partial month) is 8 % of the previous — looks like revenue collapsed.
- This is widely a confusing UX: the headline value says "EGP 1.63M, stable" and the sparkline says "down 92 %".

**Fix options (deferred — UX decision):**
- **A**: Replace the MRR sparkline with **occupancy-weighted contractual MRR** computed per-month-end (would stay flat and match the headline).
- **B**: Drop the sparkline on the MRR card entirely (Filament Stats can render without a chart).
- **C**: Label the sparkline as "Billed this month" so the audience understands the dip.

### 🟡 F-5. MallStats `percentDelta` returns 100.0 for any non-zero current vs zero previous

- [MallStats.php:156-163](../../app/Filament/Admin/Widgets/MallStats.php#L156-L163): `if ($previous <= 0) return $current > 0 ? 100.0 : null`.
- Fresh installs (or partial months) where `$collectedLastMonth === 0.0` always show **"↑ 100.0 % vs last month"**, regardless of actual growth.
- Current seed: `Stat[2].description = "11.5% of expected · ↑ 0.0% vs last month"` — last-month delta is 0.0 % because the seed creates both months at similar levels; not the 100 % case, but the bug is latent.

**Fix:** Use `'—'` or `'n/a'` instead of `'100.0%'` when previous is 0 — clearer and avoids the false-precision claim.

### 🟢 F-6. (Fixed inline) HayaWalkSeeder summary log said "17 vacant" when it actually created 25

- Pre-fix: `Created Haya Walk with 33 occupied, 17 vacant units` — but `Unit::count()` was 58 (extra 8 on Plaza Annex not tracked).
- Fix applied: now prints `Created Haya Walk with 33 occupied, 17 vacant units (+ 8 vacant units on Plaza Annex demo asset)`. Also relabels the "Demo metrics" header as `(Haya Walk)` so the per-asset scope is explicit. See [HayaWalkSeeder:240-247](../../database/seeders/HayaWalkSeeder.php#L240-L247).
- Pest still 287/287 after the change.

## 4. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter=Widgets|MallStats|ArAging|ActivityLog|UncoveredWidgets|ActionRequired|WidgetScoping` | **45 passed / 0 failed** | 2.83 s |
| `php artisan test --parallel --filter=ActivityLog|ArAging|OccupancyMap|PanelResources|AdminPages|SetupGuide` | **36 passed / 0 failed** | 1.49 s |
| `npx playwright test tests/e2e/02-admin-pages.spec.js` (Dashboard + Activity Log + 13 others) | **15 passed / 0 failed** | 18.1 s |
| `php artisan test --parallel` (post-seeder-edit regression) | **287 passed / 0 failed** | 4.10 s |

No new tests added for this module — the existing coverage (UncoveredWidgetsTest exercises every widget's data method, plus WidgetScopingTest covers the per-asset tenancy) is already strong for the dashboard surface.

## 5. Manual UX pass

Headless Playwright (`02-admin-pages.spec.js`) confirms the dashboard URL loads without console errors after Super Admin login on Herd at `https://mall-management.test`. RTL/AR rendering of the dashboard is covered by the `99-system-smoke.spec.js` "ARABIC locale" group (not run in this pass; queued for the end-state gate).

Not visually inspected this pass:
- The MRR sparkline dip described in F-4 (would need a screenshot to confirm what the audience would see).
- The colour coding on AR Aging buckets (covered by code review — colours hard-coded as `#3B8C5A` → `#7A1F1F`).
- The "All Properties" vs "Haya Walk" tenant-switch effect on KPI values described in F-1.

## 6. Code quality notes

Healthy patterns:
- Every widget cleanly separates tenancy (one trait/helper) from data (a query closure), making the per-property aggregation legible.
- ChartWidget `getData()` builds Chart.js datasets via the `RawJs` escape hatch only for options, not for data — so the data layer stays testable.
- `monthlySeries` driver-aware date expression (`strftime` / `to_char` / `DATE_FORMAT`) is used consistently in `MallStats`, `MonthlyRevenueTrend`, and `EnergyConsumptionTrend`. Good DRY candidate but not refactor-worthy now.

No code smells worth flagging beyond F-5.

## 7. Deferred decisions for explicit approval

| # | Question | Default if I don't hear back |
|---|---|---|
| D-1 | F-1 — Plaza Annex stub: keep + update DEMO, or remove? | Keep; update DEMO §2 to "switch to Haya Walk first" |
| D-2 | F-2 + F-3 — DEMO numbers: update DEMO copy, make seeder deterministic, or split a Demo-perfect seeder? | Make seeder deterministic via `srand($seed)` + `Carbon::setTestNow`; updates DEMO.md to match the locked numbers |
| D-3 | F-4 — MRR sparkline semantic: change source, drop, or relabel? | Relabel as "Billed this month" — single string change, no logic change |
| D-4 | F-5 — `percentDelta(_, 0)` → `null` instead of `100.0`? | Apply fix (~3 LOC); render `—` in description when `$collectedDelta === null` |

I will pause at the end of the sweep to walk these together rather than ask 4 separate decisions now.

## 8. Verdict

**🟡 Yellow.** Code, widgets, and tests are healthy and well-covered. The dashboard module itself is not blocking demo or production. The two real blockers are content-layer:
- DEMO.md narrative numbers are stale relative to the seeder (F-1/F-2/F-3) — needs a decision before the demo
- One latent bug in MallStats's month-over-month delta (F-5) — easy fix, queued

The inline fix to the seeder log message (F-6) is committed in this module's commit.

## Next

Module 02 — Tenants. Surface: [app/Filament/Admin/Resources/Tenants/](../../app/Filament/Admin/Resources/Tenants/), [Tenant model](../../app/Models/Tenant.php), Tenant auth columns migration, portal+API tenant guards, and the tenant-anchored relations to Lease / Sales / Invoices.
