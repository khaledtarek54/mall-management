# Reports

> Unified monthly financial close, AR aging analysis, and tenant/owner statements—all data-driven from the invoice and payment ledgers.

## 1. Purpose & business context

Reports power the **finance & collections team** and **mall owners** with visibility into monthly performance, aging receivables, and tenant liability. In the Eltizam (operator) and Jawad (owner) model:

- **Admin/Finance staff** run the monthly close to reconcile billed revenue, collections, outstanding AR, and credit note activity for month-end settlement and reporting to owners.
- **Collections teams** drill into AR aging buckets to prioritize follow-up with delinquent tenants.
- **Owners** download property-level statements to audit tenants' payment status and see top delinquents at a glance.
- **Tenants** retrieve their own 12-month statement for reconciliation and dispute resolution.

The module is **optional** (Module flag: `reports`; defaults enabled) and scoped via `TenantScope` so each user sees only their assigned properties' data.

## 2. Domain model

| Entity | Model Class | Key columns | Meaning |
|--------|-------------|------------|---------|
| **Invoice** | `App\Models\Invoice` | `id`, `number` (unique, e.g. INV-HW-202602-0001), `lease_id`, `tenant_id`, `status` (draft/issued/partially_paid/paid/overdue/disputed/cancelled/credited), `issue_date`, `due_date`, `period_start`, `period_end`, `subtotal` (decimal), `vat_amount` (14% standard), `total`, `paid_amount`, `credit_applied_amount`, `balance`, `currency` (EGP) | Core billing ledger entry; links to lease + tenant. Status tracks lifecycle; balance = total - paid_amount - credit_applied_amount. |
| **InvoiceItem** | `App\Models\InvoiceItem` | `id`, `invoice_id`, `type` (base_rent / service_charge / utility / parking / percentage_rent / late_fee / other), `amount`, `vat_rate` (5.2 decimal), `vat_amount`, `total` | Line-item breakdown of invoice; types feed revenue_by_type aggregation. |
| **Payment** | `App\Models\Payment` | `id`, `reference` (unique), `tenant_id`, `amount`, `method` (card/bank_transfer/instapay/wallet/cash/cheque/other), `status` (initiated/authorized/captured/reconciled/settled/failed/refunded/bounced), `payment_date`, `gateway`, `gateway_transaction_id`, `receipt_notified_at` | Payment record; many-to-many via pivot `invoice_payment` with `allocated_amount`. Only `captured` status counts toward collections. |
| **CreditNote** | `App\Models\CreditNote` | `id`, `number` (unique, e.g. CN-HW-202602-0001), `tenant_id`, `invoice_id` (nullable), `lease_id` (nullable), `status` (draft/issued/applied/void), `issue_date`, `total`, `applied_amount`, `balance`, `reason` | AR adjustment; standalone (lease_id null) = tenant-level, linked = invoice-specific. Counts in monthly close if issued/applied. |
| **Asset** | `App\Models\Asset` | `id`, `code`, `name` | Property/mall; report data is scoped via `TenantScope::currentAssetId()`. |

**Relationships:**
- `Invoice` → `Lease` → `Unit` → `Asset` (scoping path)
- `Invoice` ↔ `Payment` (many-to-many via `invoice_payment` pivot with `allocated_amount`)
- `Invoice` → `Tenant`
- `CreditNote` → `Tenant`, optionally → `Invoice`, optionally → `Lease`

## 3. Business rules & invariants

### Invoices & AR
- **Invoice balance** = `total - paid_amount - credit_applied_amount`. When balance ≤ 0, status → `paid`; when balance > 0 and paid_amount > 0, status → `partially_paid`.
- **Open invoices** for AR aging: status ∈ {`issued`, `partially_paid`, `overdue`} AND balance > 0.
- **Cancelled + draft invoices** are excluded from **all** monthly-close billed figures — `invoices.count`/`total`/`vat`, `revenue_by_type`, **and** the collections-rate denominator (a draft was never issued; a cancelled invoice was voided). They still appear in the `by_status` breakdown. *(Fixed 2026-07-27 — they used to inflate the billed headline while `revenue_by_type` excluded them, so the report contradicted itself.)*
- **VAT rate** standard 14%; invoice_items carry individual vat_rate (may vary; 0 for some line types).

### Payments & Collections
- **Only CAPTURED payments** count toward `monthlyClose()->payments[]`. Initiated/failed/authorized statuses ignored.
- **Payment allocation** via pivot `invoice_payment.allocated_amount`; a single payment can split across multiple invoices.
- **Receipt notification** fires once per payment when status=captured AND at least one invoice allocated, idempotent via `receipt_notified_at`.

### AR Aging
Buckets based on `invoice.due_date` vs. reference date (`asOf`), measured in **whole days overdue**:

