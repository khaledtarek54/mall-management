# Module 07 — CAM (Common Area Maintenance)

> Date: 2026-05-31
> Status: 🟢 Green — explicit MVP per FEATURES.md ("v1 intentionally shallow"); 4 Yellow extensibility findings (all align with documented Q2 roadmap).
> Surface: [CamExpensePool](../../app/Models/CamExpensePool.php) + [CamAllocation](../../app/Models/CamAllocation.php) models, [CamReconciliationService](../../app/Services/CamReconciliationService.php), [CamAnnualReconciliationCommand](../../app/Console/Commands/CamAnnualReconciliationCommand.php), Admin resource + RelationManager. Egyptian retail wedge #1.

## 1. Inventory

### 1.1 Models

**[CamExpensePool.php](../../app/Models/CamExpensePool.php)** (69 LOC). Traits: `HasFactory`, `LogsActivity`, `SoftDeletes`. One per `(asset_id, period_year)` — unique constraint. Status enum: `draft → reconciling → reconciled → closed`. Fields: `total_actual_expense`, `total_estimated_collected`, `reconciled_at`, `reconciled_by_user_id`. Methods: `variance()`, `isReconciled()`. Activity log on 4 cols.

**[CamAllocation.php](../../app/Models/CamAllocation.php)** (62 LOC). One per `(pool, lease)` — unique constraint. Status enum: `pending → billed → disputed → closed`. Fields: `pro_rata_share_pct` (decimal 7,4), `allocated_amount`, `estimated_paid`, `true_up_amount` (positive = tenant owes, negative = credit due), optional `cap_amount`, `exclusions` JSON array, `billed_charge_id`.

### 1.2 Migrations (both shipped 2026-05-23)

Pool table: 9 columns + softDeletes + timestamps. Unique `(asset_id, period_year)`, index `status`.

Allocation table: 11 columns + softDeletes + timestamps. Unique `(cam_expense_pool_id, lease_id)`, index `status`. Cascade-on-delete from pool, cascade-on-delete from lease.

### 1.3 Admin Resource — `app/Filament/Admin/Resources/CamExpensePools/`

| File | Notes |
|---|---|
| [CamExpensePoolResource.php](../../app/Filament/Admin/Resources/CamExpensePools/CamExpensePoolResource.php) (85 LOC) | `BypassesScopingOnAll` + `RoleGatedActions`; module gate `'cam'`; nav icon `OutlinedBuildingOffice2`, sort 7, group "Operations". **No `getNavigationBadge()` override — no F-17 carryover.** |
| CamExpensePoolForm.php | Sections: asset (auto-scoped), period_year, total_actual_expense, total_estimated_collected, status, notes. |
| CamExpensePoolsTable.php (120 LOC) | 7 columns incl. variance (colored: green negative = under-budget, amber positive = over-budget), allocations_count badge. Status filter only. **Record actions**: `generateAllocations` (visible when status ∈ [draft, reconciling]; bumps to `reconciling`), `markReconciled` (visible when `reconciling`; bumps to `reconciled`, stamps `reconciled_at` + `reconciled_by_user_id`), Edit. Bulk: Delete only. |
| CamAllocationsRelationManager.php (90 LOC) | Per-pool allocations table: tenant, unit, share %, allocated, estimated paid, **true_up (bold + green/amber)**, status. Record action: `bill` (visible when `pending` → calls `CamReconciliationService::bill()`). |

### 1.4 Service — [CamReconciliationService.php](../../app/Services/CamReconciliationService.php) (171 LOC)

Three public methods:

| Method | What it does |
|---|---|
| `generateAllocations(pool)` | Fetches active leases for pool's asset (`where('status','active')`), sums `unit.area_sqm`, computes `share% = lease_sqm / total_sqm`, `allocated = share × total_actual_expense`, `estimated = share × total_estimated_collected`, `true_up = allocated − estimated`. **`updateOrCreate` on `(pool_id, lease_id)` keys** — idempotent across multiple runs. Returns count. |
| `autoTrueUpForYear($year, $autoBill = false)` | Walks all draft/reconciling pools for the year. Generates allocations. If `$autoBill = true`, bills each pending allocation and marks pool reconciled. Returns per-pool report array. |
| `bill(allocation)` | Idempotent (no-op if `isBilled()`). Creates a one-off `Charge` with `type='other'`, `name="CAM Reconciliation — {year}"`, `amount=true_up_amount` (can be negative for credit), `frequency='one_time'`, `vat_applicable=false`, `start/end = {year}-01-01 / 12-31`. Links `billed_charge_id`, bumps allocation status to `billed`. |

