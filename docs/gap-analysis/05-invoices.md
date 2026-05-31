# Module 05 — Invoices

> Date: 2026-05-31
> Status: 🟡 Yellow — largest billing surface; well-tested; 1 inline fix (F-17 carryover); 3 deferred findings about scheduling, email PDF, and ETA mock mode for production.
> Surface: Invoice + InvoiceItem models, 3 panels (Admin/Owner/Portal), MonthlyBillingService, InvoicePdfService, LateFeeService, ETA services, jobs + commands, mail, Arabic mPDF view.

## 1. Inventory

### 1.1 Invoice model — [Invoice.php](../../app/Models/Invoice.php) (193 LOC)

- Traits: `HasFactory`, `LogsActivity`, `SoftDeletes`.
- `$fillable` (19 columns): **identity** (`number`, `lease_id`, `tenant_id`, `currency`), **financial** (`subtotal`, `vat_amount`, `total`, `paid_amount`, `balance`), **ETA** (`eta_submission_id`, `eta_submitted_at`, `eta_response`, `eta_status`, `eta_long_id`), **status/dates** (`status`, `issue_date`, `due_date`, `period_start`, `period_end`), **misc** (`notes`).
- `$casts`: 4 date fields, `eta_submitted_at` datetime, 5 decimal:2 financials, `eta_response` array.
- Relations: `lease()` BelongsTo, `tenant()` BelongsTo, `items()` HasMany, `payments()` BelongsToMany via `invoice_payment` pivot with `allocated_amount`.
- Status enum (8): `draft, issued, partially_paid, paid, overdue, disputed, cancelled, credited`.
- ETA status enum (6): `pending, submitted, valid, invalid, rejected, cancelled`.
- Computeds: `isOverdue()`, `daysOverdue()`, `recalculateBalance()` (single source of truth, auto-flips status), `recomputeTotals()`.
- `generateNumber($assetCode, $issueDate)` formats `INV-{HW}-{YYYYMM}-{####}` with collision detection.
- Boot: `booted()` auto-generates number at save time, defaults currency to EGP, initializes balance.
- LogsActivity allowlist: `[number, status, issue_date, due_date, total, paid_amount, balance, tenant_id, lease_id]`, dirty-only, log name `invoice`.

### 1.2 InvoiceItem — [InvoiceItem.php](../../app/Models/InvoiceItem.php) (50 LOC)

- Fillable: `invoice_id, charge_id, description, type, amount, vat_rate, vat_amount, total`.
- Type enum (7): `base_rent, service_charge, utility, parking, percentage_rent, late_fee, other`.
- Boot: `saving()` auto-computes `vat_amount = amount × vat_rate/100`, `total = amount + vat_amount`.
- Relations: `invoice()`, `charge()` (nullable — late-fee items have no source Charge).

### 1.3 Migrations

| File | Effect |
|---|---|
| [2024_01_01_000006_create_invoices_table.php](../../database/migrations/2024_01_01_000006_create_invoices_table.php) | 19-column invoices table, 7-column invoice_items table with cascade FK to invoice; indexes `(status, due_date)`, `tenant_id`, `lease_id`, `issue_date`; soft-deletes. |
| [2024_01_01_000007_create_payments_table.php](../../database/migrations/2024_01_01_000007_create_payments_table.php) | Also creates the `invoice_payment` pivot with `allocated_amount`, unique (`invoice_id`, `payment_id`). |
| [2026_05_23_172154_add_eta_status_to_invoices_table.php](../../database/migrations/2026_05_23_172154_add_eta_status_to_invoices_table.php) | Adds `eta_status` enum (nullable) + `eta_long_id`, index on `eta_status`. |

### 1.4 Admin Resource — `app/Filament/Admin/Resources/Invoices/`