```
daysOverdue = (int) due_date.startOfDay().diffInDays(asOf.startOfDay(), false)  // negative = not yet due
  daysOverdue <= 0    → 'current'               (not yet due)
  daysOverdue <= 30   → 'd_1_30'                (1–30 days)
  daysOverdue <= 60   → 'd_31_60'               (31–60 days)
  daysOverdue <= 90   → 'd_61_90'               (61–90 days)
  daysOverdue > 90    → 'd_90_plus'             (90+ days)
```

- **Floor to start-of-day on BOTH sides.** `due_date` is a date (midnight) but `asOf` carries a time (`monthlyClose` passes `endOfMonth()` = 23:59:59), so a raw `diffInDays` returns N.99… . *(Fixed 2026-07-27 — the summary used the raw float and over-aged every whole-day boundary by a bucket: a 30-days-overdue invoice showed as 31–60, one due today as 1–30. The drilldown already used `(int)`, so the two didn't reconcile — and the Reports page links each bucket total to that drilldown.)*
- **Summary (`arAgingBuckets`) and drilldown (`arAgingDrilldown`) use identical day-math + the same `issue_date <= asOf` inclusion cutoff**, so a bucket total always equals the sum of its drilldown rows. Guarded by `ReportAgingBoundaryTest`.
- **`monthlyClose()` ages at `min(month-end, today)`** and returns that day as `ar_aging_as_of`. Month-end of the month *being closed* is a future date; ageing to it declared invoices late that weren't due yet. *(Fixed 2026-07-28 — on the demo books the "1–30 days" card read 81 invoices / EGP 1.01m while the drill-down behind it listed 2 / EGP 71k, because the card aged at month-end and the drill-down re-aged at `now()`.)*
- **The ageing date travels with the drill-down.** `MonthlyCloseStats` puts `ar_aging_as_of` in each bucket link (`?bucket=…&asOf=YYYY-MM-DD`); the `ArAging` page ages at that date, shows it in the sub-heading and its own date picker, and puts it in the CSV filename. A bucket total and the worklist behind it can no longer describe different days. Guarded by `ReportsMonthlyCloseAgingTest`.
- **One bucket definition system-wide.** The dashboard `ArAging` chart calls `arAgingBuckets()` too — it used to carry a private copy comparing a midnight `due_date` to a `now()` with a time on it, so boundary invoices landed a bucket too far and the dashboard disagreed with the report.
- **Null due_date** treated as 0 days overdue (current).
- **Paid/zero-balance invoices** excluded entirely.
- **Bucket totals** = sum of `balance` (not total) for invoices in that bucket.
- **Outstanding_total** = sum of all bucket totals; must equal AR at close date.

### AR aging by charge type (RR-03)

`ReportService::arAgingByChargeType()` re-cuts the same open invoices `arAgingBuckets()` counts, by
what is owed rather than only by how late it is — so the grand total ties to the aging summary
exactly. Surfaced as the **Aging by charge type** page (`ArAgingByType`), CSV and EN/AR.

One aging total is ambiguous: "EGP 400k over 90 days" reads as delinquent rent and prompts a
collections call, when most of it may be a service charge the tenant has formally disputed. The
per-type split comes from `App\Support\InvoiceItemSettlement` (MF-06), which derives from
`invoices.paid_amount` — so the rows sum back to the invoice balances by construction, not by a
reconciliation somebody has to run.

### Monthly Close
- **Period** is month-to-month; `monthlyClose(CarbonImmutable $period)` defaults to current month.
- **Invoices included** if `issue_date BETWEEN period.startOfMonth() AND period.endOfMonth()`.
- **Payments included** if `payment_date BETWEEN period.startOfMonth() AND period.endOfMonth()` AND status=captured.
- **Collections rate** = (captured_payment_total / billable_total) * 100, where billable = the month's invoices **excluding cancelled + draft**; zero-guarded when billable_total=0. (Payments in the month may settle a *prior* month's invoice, so the rate can exceed 100% — it is a cash-flow ratio, not a per-invoice collection %.)
- **Credit notes** included if `issue_date` in period AND status ∈ {`issued`, `applied`}.
- **Revenue by type** aggregates `SUM(invoice_items.amount)` grouped by `type`, excluding cancelled/draft invoices.

### Scoping
- All queries via `TenantScope::applyTo(Query, 'lease.unit')` filter to `Asset::id = TenantScope::currentAssetId()` when a property is pinned.
- When no tenant is pinned (All Properties mode), queries return all properties (for super_admin) or assigned set.
- **Standalone credit notes** (no lease_id) visible across all properties; lease-linked credit notes scoped to their property.

### RBAC
- `reports.view`: grants access to Reports + ArAging pages (accounting, viewer, and roles with explicit grant).
- `reports.download`: gates PDF export of monthly close (viewer, accounting, manager, owner, super_admin).

## 4. Lifecycle / state machine

**No state machine per se**; reports are **read-only aggregations** of Invoice/Payment/CreditNote state.