All operations wrapped in DB transactions.

### 1.5 Command — [CamAnnualReconciliationCommand.php](../../app/Console/Commands/CamAnnualReconciliationCommand.php) (45 LOC)

```
cam:reconcile {--year= YYYY, defaults to previous calendar year} {--auto-bill}
```

Calls `autoTrueUpForYear`. Prints per-pool report row + total summary. **Not scheduled** — but FEATURES.md explicitly calls auto-true-up a Q2 deliverable (see F-30).

### 1.6 Portal / Owner

Neither tenant nor owner panels expose CAM. Tenants see the resulting "CAM Reconciliation — {year}" Charge on their invoice but no breakdown. Owner sees CAM via property-level financials (Module 10 will verify).

### 1.7 Tests

| File | Coverage |
|---|---|
| `tests/Feature/Services/CamAutoTrueUpTest.php` | 6 cases / 36 assertions / 86 LOC: `autoBill=false` flows (2 allocations generated, 0 billed, status `reconciling`); `autoBill=true` (2 billed, status `reconciled`, charges on leases); idempotency on re-run; skips reconciled/closed pools; empty year returns empty report; CLI exits OK. |
| [tests/e2e/11-cam.spec.js](../../tests/e2e/11-cam.spec.js) | 2 cases: page reachable + seeded Haya Walk pools render in table. |

## 2. Spec map

| Source | Verbatim | Verified |
|---|---|---|
| FEATURES.md L224-9 | "CamExpensePool model — one per (asset, period_year) with total_actual_expense, total_estimated_collected, status (draft → reconciling → reconciled → closed)" | ✅ |
| FEATURES.md L226-7 | "CamAllocation model — one per (pool, lease) with pro_rata_share_pct, allocated_amount, estimated_paid, true_up_amount (positive = tenant owes more, negative = credit due)" | ✅ |
| FEATURES.md L228 | "CamReconciliationService — generateAllocations(pool) distributes pro-rata by leased sqm across active leases (idempotent); bill(allocation) creates a one-off Charge type other ('CAM Reconciliation — {year}')" | ✅ |
| FEATURES.md L229 | "v1 intentionally shallow: admin manually clicks Generate + Bill; annual auto-true-up scheduled job is Q2." | ✅ scope-as-stated |
| DEMO-ELTIZAM.md L118-30 | "Pro-rata distribution by leased square meters. EGP 612K of YTD actual expenses, EGP 580K already collected via monthly estimates, EGP 32K under-collected — that's the true-up." | ⚠️ seed produces variance of 20K (100K actual − 80K estimated for 2-lease test); demo numbers don't match seed precisely — same drift pattern as [01-dashboard.md F-2/F-3](01-dashboard.md). |
| DEMO-ELTIZAM.md L130 | "Annual auto-true-up automation is Q2 work. Today we ship the data model and the manual workflow" | ✅ explicit Q2 — see F-30. |

## 3. Findings

### 🟡 F-28. Mid-year lease termination leaks CAM accounting

`generateAllocations()` filters to `where('status','active')` — so a lease terminated mid-year is excluded from the year-end pool. The terminated lease's prorated CAM contributions for the months they DID occupy go uncollected/un-refunded:

- If they over-paid via estimated monthly CAM during their tenancy, no credit note issued at termination.
- Their would-be CAM share gets implicitly redistributed across remaining active leases, slightly over-burdening them.

**Fix scope:** non-trivial — requires either (a) including terminated leases that were active during the period and prorating, or (b) issuing a credit note at termination time based on YTD pool estimate. Defer to product decision (D-21).