| File | LOC | Notes |
|---|---:|---|
| [InvoiceResource.php](../../app/Filament/Admin/Resources/Invoices/InvoiceResource.php) | 119 | `RoleGatedActions` + `ScopesViaProperty` (`lease.unit`). Nav: `Banknotes`, sort 1, group "Billing". `getNavigationBadge()` — overdue count — **fixed inline this module (F-17 carryover)**. |
| Schemas/InvoiceForm.php | 312 | Sections: Invoice Details (lease picker with `afterStateUpdated` → live auto-fill of tenant + items from charges), status, dates, subtotal/VAT/total (read-only), items repeater (type/description/amount/vat), notes. |
| Tables/InvoicesTable.php | 324 | **10 columns** incl. tenant/unit/period/total/paid/balance/due-date/status/eta_status. **7 filters** incl. period_range, due_range, overdue_only, trashed. **Header actions**: Export, Run Monthly Billing (inline service call). **Record actions**: Edit, downloadPdf, sendWhatsApp (config-gated), submitToEta. **Bulk actions**: Export, downloadPdfBundle (ZIP), bulkSubmitToEta, Delete/ForceDelete/Restore. |
| Pages/{Create,Edit,List}Invoice.php | thin | Standard pages. |

### 1.5 Owner Portal — `app/Filament/Owner/Resources/Invoices/`