| Trigger | Result |
|---------|--------|
| Invoice issued | Appears in monthly close billed totals; enters AR aging if balance > 0. |
| Payment captured | Appears in collections; allocated_amount reduces invoice.balance. |
| Invoice balance → 0 | Status → `paid`; invoice drops from AR aging. |
| Credit note issued/applied | Counts in monthly close.credit_notes; applied_amount reduces invoice.credit_applied_amount. |
| Period boundaries | Monthly close window is `[period.startOfMonth(), period.endOfMonth())`; earlier and later data excluded. |

## 5. Services, jobs & scheduled commands

### CSV export — `ReportCsvExporter` + `App\Support\ReportCsv`

The financial reports were **PDF-only**, and the two an accountant most needs as raw data — the
**General Ledger** and **AR Aging** — had **no export at all**. A PDF only presents; an accountant
works in a spreadsheet (pivot, reconcile, hand to an auditor, import elsewhere). So every financial
report now exports to **CSV**:

- **`App\Support\ReportCsv::stream(filename, headers, rows)`** — the one streaming primitive. Prepends
  a **UTF-8 BOM** (Excel needs it to render Arabic), quotes via `fputcsv`, streams so a large GL never
  loads into memory.
- **`App\Services\Reports\ReportCsvExporter`** — flattens each computed report into `[headers, rows]`
  (kept out of the Filament pages so the row shape is unit-testable; a streamed response is not).
  Account names follow the locale; amounts are plain numbers (no separators/symbol) so a spreadsheet
  reads them as numbers. Methods: `trialBalance`, `incomeStatement`, `balanceSheet`, `cashFlow`,
  `generalLedger`, `arAging`. Statements carry per-section subtotals + a final net line, so the CSV
  reads exactly like the on-screen report; the trial balance carries a self-checking totals row.
- **Wired as an "Export CSV" header action** on all six report pages (Trial Balance, Income Statement,
  Balance Sheet, Cash Flow, General Ledger, AR Aging), gated on `reports.view`. GL and AR aging gained
  export here for the first time.

### `ReportService` (app/Services/Reports/ReportService.php)

**Read-only query layer backed by PHPUnit tests, not spinning up a browser.** All methods scoped via `TenantScope::applyTo()`.

#### `monthlyClose(?CarbonImmutable $period = null): array`
**Signature:**
```php
public function monthlyClose(?CarbonImmutable $period = null): array
```
**Returns:**
```php
{
  period: '2026-02',
  period_label: 'February 2026' (localized),
  invoices: {
    count: int,
    total: float,
    vat: float,
    by_status: { 'issued' => {count, total}, 'paid' => {...}, ... }
  },
  payments: {
    count: int,
    total: float (captured only),
    by_method: { 'card' => float, 'cash' => float, ... }
  },
  ar_aging: (see arAgingBuckets()),
  outstanding_total: float,
  credit_notes: {
    count: int,
    total_issued: float,
    total_applied: float
  },
  revenue_by_type: { 'base_rent' => float, 'service_charge' => float, ... },
  collections_rate: float (percent, 0–100)
}
```
**Idempotency:** Fully read-only; no side effects. Safe to call repeatedly.

#### `arAgingBuckets(?CarbonImmutable $asOf = null): array`
**Signature:**
```php
public function arAgingBuckets(?CarbonImmutable $asOf = null): array
```
**Returns:**
```php
{
  'current': {count: int, total: float},
  'd_1_30': {count: int, total: float},
  'd_31_60': {count: int, total: float},
  'd_61_90': {count: int, total: float},
  'd_90_plus': {count: int, total: float}
}
```
**Details:** Calculates bucket membership based on due_date.diffInDays($asOf). Defaults to end-of-day today.

#### `arAgingDrilldown(string $bucket, ?CarbonImmutable $asOf = null): Collection`
**Signature:**
```php
public function arAgingDrilldown(string $bucket, ?CarbonImmutable $asOf = null): Collection
```
**Returns:** Collection of `Invoice` models in the specified bucket, sorted descending by balance, with `tenant` and `lease.unit` eager-loaded.
**Details:** Used by ArAging page to display invoices in a selected bucket.

#### `topDelinquentTenants(int $limit = 10): array`
**Signature:**
```php
public function topDelinquentTenants(int $limit = 10): array
```
**Returns:**
```php
[
  {
    tenant: Tenant,
    total_outstanding: float,
    days_overdue_avg: int,
    invoice_count: int
  },
  ...
]
```
**Details:** Ranks open invoices with past due_date by tenant; average days overdue, total outstanding, count. Useful for collections prioritization.

---

### `MonthlyCloseReportPdfService` (app/Services/Reports/MonthlyCloseReportPdfService.php)

**Generates PDF binary** via mPDF, rendering the monthly close report for the finance team.

#### `build(CarbonImmutable $period): string`
**Signature:**
```php
public function build(CarbonImmutable $period): string
```
**Returns:** PDF binary (starts with %PDF-).
**Details:**
- Calls `ReportService->monthlyClose($period)`.
- Renders `resources/views/reports/monthly-close.blade.php`.
- RTL-aware: switches font + directionality when locale is 'ar'.
- mPDF temp dir: `storage/app/mpdf`.

