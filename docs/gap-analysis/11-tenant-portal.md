# Module 11 — Tenant Portal

> Date: 2026-05-31
> Status: 🟢 Green — well-scoped read-only-plus-submit panel; 4 Yellow extensibility findings (Pay Now is a stub, no profile self-update, no password reset, no CAM visibility).
> Surface: [PortalPanelProvider](../../app/Providers/Filament/PortalPanelProvider.php), 4 Portal Resources, 2 Portal widgets, [TenantStatementPdfService](../../app/Services/TenantStatementPdfService.php). Cross-refs to [Module 02 F-8, F-9](02-tenants.md), [Module 05 InvoiceResource](05-invoices.md), [Module 06 PaymentResource](06-payments.md), [Module 09 MaintenanceRequestResource](09-maintenance.md), [Module 12 TenantSalesDeclarationResource](12-tenant-sales.md) (pending).

## 1. Panel (no changes since Module 02)

`id=portal`, path `/portal`, auth guard `portal`. Branding `Atriom · Tenant Portal`. Widgets pinned: `AccountBalance`, `OpenMaintenance`. Middleware stack ends with `SetLocale` (RTL-ready for AR locale).

## 2. Resources (`app/Filament/Portal/Resources/`)

| Resource | LOC | Scope filter | Mode | Pages | Notable |
|---|---:|---|---|---|---|
| Invoices | 156 | `where('tenant_id', Auth::guard('portal')->id())` | Read-only | List + View | 8 cols, status/unit/period/unpaid_only filters, **Download PDF** (per row), **Pay Now** (stub — see F-42), **Download Statement** (header — calls `TenantStatementPdfService`). |
| Payments | 88 | `where('tenant_id', Auth::guard('portal')->id())` | Read-only | List + View | 5 cols; method/status/date filters. |
| MaintenanceRequests | 79 + 92 form + 82 table | `where('tenant_id', Auth::guard('portal')->id())` | Submit + view + comment | List + Create + View | Form: title/category/priority/unit_id (from active leases)/description/attachments (5 × 10 MB images/PDF/video). Public-comments-only relation manager (`is_internal=false` filter). |
| TenantSalesDeclarations | 60 form + 59 table | `whereHas('lease', fn => where('tenant_id', auth-id))` | Submit + view | List + Create + View | Form: lease_id (filtered to `has_percentage_rent=true`), period_start/end (default last month), declared_sales (EGP). Once submitted, tenant cannot edit (admin reviews → `locked` or `disputed`). |

## 3. Widgets

| Widget | LOC | Display |
|---|---:|---|
| [AccountBalance](../../app/Filament/Portal/Widgets/AccountBalance.php) | 58 | 4 stats: Outstanding Balance (`Tenant::outstandingBalance()` nets credit notes), Overdue Invoices count, Active Leases count, Lifetime Paid. Danger/success coloring. |
| [OpenMaintenance](../../app/Filament/Portal/Widgets/OpenMaintenance.php) | 75 | Table widget — open requests sorted latest first; columns reference/title/unit/priority/status/submitted_at. Empty state with wrench icon. Unpaginated. |

## 4. TenantStatementPdfService

[Services/TenantStatementPdfService.php](../../app/Services/TenantStatementPdfService.php) (98 LOC). `build($tenant)` → mPDF Blade (`tenants.statement` view). 12-month trailing window. Data: open invoices sorted by due_date, recent invoices in window sorted DESC, captured payments in window, summary rollup (outstanding / overdue / total_billed / total_paid / open_count). mPDF: `xbriyaz` for AR, `dejavusans` for EN. Filename `Statement-{slug}-{Ymd}.pdf`.

Invoked from **both** Admin (`Tenants → row → Statement` per DEMO-ELTIZAM L303) and Portal (Invoices list header action).

## 5. Spec map

| Source | Verbatim | Verified |
|---|---|---|
| FEATURES.md | "AccountBalance widget (outstanding / overdue / active leases / paid lifetime)" | ✅ |
| FEATURES.md | "OpenMaintenance widget" | ✅ |
| FEATURES.md | "Read-only Invoices + Payments scoped to auth('portal')->id()" | ✅ |
| FEATURES.md | "Maintenance Requests (list own, submit new with attachments, view status + public comments)" | ✅ |
| FEATURES.md | "Download PDF on each invoice" | ✅ |
| FEATURES.md | "Statement of Account on Invoices list header action" | ✅ |
| FEATURES.md | "TenantSalesDeclaration submission + status visibility" | ✅ |
| DEMO-ELTIZAM.md L216-228 | "Tenant portal — the WhatsApp moment (1:30 min)" | ✅ all 4 resources accessible; PDF downloadable |
| DEMO-ELTIZAM.md L228 | "Egyptian tenants live on WhatsApp. They share the PDF with their accountant in two taps." | ✅ (depends on browser PDF share affordances, but supported) |

## 6. Findings

### 🟡 F-42. "Pay Now" is a stub