### 🟡 F-29. No CAM visibility on tenant portal

Tenants see a `Charge` row on their invoice ("CAM Reconciliation — 2026 — EGP X") but no breakdown of: how `X` was computed (their sqm / total sqm × pool actual − their estimated paid), or which categories the pool covers. Operators would have to email PDFs or screenshots when tenants question the figure.

**Fix scope:** add a Portal CAM allocations view showing the tenant's per-year share + breakdown line. Estimated 1-day. Defer to D-22.

### 🟢 F-30 — **Partially superseded** (audit miss).

[routes/console.php:37-44](../../routes/console.php#L37) already schedules `cam:reconcile` annually (Jan 15 @ 03:00 by default, `BillingSettings`-driven). What's NOT scheduled is the `--auto-bill` variant — the cron runs in review-only mode, leaving admins to click Bill per allocation. That matches FEATURES.md L411's "v1 admin-manual" stance.

**Remaining decision (D-23-bis):** flip the annual cron to `cam:reconcile --auto-bill` for full automation, or keep review-only as the safer default? Defer — operator preference, not a code defect.

### 🟡 F-31. No expense categories — single total budget

DEMO-ELTIZAM.md describes the operating cost pool as "security, cleaning, HVAC for corridors, lobby lighting, landscaping" — but `CamExpensePool` has only `total_actual_expense` (single number). No category breakdown, no per-category sub-budget. Operators can't import 12 invoices for cleaning vs security and have them auto-aggregate. For demo this is fine (one number, big effect). For real-world operations a `cam_categories` JSON or sibling `cam_category_expense` table would be the natural extension.

**Defer:** scope decision (D-23). Not blocking demo.

### 🟢 The "negative true_up = credit" pattern is correct

When `true_up_amount < 0`, `bill()` creates a Charge with a negative `amount`. Next invoice run picks it up and reduces the tenant's bill. Tested by `CamAutoTrueUpTest`. Clean pattern.

### 🟢 No F-17 carryover

`CamExpensePoolResource` has no `getNavigationBadge()` override.

## 4. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter='Cam|CAM'` | **12 passed / 0 failed** | 0.99 s |
| `npx playwright test tests/e2e/11-cam.spec.js` | **2 passed / 0 failed** | 4.9 s |

## 5. Manual UX

Covered by e2e (page reachable + seeded data renders). RTL/Arabic not exercised this pass; queued for end-state gate.

## 6. No inline fixes this module

CAM is intentionally MVP per FEATURES.md L229 and L411. All four Yellow findings are scope/product decisions; none are bugs.

## 7. Deferred decisions

| # | Decision | Default |
|---|---|---|
| D-21 | F-28: handle mid-year lease termination (prorate active period? credit at termination?) | Defer — product decision; flagged for stakeholder discussion |
| D-22 | F-29: add Portal CAM allocations view | Defer — pilot operators can email; revisit post-pilot |
| D-23 | F-31: add categories breakdown? | Defer — single number is sufficient for v1 |
| D-15 (already raised at M05) | Schedule `cam:reconcile`, `billing:run-monthly`, `billing:apply-late-fees` at Module 20 cross-cutting cron commit | Apply at Module 20 |

## 8. Verdict

**🟢 Green.** CAM is a well-scoped MVP. The data model is correct, the service is idempotent and DB-transactional, tests cover the happy path + idempotency + skip cases, and FEATURES.md is honest about what's deferred to Q2. The four Yellow findings are all forward-looking product extensions, not defects.

Module ratings: 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡 · 05 🟡 · 06 🟢 · 07 🟢.

## Next

Module 08 — ETA e-invoicing. Surface: [Services/Eta/](../../app/Services/Eta/) (JsonBuilder + SubmissionService + ApiClient), [SubmitInvoiceToEta job](../../app/Jobs/SubmitInvoiceToEta.php), [EtaSettings](../../app/Settings/EtaSettings.php), [EtaCompliance widget](../../app/Filament/Admin/Widgets/EtaCompliance.php), and Module 05's downstream effects on Invoice. Also: production cutover plan for ETA (F-24 from Module 05).