#### `filename(CarbonImmutable $period): string`
**Returns:** e.g., `atriom-monthly-close-2026-02.pdf`.

**Idempotency:** Fully deterministic; same period → identical PDF bytes each time.

---

### `AssetStatementPdfService` (app/Services/AssetStatementPdfService.php)

**Property-level statement for the Owner Portal.** Aggregates invoices/payments across all leases at a property for 12 trailing months.

#### `build(Asset $asset): string`
**Returns:** PDF binary.
**Details:**
- Trailing 12-month window (`asOf.subMonths(12).startOfMonth()` to now).
- Lists open invoices, recent invoices (last 12 months), payments, top 10 delinquent tenants by outstanding.
- Data shape property-level, not per-tenant.

#### `filename(Asset $asset): string`
**Returns:** e.g., `Property-Statement-HW-20260615.pdf`.

---

### `TenantStatementPdfService` (app/Services/TenantStatementPdfService.php)

**Tenant statement for tenant portal + API.** 12-month trailing invoices/payments for a single tenant.

#### `build(Tenant $tenant): string`
**Returns:** PDF binary.
**Details:**
- Trailing 12-month window (same as AssetStatementPdfService).
- All leases for the tenant; per-tenant view (not per-property).

#### `filename(Tenant $tenant): string`
**Returns:** e.g., `Statement-Acme-Corp-20260615.pdf`.

---

**No scheduled commands or jobs** for reports module. All generation on-demand via Filament pages or API.

## 6. Filament resources & key fields

### `Reports` page (app/Filament/Admin/Pages/Reports.php)

**Route:** `/admin/reports`  
**Navigation:** "Accounting" group, sort 50.  
**Permissions:**
- `reports.view` (gated by module flag + permission).
- `reports.download` (gated separately for PDF button).

**View data:**
- `period`: YY-mm (query param, defaults to current month).
- `report`: output of `ReportService->monthlyClose($period)`.
- `recentPeriods`: dropdown of last 12 months.

**Key UI elements:**
- Period picker (Livewire live-binding to period).
- KPI grid: invoices issued, payments captured, collections rate, outstanding AR.
- AR aging buckets (5 cards); each links to the ArAging page with **`bucket` + `asOf`**.
- Revenue by type table.
- Download PDF button (gated on `reports.download`).

**Widget wiring — two traps, both hit at once (fixed 2026-07-28):**
- The cards are declared as a **`statsWidgets(Schema $schema): Schema`** rendered by
  `ledger-report.blade.php`, **not** `getHeaderWidgets()`. Filament's page component renders
  header widgets itself, above the page slot — registering them there *and* printing them in
  the view rendered every card **twice**, and would put the cards above the picker driving them.
- The selected period is published through **`getWidgetData()`**. It used to be spelled
  `getHeaderWidgetsData()`, which Filament 4 never calls: the revenue table (reading
  `$this->period` directly) followed the picker while the cards stayed pinned to the current
  month, so one page described two months. `MonthlyCloseStats::$period` is `#[Reactive]` —
  Livewire mounts a child once, so without it the cards freeze at first load.

**Methods:**
- `downloadMonthlyClose()`: builds PDF via `MonthlyCloseReportPdfService::build()`, streams with filename.

---

### `ArAging` page (app/Filament/Admin/Pages/ArAging.php)

**Route:** `/admin/ar-aging`  
**Navigation:** Hidden (reached via Reports page).  
**Permissions:** `reports.view` (same gate as Reports).

**Query params:**
- `bucket`: one of {current, d_1_30, d_31_60, d_61_90, d_90_plus} (defaults d_1_30).
- `asOf`: `Y-m-d` the receivables are aged at (defaults to today; junk falls back to today via
  `ArAging::parseAsOf()`). Set by the monthly-close card that was clicked so the drill-down
  lists exactly the invoices that card counted.

**View data:**
- `invoices`: result of `ReportService->arAgingDrilldown($bucket, $asOf)` sorted by balance desc.
- `bucket`, `buckets`, `asOf`, `totalBalance`.

**Key UI elements:**
- Bucket picker + **as-of date picker** (both Livewire live-binding).
- Sub-heading states the bucket total, the invoice count and the ageing date.
- Invoice table: number, tenant, unit, due_date, balance, days_overdue (measured against
  `asOf`, not `now()`), link to edit invoice.
- CSV export; the filename carries the as-of date (`ar-aging-{bucket}-{Y-m-d}.csv`).

---

### `ArAging` widget (app/Filament/Admin/Widgets/ArAging.php)

**Filament widget** (not a page) displayed on Admin dashboard.

**Permissions:** Restricted to roles: manager, viewer (via `RoleScopedWidget` trait).