[Portal/Invoices/Tables/InvoicesTable.php:141-152](../../app/Filament/Portal/Resources/Invoices/Tables/InvoicesTable.php#L141-L152) defines a `payNow` action that:

- only renders when `config('integrations.paymob.enabled')` is true AND invoice balance > 0
- on click, requires confirmation, then shows a `Notification::make()` with the invoice number — no PSP redirect, no payment intent, no callback handler

This is currently **safe by default** (Paymob flag is off, button doesn't render). It's a placeholder for the eventual integration. Production rollout requires a real Paymob (or InstaPay / Card) flow plus a callback route that records a Payment and recomputes the invoice.

**Defer D-33** — pilot decision: integrate Paymob / InstaPay before non-demo deployment, or accept the WhatsApp/offline rails as the v1 collection channel.

### 🟡 F-43. No portal CAM allocation visibility (cross-ref M07 F-29, M10 F-41)

Tenants get a `Charge` row on their invoice (`CAM Reconciliation — 2026 — EGP X`) but no breakdown of how X was computed. They'd have to email an operator to ask.

Same scope as [Module 07 F-29](07-cam.md#-f-29-no-cam-visibility-on-tenant-portal) and [Module 10 F-41](10-owner-portal.md#-f-41-owner-role-has-camview-permission-but-no-cam-resource). Three sides of the same surface gap; bundle decision at D-22.

### 🟡 F-44. No notification to tenant when admin locks a declaration

`TenantSalesDeclaration` flips from `submitted` → `locked` on admin action (Module 12 will detail). Tenant must refresh the portal to see the new status. No email / push / WhatsApp ping. Once a percentage_rent Charge is auto-generated on the lease, the next invoice will surprise them.

**Defer D-34** — bundle with Module 09 F-37 (general maintenance notification design).

### 🟡 F-45. No "Download past 12-month statements as ZIP" / archive

Each statement is generated on-demand from current data, so a tenant who downloaded last month's statement gets last month's numbers if they re-download — that's correct behavior. But a tenant who wants their last 12 historical statements at once has to call them up one by one. Not a bug, just an absent convenience.

**Defer D-35** — low priority.

### ✅ Cross-refs to known findings already documented

- **F-8 (Module 02)**: no password reset flow on portal. Still open. Re-flagged here so Module 20 cross-cutting picks it up.
- **F-9 (Module 02)**: no tenant profile self-update. Still open. Same.

### 🟢 No F-17 carryover

None of the 4 Portal Resources override `getNavigationBadge()`.

### 🟢 PII isolation correct

Every Portal Resource scopes via `tenant_id` (direct) or `whereHas('lease.tenant_id')` (indirect via Lease) — so a tenant cannot reach another tenant's record by URL guessing.

### 🟢 Internal comments hidden

`PortalMaintenanceCommentsRelationManager` filters `is_internal=false` — admin's internal triage notes never leak to the tenant.

### 🟢 Sales declaration submit-then-lock UX is correct

`canEdit=false` once submitted (admin owns the locking). The state machine matches the percentage-rent Charge generation lifecycle (Module 12 will verify the lock → charge link).

## 7. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter='Portal'` | **4 passed / 0 failed** | 1.00 s |
| `npx playwright test tests/e2e/04-portal.spec.js` | **4 passed / 0 failed** | 7.2 s |
| Already-run e2e (`17-functional-actions.spec.js`) covering portal: "Submit Sales create form mounts", "Create Maintenance Request form mounts" | both green per Module 04 audit | — |

## 8. No inline fixes this module

All 4 Yellow findings are scope/feature decisions:
- F-42 needs PSP integration design
- F-43/F-44 bundle with broader notification & CAM-portal-view design
- F-45 is low priority

## 9. Deferred decisions

| # | Decision | Default |
|---|---|---|
| D-33 | F-42: integrate Paymob / InstaPay before pilot or keep WhatsApp/offline rails for v1 | Defer — pilot decision |
| D-34 | F-44: notify tenant on declaration lock + status change | Bundle with M09 D-29 (notification design) |
| D-35 | F-45: 12-month archive ZIP | Low priority; revisit post-pilot |

## 10. Verdict

**🟢 Green.** Tenant Portal is correctly scoped, correctly PII-contained, correctly read-only-plus-submit, correctly bilingual via SetLocale + admin.php i18n keys. The 4 Yellow findings are forward-looking extensions, not defects in what's shipped.

Module ratings: 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡 · 05 🟡 · 06 🟢 · 07 🟢 · 08 🟡 · 09 🟡 · 10 🟢 · 11 🟢.

## Next

Module 12 — Tenant Sales Declarations + Percentage Rent. Surface: [TenantSalesDeclaration model](../../app/Models/TenantSalesDeclaration.php), [Admin resource](../../app/Filament/Admin/Resources/TenantSalesDeclarations/) (F-17 carryover candidate), [Portal resource](../../app/Filament/Portal/Resources/TenantSalesDeclarations/) (already inventoried), [PercentageRentCalculationService](../../app/Services/PercentageRentCalculationService.php), and the lock → Charge generation flow.