| File | LOC | Notes |
|---|---:|---|
| InvoiceResource.php | 81 | **Read-only** (`canCreate=canEdit=canDelete=false`). Query scoped via `whereHas('lease.unit.asset.owners', fn($q) => $q->where('user_id', Auth::id()))` — uses the Asset `owners()` BelongsToMany (via `asset_owner` pivot) confirmed at [Asset.php:101](../../app/Models/Asset.php#L101). Pages: List + View. |

### 1.6 Tenant Portal — `app/Filament/Portal/Resources/Invoices/`

| File | LOC | Notes |
|---|---:|---|
| InvoiceResource.php | 82 | **Read-only**. Query scoped via `where('tenant_id', Auth::guard('portal')->id())`. Pages: List + View. No payment-initiation action visible — tenant pays via WhatsApp/Paymob/InstaPay (offline rails) — confirm at Module 11 Portal audit. |

### 1.7 Services

| Service | LOC | Purpose |
|---|---:|---|
| [MonthlyBillingService](../../app/Services/MonthlyBillingService.php) | 244 | `runForPeriod($period)` — idempotent, chunked (100), per-lease guard via `already_billed` detection, returns `{period, leases_considered, created, skipped, failed, failed_lease_ids}`; dispatches `InvoiceIssued` mail per created invoice. `generateForLease($lease, $period, $prorate)` — single-lease wrapper for the EditLease "Generate Invoice" action. `chargeAppliesToPeriod($charge, $start, $end)` — frequency-aware (monthly/quarterly/annually/one_time) + date-range filter. Due date = `issue_date + lease.payment_terms_days` (default 7). |
| [InvoicePdfService](../../app/Services/InvoicePdfService.php) | 56 | `build($invoice)` → renders Blade `invoices.pdf` through mPDF; config A4, margins 12/12/12/14; **font `xbriyaz` for RTL, `dejavusans` for LTR**; `autoArabic` + `autoLangToFont`; locale-driven direction switch. `filename($invoice)` → `{number}.pdf`. |
| [LateFeeService](../../app/Services/LateFeeService.php) | 92 | `runForToday($today)` — grace period from `config/billing.late_fee_grace_days` (default 7); criteria: `status ∈ [issued, partially_paid, overdue], balance > 0, due_date ≤ today - grace`; idempotent. `applyTo($invoice)` — guards against duplicate (existing `late_fee` item); fee = `max(min_fee, balance × percent/100)`; defaults 2% and EGP 50. Updates subtotal/total/balance and flips status to `overdue`. |
| [Eta/EtaJsonBuilder](../../app/Services/Eta/EtaJsonBuilder.php) | 147 | Maps Invoice → ETA v1.0 JSON; **enforces `tenant.tax_id` for B2B** (throws `RuntimeException` if missing — surfaces "your customer needs a tax ID before you can submit"); maps charge types to EGS codes (`EG-6820-001` for base_rent etc.); 5-decimal rounding. |
| [Eta/EtaSubmissionService](../../app/Services/Eta/EtaSubmissionService.php) | 71 | `submit($invoice)` — idempotent (no-op if `eta_status='valid'`); writes back `eta_submission_id`, `eta_long_id`, `eta_status`, `eta_submitted_at`, `eta_response`. |
| [Eta/EtaApiClient](../../app/Services/Eta/EtaApiClient.php) | 83 | `submitDocument(json)` — switches on `isMock()`: mock returns deterministic UUID + Valid; real fetches OAuth bearer token, POSTs to `config('eta.endpoint')/api/v1/documentsubmissions`. |

### 1.8 Jobs + commands

| Class | What it does |
|---|---|
| [Jobs/RunMonthlyBilling](../../app/Jobs/RunMonthlyBilling.php) | ShouldQueue, timeout 600s, tries 1; calls `MonthlyBillingService::runForPeriod`. |
| [Console/Commands/RunMonthlyBillingCommand](../../app/Console/Commands/RunMonthlyBillingCommand.php) | `billing:run-monthly {--period=YYYY-MM} {--queue}`. |
| [Jobs/ApplyLateFees](../../app/Jobs/ApplyLateFees.php) | ShouldQueue, timeout 600s, tries 1. |
| [Console/Commands/ApplyLateFeesCommand](../../app/Console/Commands/ApplyLateFeesCommand.php) | `billing:apply-late-fees {--date=YYYY-MM-DD} {--queue}`. |
| [Jobs/SubmitInvoiceToEta](../../app/Jobs/SubmitInvoiceToEta.php) | ShouldQueue; calls `EtaSubmissionService::submit`. |

**Scheduling**: `grep -rn 'schedule->' bootstrap/app.php app/Console` returned nothing. **None of these jobs are scheduled** — see F-22.

### 1.9 Mail

- [Mail/InvoiceIssued.php](../../app/Mail/InvoiceIssued.php) (38 LOC). Dispatched from `MonthlyBillingService::notifyInvoiceIssued()`, queued.
- Envelope subject: `__('admin.email.invoice_issued_subject', ['number' => …])` — localized.
- Content: `emails.invoice-issued` Blade view with `invoice`, `tenant`, `lease`.
- **Does NOT attach the PDF** — see F-23.

### 1.10 PDF view — [resources/views/invoices/pdf.blade.php](../../resources/views/invoices/pdf.blade.php) (265 LOC)

- Opens with `@php $isRtl = app()->getLocale() === 'ar'; @endphp`.
- Sets `<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">` so mPDF handles bidi naturally.
- Title via `__('admin.pdf.tax_invoice')`.
- Sections: header (logo + invoice number), parties (issuer + receiver), period box, items table (description / qty / unit price / VAT% / line total), totals (subtotal + VAT + grand total), ETA reference lines (visible only when `eta_submission_id` is set), notes.
- Confirms FEATURES.md claim about "Arabic shaping + bidi" being engineered in.

### 1.11 Tests

Feature:
- [BillingMathTest.php](../../tests/Feature/BillingMathTest.php) (328 LOC) — VAT math + proration.
- `Models/InvoiceTest.php` (98 LOC) — generation + status helpers.
- `Services/MonthlyBillingServiceTest.php` (78 LOC) — idempotency + charge applicability.
- `Services/LateFeeServiceTest.php` — grace + min/percent.
- `EtaJsonBuilderTest.php` + `EtaJsonBuilderTaxIdTest.php` + `Services/EtaIntegrationTest.php`.
- `Settings/ModulesEtaToggleTest.php`.

E2E:
- `05-pdfs.spec.js` (3) — admin downloads invoice PDF EN, AR, + statement PDF.
- `07-pdf-content.spec.js` (4) — bilingual content rendering for invoice + statement.
- `13-eta.spec.js` (2) — Invoices index + seeded Valid badges.
- `17-functional-actions.spec.js` — Run Monthly Billing modal opens, downloadPdf, Submit to ETA exists, bulk actions.

## 2. Spec map

| Source | Verbatim claim | Verified |
|---|---|---|
| DEMO.md §7 | "Watch the items repeater auto-fill from the lease's monthly charges — base rent, service charge, with VAT pre-computed." | ✅ `InvoiceForm.php` `afterStateUpdated` on lease picker + `prefillItemsFromLease`. |
| DEMO.md §8 | "Click this and we generate invoices for every active lease for the current period — one invoice per lease, items from each lease's charges. The system skips anyone already billed for this period." | ✅ `MonthlyBillingService::runForPeriod` chunks 100, idempotent. |
| DEMO.md (flag) | "ETA e-invoicing is live in mock mode — submit-to-ETA action returns a stubbed Valid response. Flip `eta.mock` off in `/admin/settings → ETA` when preprod creds land." | ✅ but production must flip — see F-24. |
| DEMO.md (numbers) | "Invoices: ~200 total · ~10 overdue" | Seed produces 186 invoices, 13 overdue (close enough but documented as drift in [01-dashboard.md F-3](01-dashboard.md)). |
| FEATURES.md | "mPDF Arabic shaping + bidi" | ✅ PDF view + service config. |
| FEATURES.md | "Egypt-first defaults — EGP, DD/MM/YYYY, EG VAT (rent exempt, service 14%), Arabic" | ✅ Charge types set `vat_applicable=false` for base_rent, `vat_rate=14.00` for service. |
| FEATURES.md | "ETA-native architecture — `eta_submission_id` / `eta_submitted_at` / `eta_response` columns already on Invoice; just need credentials" | ✅ plus `eta_status` and `eta_long_id` added later. |

## 3. Findings

### 🔴 F-17 (Fixed inline) — Invoice nav badge bypassed tenant scope

[InvoiceResource:86-93](../../app/Filament/Admin/Resources/Invoices/InvoiceResource.php#L86-L93) was `static::getModel()::where(...)->count()`. Now uses `static::getEloquentQuery()` so `ScopesViaProperty::getEloquentQuery()` applies `whereHas('lease.unit', ...)->where('asset_id', currentAssetId)`. Carryover from [Module 03 F-17](03-units.md#-f-17-cross-cutting-partially-fixed--navigation-badges-bypass-tenant-scope). Pest 287/287 green after fix.

### 🟡 F-22. `RunMonthlyBilling` and `ApplyLateFees` are NOT scheduled

Both jobs and their commands exist and work end-to-end, but `bootstrap/app.php` + `app/Console/` have **no `$schedule->command()` or `$schedule->job()` calls anywhere**. The intended invocation is:

- **Monthly billing**: must be triggered manually on or shortly after the 1st of each month — via the LeasesTable "Run Monthly Billing" header action, or `php artisan billing:run-monthly`.
- **Late fees**: must be triggered daily — via `php artisan billing:apply-late-fees`. There is no UI surface for this.

**Production impact:** without scheduling, missing the manual trigger means a month with no invoices generated. Late fees never accrue.

**Suggested fix:** add to [bootstrap/app.php](../../bootstrap/app.php) `->withSchedule()`:

```php
->withSchedule(function (Schedule $schedule) {
    $schedule->command('billing:run-monthly --queue')->monthlyOn(1, '03:00')
        ->name('billing:run-monthly')->withoutOverlapping();
    $schedule->command('billing:apply-late-fees --queue')->dailyAt('04:00')
        ->name('billing:apply-late-fees')->withoutOverlapping();
})
```

Plus a cron job on the server: `* * * * * cd /path-to-app && php artisan schedule:run`.

Not applied inline because: (a) requires an explicit `Schedule` import + new use of `->withSchedule()` which is new to this codebase, (b) operations team needs to be aware of the new cron, (c) production-environment-only concern, (d) easy to misconfigure (timezone, idempotency window) — D-15.

### 🟡 F-23. `InvoiceIssued` mail does not attach the PDF

[Mail/InvoiceIssued.php](../../app/Mail/InvoiceIssued.php) has no `attachments()` method. The email tells the tenant "invoice {number} is ready" but they must log in to the portal (or wait for WhatsApp) to actually read it.

**Why this matters:** the user flow on DEMO-ELTIZAM is "WhatsApp moment" — tenants get the link, open the PDF, pay. An email-with-attachment doubles the safety net (works even when WhatsApp is down).

**Fix sketch:**

```php
use Illuminate\Mail\Mailables\Attachment;
use App\Services\InvoicePdfService;

public function attachments(): array
{
    $pdf = app(InvoicePdfService::class)->build($this->invoice);
    $name = app(InvoicePdfService::class)->filename($this->invoice);
    return [
        Attachment::fromData(fn () => $pdf, $name)->withMime('application/pdf'),
    ];
}
```

Cost: PDF rendering moves to the queue job. Each render ≈ 30-150ms; not a problem at the scale of 1 invoice / lease / month.

Deferred (D-16) for explicit go-ahead because some operators consider PDF-by-email a deliverability liability (size, spam scoring); some prefer link-only.

### 🟡 F-24. ETA defaults to mock mode — production must flip

`config/eta.php` has `'mock' => env('ETA_MOCK', true)`. Seeded `EtaSettings` accordingly. For demo this is correct (no real submissions). For production a real-mode flip plus 3 env vars (`ETA_CLIENT_ID`, `ETA_CLIENT_SECRET`, `ETA_ENDPOINT`, plus `eta.issuer.*` configuration in settings) is required.

**Not a code bug** — documenting for the production checklist (Module 20 / 999).

### 🟢 ETA tenant-tax-id guard

`EtaJsonBuilder` throws `RuntimeException` when `tenant.tax_id` is null on B2B submission. Tested. This is the right behavior — silently submitting with no tax_id would have the regulator reject the whole document.

### 🟢 LateFeeService double-application guard

`applyTo($invoice)` checks for an existing `late_fee` item before creating a new one. Tested. So running the daily job twice in the same day is safe.

### 🟢 MonthlyBillingService idempotency

`runForPeriod` skips already-billed leases. Tested. The DEMO §8 narration ("the system skips anyone already billed for this period") is literally implemented.

### 🟢 PDF view handles RTL properly

`dir="rtl"` attribute on `<html>` is the right pattern for mPDF + `autoArabic`. EN + AR e2e both produce a downloadable PDF (`05-pdfs.spec.js`).

## 4. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter='Invoice|Eta|Billing|LateFee'` | **63 passed / 0 failed** | 1.48 s |
| `npx playwright test tests/e2e/05-pdfs.spec.js tests/e2e/07-pdf-content.spec.js tests/e2e/13-eta.spec.js` | **9 passed / 0 failed** | 12.6 s |
| `php artisan test --parallel` (post-F-17 fix) | **287 passed / 0 failed** | 4.13 s |

Coverage is strong. Notable gap: no test asserts that `InvoiceResource::getNavigationBadge()` actually respects tenant scope (would catch F-17 if it regressed). Worth adding in the test-writing pass.

## 5. Manual UX

- PDF download EN: confirmed (`05-pdfs.spec.js`).
- PDF download AR: confirmed.
- ETA Valid badges in table: confirmed.
- Run Monthly Billing modal: confirmed (`17-functional-actions.spec.js`).

## 6. Inline fix this module

**F-17 (Invoice carryover)**: 6 LOC change. Pest 287/287 + PDF/ETA e2e 9/9 green after.

Cross-cutting status update:
- ✅ Module 03 Units — fixed
- ✅ Module 05 Invoices — fixed this commit
- ⏳ Module 09 MaintenanceRequests — pending
- ⏳ Module 12 TenantSalesDeclarations — pending
- ⏳ Module 15 Vendors — pending

## 7. Deferred decisions

| # | Decision | Default if not raised |
|---|---|---|
| D-15 | F-22: add `->withSchedule()` cron entries for `billing:run-monthly` (monthly on 1st @ 03:00) and `billing:apply-late-fees` (daily @ 04:00) | Apply during the cross-cutting pass at Module 20; production checklist will require it before go-live |
| D-16 | F-23: should `InvoiceIssued` mail attach the PDF? | Apply — operators want the safety net |
| D-17 | F-24: confirm production cutover checklist for ETA — env vars + settings + flip mock to false | Document at Module 08 ETA audit + Module 20 cross-cutting |

## 8. Verdict

**🟡 Yellow.** The Invoice surface is the codebase's most production-load-bearing area, with strong tests and clean service design. F-17 was a real per-property bug (overdue count was global), fixed inline. F-22 is a production-readiness gap (no cron). F-23 is a UX upgrade opportunity. F-24 is purely a config/secrets concern.

Module ratings: 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡 · 05 🟡.

## Next

Module 06 — Payments. Surface: [Payment model](../../app/Models/Payment.php), payment-to-invoice allocation logic, [Admin Payments resource](../../app/Filament/Admin/Resources/Payments/), portal payment visibility, and how `Invoice::recomputeTotals()` reacts when a payment is allocated/voided. Module 06 also intersects [01-dashboard.md F-3](01-dashboard.md) (Collected This Month KPI).