**Display:**
- Bar chart with 5 aging buckets (colors: green current → red 90+).
- Tooltip shows EGP amount + count of invoices.
- Buckets come from `ReportService::arAgingBuckets()` — the same call the monthly-close cards
  and the drill-down use. *(Fixed 2026-07-28: it used to run its own `due_date` comparisons
  against a timestamped `now()`, so an invoice due today, or exactly 30/60/90 days late, was
  pushed one bucket too far and the dashboard contradicted the report.)*

---

## 7. Notifications & integrations

**No direct outbound integrations** from the Reports module itself. However:

- **Payment received notification** fires when a payment is `captured` (see Payment model); tenant is notified via portal.
- **Owner overdue notification** (outside Reports) tracks `invoice.owner_overdue_notified_at` to notify owners of tenant overdue invoices.

**PDF generation:**
- Uses **mPDF** library for RTL (Arabic) rendering.
- Temp dir: `storage/app/mpdf` (created on-demand; no cleanup logic).

## 8. Extension points — how to change/extend SAFELY

### Add a new KPI to monthly close
1. Edit `ReportService::monthlyClose()` to calculate the KPI (e.g., `sum($invoices->where('status', 'disputed'))`).
2. Add the field to the return array (e.g., `'disputed' => [...]`).
3. Update the test in `tests/Feature/Scenarios/ReportScenarioTest.php` to assert the new field.
4. Add a `Stat` for it in `App\Filament\Admin\Widgets\MonthlyCloseStats::getStats()` — the KPI grid is a native Filament stats widget, not a Blade template.
5. Update PDF template `resources/views/reports/monthly-close.blade.php` to include it.
6. **Do NOT** break the existing return structure; new fields should be optional/backward-compatible if the return is consumed elsewhere (API, exports, etc.).

### Modify AR aging bucket boundaries
1. Edit the `match()` logic in `ReportService::arAgingBuckets()` (line 129–135).
2. Update the bucket array keys and comments.
3. Update tests in `ReportScenarioTest::test AR aging bucket boundaries` to reflect new cutoffs.
4. Update the bucket labels in `ArAging::buckets()` (the single list the page, its picker and `MonthlyCloseStats` all read) if the names change.
5. **Invariant to maintain:** `outstanding_total == sum of all bucket totals` (tested).

### Add filters to monthly close (e.g., by status, tenant, unit)
1. Add optional parameters to `ReportService::monthlyClose()` (e.g., `?array $statuses = null`).
2. Add `.where()` clauses to the invoice/payment queries before aggregation.
3. Update test(s) to verify filtered results.
4. Update the Reports page query params / form fields (Filament Select for statuses).
5. **Do NOT** break the existing unfiltered call; add a new method or make filters optional.

### Export to Excel or CSV
1. Create `ExportMonthlyCloseService` or similar in `app/Services/Reports/`.
2. Use `ReportService->monthlyClose()` to fetch data (no code duplication).
3. Format and stream via Symfony HttpFoundation Response or Laravel Excel.
4. Add action button to Reports page (Filament Action).
5. Gate on `reports.download` permission.

### Change scoping strategy (e.g., by unit, not by asset)
1. **Do NOT directly modify** `TenantScope` (used across the app).
2. Instead, add a **new scoping method** in ReportService (e.g., `private function scopedInvoicesForUnit(Unit $unit)`).
3. Update `monthlyClose()` to accept an optional Unit parameter.
4. Update tests to verify scoping works correctly.
5. **Invariant:** AR aging totals must still sum to outstanding_total per scope.

---

## 9. Gotchas, edge cases & recently-fixed bugs

### Null due_date
- Invoices **may have null due_date** (edge case from manual invoice entry or legacy data).
- AR aging treats null as 0 days overdue (current bucket).
- **Test:** `arAgingBuckets()` handles null gracefully; no crash.

### Double-notification on payment
- Payment receipt notification is idempotent via `receipt_notified_at` timestamp.
- Called from both `Payment::saved()` hook AND after Filament form save (when pivot is synced).
- **Gotcha:** If both occur in the same request, the second call sees the flag and returns early. Safe but watch for silent failures in logging.

### Three surfaces, one set of buckets *(fixed 2026-07-28)*
The same five ageing buckets are shown in three places — the dashboard chart, the
monthly-close KPI cards, and the AR-ageing drill-down. All three disagreed:
- The **dashboard chart** ran its own `where('due_date', '<', now()->subDays(X))` queries,
  comparing a midnight `due_date` against a `now()` that carries a time — every boundary
  invoice (due today, or exactly 30/60/90 days late) landed one bucket too far.
- The **cards** aged at month-end; the **drill-down** re-aged at `now()`. On the demo books
  the "1–30 days" card said 81 invoices / EGP 1.01m and opened onto 2 / EGP 71k.

Now: the chart calls `arAgingBuckets()`; `monthlyClose()` ages at `min(month-end, today)` and
publishes `ar_aging_as_of`; the cards pass that date to the drill-down in the link. **If you add
a fourth surface, call `ReportService` and carry the as-of date — don't re-derive the buckets.**
Guarded by `ReportsMonthlyCloseAgingTest` + `ReportAgingBoundaryTest`.

### Month boundary edge cases
- Invoice issued on 2026-02-28 (last day) **is included** in Feb close.
- Invoice issued on 2026-03-01 (first day) **is excluded** from Feb close.
- Test guards: `ReportScenarioTest::test monthly close month window`.

### Cancelled invoices in revenue_by_type
- Cancelled + draft invoices **excluded from `revenue_by_type`** aggregation.
- But they **are counted** in `invoices.count` and `invoices.total`.
- **Rationale:** revenue_by_type is operational revenue (excludes cancellations); total billed is accrual basis.
- **Test:** `test excludes cancelled + draft invoices from revenue_by_type but still counts them as billed`.

### Credit note balance calculation
- `credit_note.balance = total - applied_amount`.
- Applied amount increases as the note is used against invoices.
- A credit note is included in monthly close only if status ∈ {issued, applied}.
- **Gotcha:** A drafted credit note never appears, even if it has items.

### Collections rate zero-guard
- When `billed_total == 0`, `collections_rate = 0.0` (not NaN or division-by-zero error).
- **Test:** `test returns a zero collections_rate (no division-by-zero) when nothing was billed`.

### All Properties mode
- When no Filament tenant is pinned (All Properties), `TenantScope::currentAssetId()` returns null.
- `applyTo()` becomes a no-op; queries see all properties.
- For restricted users (non-super_admin) in All Properties, scoping still applies via `AssignedAssets::idsForCurrentUser()`.
- **Test:** `test sees BOTH properties when no tenant is pinned (All Properties / unscoped)`.

### Partial payments and balance tracking
- Invoice balance = `total - paid_amount - credit_applied_amount`.
- A partially paid invoice counts only its **balance** in AR aging (not its total).
- Example: invoice total 10,000, paid 6,000 → balance 4,000 counts in bucket.
- **Test:** `test counts only the open balance of a partially-paid invoice in its bucket`.

### VAT precision
- VAT amounts are stored as `decimal(12, 2)` with no rounding error.
- Monthly close sums VAT exactly: `SUM(invoices.vat_amount)`.
- Invoice items carry individual `vat_rate` (default 14%, may vary).
- **No reconciliation** between item vat (sum of item.vat_amount) and invoice.vat_amount; assume data entry is correct.

---

## 10. Tests & related modules

### Test files

- **`tests/Feature/ReportServiceTest.php`**: Unit tests for ReportService (monthly close aggregation, AR aging, revenue_by_type).
- **`tests/Feature/Services/MonthlyCloseReportPdfServiceTest.php`**: PDF generation (binary output, RTL rendering, filename format).
- **`tests/Feature/Scenarios/ReportScenarioTest.php`** (extensive): Monthly close figures, AR aging boundaries (every cutoff: 0/30/31/60/61/90/91 days), month windows, credit notes, collections rate, status breakdown, scoping (single property + All Properties), RBAC (reports.view + reports.download permissions).

### Related modules

- **[05-billing-invoices.md](./05-billing-invoices.md)**: Invoice domain, status lifecycle, numbering, VAT, period windows.
- **[06-payments.md](./06-payments.md)**: Payment domain, statuses (initiated/captured/failed), allocation to invoices, receipt notifications.
- **[07-credit-notes.md](./07-credit-notes.md)**: Credit note domain, reasons, application to invoices, balance tracking.
- **[04-leases.md](./04-leases.md)**: Lease domain (invoice.lease_id parent).
- **[02-tenants.md](./02-tenants.md)**: Tenant domain (invoice.tenant_id parent, statement generation).


## 11. Weekly spend — fixed vs variable (FR-FIN-02)

A management view of where the money goes week to week, split **fixed** (committed costs that land
whether the mall is busy or not — a security/cleaning contract, admin salaries/rent) vs **variable**
(spend that tracks activity — utility consumption, ad-hoc repairs, discretionary marketing).

- **The classification lives in `App\Support\CostNature`** — a single category→nature map read by
  `Expense::costNature()`, the `Expense` register's nature column + filter (`scopeOfNature`), and the
  report. `Expense` and `VendorBill` carry the **same** category set, so both are classified the same
  way and the register can never disagree with the report. Coarse by category on purpose (the FRD asks
  for a fixed/variable *report*, not per-line tagging); an unmapped category falls to `variable` (the
  conservative default — an unclassified cost isn't treated as committed). Adjust the map as the
  operator's contracts dictate.
- **`ReportService::weeklySpend(from, to)`** sums Expense (`expense_date`, `recorded`) + VendorBill
  (`bill_date`, not draft/cancelled) as the **ex-VAT** cost (input VAT is recoverable, not a cost),
  grouped by **ISO week (Mon–Sun)**. The range is pre-seeded so a spend-free week reads as zero rather
  than vanishing from the trend. Property-scoped via `TenantScope::applyTo` (direct `asset_id`), so it
  respects the selected mall — the first weekly period anywhere in the app (every other report is
  monthly / as-of). Surfaced on the `WeeklySpend` page (fixed/variable/total columns + summarised
  totals + CSV), reusing the shared `ledger-report` view.
- Pinned by `tests/Feature/WeeklySpendReportTest.php` (classification, the ex-VAT split across both
  sources with property-scoping + cancelled-exclusion, and the zero-seeding).

**Workflow visualization (FR-FIN-04)** — the `Workflows` page (Settings group) renders each state
machine's transitions read-only, driven straight off the `TRANSITIONS` matrices that *enforce* the
flows (`PurchaseRequest`, `MaintenanceWorkOrder`, `TenantRequest`), so it can never document a
transition the services don't allow. No domain change — a rendering of the single source of truth.

## 9. UI architecture — native Filament, no hand-written report markup

Every report surface in this module is a Filament **Page + Table**, not a Blade
template. The pages share one 12-line shell
(`resources/views/filament/pages/ledger-report.blade.php`) that renders three
things and nothing else:

```blade
{{ $this->filtersForm }}   {{-- native Schema: year / property / period / bucket --}}
<x-filament-widgets::widgets … />   {{-- header stats, where the page has them --}}
{{ $this->table }}
```

**Why it matters.** These pages previously carried ~700 lines of hand-written
`<table>` markup with inline styles and hard-coded hex colours. That markup did
not follow the panel theme (including each property's own `primary_color`), had
patchy dark-mode support, and gave an operator no sorting, no column control and
no drill-through beyond bespoke anchors.

**`records()`, not `query()`.** The trial balance, the three statements, the AR
ageing drill-down and monthly-close revenue are all AGGREGATES computed by a
report service, not row sets. They are fed to Filament through
`Table::records()`. Two consequences worth knowing before changing one:

- A per-group `Summarizer` has no query to aggregate. The financial statements
  therefore emit section totals as **real rows** (`is_total`), which is also how
  a printed statement reads. See `Concerns\RendersFinancialStatement`.
- A `Summarizer` on such a table must ignore its `$query` argument and read the
  figure off the report (`->using(fn (): float => $this->report()['total_debit'])`).
  This is deliberate: those totals are the tie-out the statement is judged on,
  so they come from the same array the PDF and CSV are built from.

**Filters stay bound to the page's own properties** (`$year`, `$assetId`,
`$period`, `$bucket`) rather than living in table-filter state, because the PDF
and CSV header actions read those properties. One piece of state means the
export can never describe a different period than the screen.

**Ordering carries meaning** on the general ledger (running balance) and the
statements (section order), so both are `paginated(false)` and unsorted by
design — re-ordering them would make each line's balance not follow from the one
above it.

Related: `tests/Feature/Pages/LedgerReportTablesTest.php` asserts each page's
rows and totals against the report service rather than merely that the page
renders.

---

## AR collections worklist

`/admin/ar-collections` ([`ArCollections`](../../app/Filament/Admin/Pages/ArCollections.php)) —
one row per tenant, outstanding split across every aging bucket at once, sorted **worst-first
(deepest bucket, then size)**, with invoice count, oldest item in days, last payment date (or a red
"never paid"), a statement download per row and a CSV export. Property-scoped, EN + AR.

It is the **collections** question — *who do I call, and about what* — as distinct from
[`ArAging`](../../app/Filament/Admin/Pages/ArAging.php), which is the accountant's *how much is
31–60 days late* and drills into one bucket.

### The one rule for anything that ages a receivable

**Bucket boundaries live in `ReportService::agingBucketKey()`, against the `AGING_BUCKETS`
register. Never re-derive them.** The arithmetic used to be copied between `arAgingBuckets()` and
`arAgingDrilldown()` with a comment asking the two to stay identical; the collections worklist
would have been a third copy. A bucket total that disagrees with the list behind it destroys the
operator's trust in both numbers, and the day-boundary cases (due today; exactly 30/60/90 days) are
where that disagreement hides — `ArCollectionsTest` pins all three views to the same answer on
exactly those days.

`ReportService::openInvoicesAsOf()` is likewise the single query every aging view starts from, so a
drill-down can never surface an invoice its own summary did not count.

---

## Lease expiration schedule

`/admin/expiration-schedule` ([`ExpirationSchedule`](../../app/Filament/Admin/Pages/ExpirationSchedule.php) +
`ReportService::expirationSchedule()`, story RR-02) — the rent roll says what the mall earns today;
this says **when that stops**.

Live leases bucketed by the year their term ends, each bucket carrying its lease count, area, annual
rent and — the number the question is really about — **its share of the mall's total area and
income**. A year with 30% of the income expiring is a year of negotiations that has to start
eighteen months earlier, and the only way to see one before this was to sort the lease table by end
date and add the rents up by hand. CSV exports the per-LEASE rows, not the four totals, because a
leasing manager exports this to work the list.

**Holdovers are their own bucket, sorted first.** A lease past its term but still trading has not
rolled off — its rent is live and its space is occupied — so counting it under a past year would
understate both this year's risk and today's income, and would bury the one row that needs a
decision today rather than in eighteen months.

## Sales & trading performance

`/admin/sales-analytics` ([`SalesAnalytics`](../../app/Filament/Admin/Pages/SalesAnalytics.php) +
`ReportService::salesAnalytics()`, story RR-05) — MTD, YTD, **MAT** (the trailing twelve months) and
growth against the same twelve months a year earlier.

**MAT is the number retail runs on.** A calendar-year figure says nothing useful in March and swings
around Ramadan and the school year; twelve months strips the seasonality out so two dates are
comparable.

**Two growth figures, deliberately.** Total MAT growth says how the centre's income is moving;
**like-for-like** says how the tenants who were already there are trading. A mall that let ten new
shops reads as growth on the first and flat on the second — and the gap between them is the story a
GM is actually looking for.

- **LFL counts only leases that declared in BOTH windows.** Without that exclusion a new anchor's
  whole turnover reads as "growth", which measures letting rather than trading. Both directions are
  pinned: the newcomer is excluded, and so is a departed tenant who would otherwise collapse the
  headline while saying nothing about the tenants still there.
- **The rule is *declared in both*, not *trading every month of both*.** Real declaration data has
  gaps, and the stricter rule would compute a mall-wide metric over a quarter of the mall while
  claiming to describe it. `lfl_leases` reports the count it used, so the basis is on the screen.
- **No prior year reads as UNKNOWN, never 0%** — zero would claim flat trading the data cannot
  support.
- Estimated declarations are flagged, the same as on the occupancy-cost report.

Only leases with percentage rent appear, because those are the ones that declare sales.

## Occupancy cost %

`/admin/occupancy-cost` ([`OccupancyCost`](../../app/Filament/Admin/Pages/OccupancyCost.php) +
`ReportService::occupancyCost()`, story RR-04) — **who is in trouble before they miss a payment**.

Total occupancy cost ÷ declared sales per tenant, rolling 12 months by default. A fashion tenant at
12% of turnover is healthy; one at 30% is failing and will usually stop paying before saying so.
Every input already existed — invoices and `TenantSalesDeclaration` — and the number was produced
nowhere. Thresholds are 20% amber / 25% red: **commonly cited retail rules of thumb, deliberately
not a setting**, because the healthy band differs by trade (food courts run high, anchors run low)
and putting them in a settings screen would imply a precision this does not have. Ask Eltizam for
their own bands before making them configurable.

Four rules that decide whether the number means anything:

- **Cost is what was BILLED, not what was paid.** Occupancy cost burdens the business whether or
  not the tenant has settled it; folding in payment behaviour would make a struggling tenant look
  *cheaper* the longer they went without paying.
- **Late fees and violation fines are excluded** (`ReportService::OCCUPANCY_COST_TYPES`). They are
  penalties for behaviour, and including them would say a tenant's occupancy is expensive because
  they paid late rather than because their rent is high — inverting the signal.
- **No declared sales reads as UNKNOWN, never 0%.** Zero would rank the tenant who files nothing as
  the healthiest in the mall, when a tenant who stops declaring is usually the one in trouble.
- **The portfolio headline is total cost ÷ total sales**, not the mean of the per-tenant ratios,
  which one tiny tenant could otherwise dominate.

Only leases with percentage rent appear, because those are the ones that declare sales at all.

## Rent roll

`/admin/rent-roll` ([`RentRoll`](../../app/Filament/Admin/Pages/RentRoll.php) +
`ReportService::rentRoll()`) — the single most-used report in commercial property, and Atriom had
no version of it.

One row per lease **as at a chosen date**: unit(s) · tenant · area · expiry and months left ·
**base rent in force on that date** · EGP/m²/yr · total monthly (rent + service + levy) · the next
contracted rent step · the next unresolved option deadline · deposit. Property-scoped, CSV export,
EN + AR, each row links to its lease.

**Why it could not exist before the charge schedule.** The rent used to be a single mutable number,
so a roll for last March would have reported *today's* rent and a roll for next year could not
exist at all. Every row now reads the schedule row in force on the as-of date through
**`ChargeScheduleService::pickInForce()` — the same selection the billing engine and the schedule
writer use.** A rent roll that decided "current rent" by its own rule would eventually disagree
with what actually bills, and an owner who catches that stops trusting both numbers; the test
suite therefore asserts the roll against the *invoice the engine produces for the same month*,
never against a hand-computed figure.

Two smaller rules worth keeping:

- **EGP/m²/yr is null, not zero, when the unit has no recorded area.** Unknown is not free, and a
  zero would quietly drag the portfolio rate down.
- **The headline rate is weighted by area**, so a 20 m² kiosk does not move the mall's number as
  hard as a 2,000 m² anchor.
