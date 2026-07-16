# Atriom — Mall-Management ERP · Project Overview

> **The single, current source-of-truth overview.** Start here, then drill into the
> per-module docs in [`docs/modules/`](modules/) for business-logic detail.
> Last consolidated 2026-07-16.
>
> **New here, or feeling lost?** Read [PROJECT-MAP.md](PROJECT-MAP.md) for the generated
> census (what exists, how much, what's covered) and the **[visual handbook](visual/)** —
> [the whole system on one page](visual/map.md) and [a month in the life](visual/scenarios.md)
> — before this file. Pictures first; this is the reference.

---

## 1. What Atriom is

Atriom is an **Egyptian retail-mall operations platform** (ERP). It runs the day-to-day of
shopping-centre management: leasing, monthly billing, collections, CAM reconciliation,
percentage-rent on tenant sales, maintenance, vendor management, marketing budgets, and
**ETA e-invoicing** (Egyptian Tax Authority compliance).

**Actors / brand map** (easy to confuse — anchor on this):

| Term | Who | Surface |
|---|---|---|
| **Atriom** | The product/platform (this codebase) | — |
| **Eltizam** | The **operator** — manages malls, performs the work. **Live customer (deal closed 2026-06-27).** | Admin app `/admin` (User + roles) |
| **Jawad** | An **owner** customer — owns a property, gets oversight + raises owner-requests | Admin app `/admin`, scoped to owned properties (no separate portal) |
| **Tenants** | The **retailers / F&B / service shops** leasing units | Tenant portal `/portal` + mobile app |
| **PropEzy** | A competitor (research kept in `docs/gap-analysis/`) | — |

**Status:** all original requirements built + validated; a large Pest suite (**2227 tests** as of 2026-07-16 — live counts in [PROJECT-MAP.md](PROJECT-MAP.md)) + a Playwright E2E suite; production-ready, in a live pilot with Eltizam. Being extended per the **Eltizam FRD** into facility management — see [ROADMAP.md](ROADMAP.md).

---

## 2. Architecture at a glance

Three authenticated surfaces over one MySQL source-of-truth:

| Surface | Path | Auth guard | Identity model |
|---|---|---|---|
| **Admin app** (operator + owners) | `/admin` | `web` (session) | `User` + spatie roles |
| **Tenant portal** | `/portal` | `portal` (session) | `TenantUser` (multi-user per tenant) |
| **Mobile API** | `/api/v1/*` | `tenant-api` (Sanctum) | `Tenant` (company login) |

- **Stack:** Laravel 13 · PHP 8.4 · Filament 4 · MySQL (prod/local) / SQLite `:memory:` (tests) · Pest 4 + ParaTest. Packages: spatie **permission / settings / activitylog / medialibrary**, Laravel **Sanctum**, **Paymob** (card payments), **ETA** (e-invoicing).
- **Multi-property tenancy:** the admin panel's Filament "tenant" is an **`Asset` (property)**; resource *tables* auto-scope to the selected property via `App\Support\TenantScope`. An **"All Properties"** pseudo-asset (`Asset::ALL_PROPERTIES_CODE`) shows the portfolio. See [`18-rbac-scoping`](modules/18-rbac-scoping.md).
- **Single-action services** hold business logic; controllers/Filament pages stay thin.

---

## 3. The modules (detailed docs)

Each file in [`docs/modules/`](modules/) is the authoritative reference for that module —
purpose, domain model, business rules, lifecycle/state-machine, services, Filament fields,
**extension points (how to change it safely)**, gotchas, and tests.

| # | Module | What it owns |
|---|---|---|
| 01 | [Properties & Units](modules/01-properties-units.md) | Assets (malls), Units, occupancy projection, All-Properties |
| 02 | [Tenants](modules/02-tenants.md) | Lessee companies + identity fields (tax/commercial register) |
| 03 | [Tenant Portal — multi-user](modules/03-tenant-portal-users.md) | `TenantUser` logins, admin-vs-readonly gating |
| 04 | [Leases](modules/04-leases.md) | Contracts, multi-unit/master-unit, create/renew/terminate/escalate |
| 05 | [Billing & Invoices](modules/05-billing-invoices.md) | Monthly billing, VAT, proration, charge frequencies |
| 06 | [Payments & allocation](modules/06-payments.md) | Payments, AR recompute, late fees, Paymob |
| 07 | [Credit Notes](modules/07-credit-notes.md) | Issue/apply/void, `credit_applied_amount` |
| 08 | [CAM reconciliation](modules/08-cam.md) | Expense pools, pro-rata allocations, true-up |
| 09 | [Tenant Sales & Percentage Rent](modules/09-tenant-sales-percentage-rent.md) | Sales declarations → breakpoint percentage rent |
| 10 | [Utility Meters](modules/10-utility-meters.md) | Meters + readings, consumption |
| 11 | [Maintenance](modules/11-maintenance.md) | Work-orders, state machine, SLA, department routing |
| 12 | [Vendors & Contracts](modules/12-vendors.md) | Vendors, contacts, contracts, expiry |
| 13 | [Marketing](modules/13-marketing.md) | 5% levy, budgets, spend (warn-but-allow overspend) |
| 14 | [Departments](modules/14-departments.md) | Fixed org set, membership→role, messaging |
| 15 | [Owner Requests & Owner model](modules/15-owner-requests-and-model.md) | Owner→operator/owner requests; owner-as-admin |
| 16 | [ETA e-invoicing](modules/16-eta-einvoicing.md) | Egyptian Tax Authority submission + status |
| 17 | [Reports](modules/17-reports.md) | Monthly close, AR aging, statement PDFs |
| 18 | [RBAC, authorization & scoping](modules/18-rbac-scoping.md) | 9 roles, RoleGatedActions, TenantScope |
| 19 | [Notifications & scheduled scans](modules/19-notifications-scans.md) | Bell/email flows, idempotent scan commands |
| 20 | [Mobile API (v1)](modules/20-mobile-api.md) | Sanctum endpoints, Paymob webhook |
| 21 | [General Ledger & Accounting Core](modules/21-general-ledger.md) | Double-entry ledger: chart of accounts (دليل الحسابات), journal entries (قيود اليومية), fiscal periods, trial balance (ميزان المراجعة), general ledger (دفتر الأستاذ) — Phase 0 |
| 22 | [Inventory & Stock](modules/22-inventory.md) | Per-property warehouses, item catalog, stock ledger, maintenance-ticket consumption, GL costing — **complete** |
| 23 | [Fixed Assets & Depreciation](modules/23-fixed-assets.md) | Fixed-asset register + straight-line depreciation + full GL posting (acquisition, depreciation, disposal write-off with gain/loss) — **complete** |
| 24 | [HR / Employees](modules/24-hr-employees.md) | Employee master + advances/loans (سلف) posting to the GL + per-employee payroll lines & bilingual payslip PDFs — **complete** |
| 25 | [Treasury / Custody](modules/25-treasury-custody.md) | Custodies (عهدة) — cash in a custodian's hands, settled by categorised expenses or returns, posting to the GL — **Phase 1** (multi-treasury / multi-currency on the roadmap) |
| 26 | [Facility Maintenance (PPM + CM)](modules/26-preventive-maintenance.md) | The **internal work-order system**: recurring plans that auto-raise work orders with **pass/fail checklists** (daily scan) + a lock-safe completion gate, the **equipment register** (maintainable-asset codes + sub-codes), and **corrective maintenance** raised from a failed check or as a follow-up on a closed job (internal/external, never reopened). CM lives here, not module 11 — a common-area fault has no tenant and no unit. **Per-property SLA** (clock starts on acceptance) with breach detection + **vendor penalties** charged to their bill, and **spare parts** (internal draw needs approval by value, external purchase recorded). Fault attribution + tenant recharge pending |
| 27 | [Announcements](modules/27-announcements.md) | Operator broadcasts to a property's active tenants via in-app bell + mobile push (no email); compose-is-send, property-scoped, queued fan-out — **complete** |
| 28 | [Approvals](modules/28-approvals.md) | The **value → approver ladder** (`approval_rules`): "does this amount need signing off, and by whom?", resolved by amount against tier permissions. Operator-wide (not per property); fails **closed** to the strictest tier on a gap. Sole reader = `ApprovalPolicy`. Live for spare-part draws; procurement + permits to follow. ⚠️ amounts need operator sign-off |

---

## 4. Core business rules (quick reference)

| Rule | Value | Where |
|---|---|---|
| VAT | **14%** on service charges; **base rent is VAT-exempt** | Billing |
| Marketing levy | **5%** of base rent (configurable, captured per-charge) | Marketing |
| AR balance | `paid_amount = captured payments + credit_applied_amount`; `balance = total − paid` | Invoice::recomputeTotals |
| Delete | **super_admin only**; bulk-delete disabled project-wide | RBAC |
| Tenant writes | **only admin `TenantUser`s** submit/pay in the portal; others read-only | Portal |
| Terminal work-orders | closed/cancelled maintenance + responded owner-requests are **immutable** | Maintenance / Owner Requests |
| Cross-tenant API | returns **404** (not 403) — no existence enumeration | Mobile API |

---

## 5. Quality & testing

QA ran in layers — see [`docs/modules/`](modules/) gotchas sections and the regression suite:

- **Pest tests** (`vendor/bin/pest --parallel`; run with `--parallel` per project convention). `:memory:` sqlite. Counts are generated into [PROJECT-MAP.md](PROJECT-MAP.md) — don't hand-type them here; that's how this file drifted.
- **Self-enforcing conformance gates** — the load-bearing ones. `PropertyIsolationConformanceTest` (every model classified + scoped + guarded), `AdminSmokeManifestConformanceTest` (E2E covers every resource), and `GlRegistryConformanceTest` (every journalizer is actually dispatched). Where a gate exists, drift fails CI; where one doesn't, drift ships — see the SLA-penalty posting bug in [modules/21](modules/21-general-ledger.md#gl-registry-gate).
- **Scenario suites** — `tests/Feature/Scenarios/` (RBAC matrix, scoping, every module's happy/negative/boundary/state cases).
- **Regression suite** — `tests/Feature/Regression/` (one guard per fixed bug, each verified to fail without its fix), incl. `Regression/Validation/` — field-validation guards proving each form rule rejects bad input.
- **Field-validation hardening (2026-06-27)** — every Filament resource was audited field-by-field against its column constraints; 26 fault-tolerance fixes applied (property-scoped tenant selects, non-negative money, unique constraints, date ordering, length caps) + 32 regression cases.
- **Reconciliation harness** — `php artisan billing:reconcile [--month=YYYY-MM]` independently re-derives the AR books from source (line items, captured allocations, applied credits) and confirms stored totals tie out (read-only; exits non-zero on any discrepancy). Guarded by `tests/Feature/Reconciliation/`. Run before a monthly close or tax filing.
- **E2E** — `tests/e2e/` Playwright (`npx playwright test --project=chromium` against Herd `mall-management.test`).
- **Concurrency** validated against real MySQL (late-fee + scan idempotency under parallel runs).
- **Security** — adversarial pentest pass (auth/scoping/webhook-HMAC/secrets) found only low info-disclosure, fixed.

Demo logins (password `password`): `admin@mall.test` (super_admin) · `manager@/viewer@/leasing@/maintenance@/accounting@/marketing@/hr@mall.test` · `owner@atriom.test` (owner) · portal `tenant1@atriomwalk.test` (admin) / `staff1@atriomwalk.test` (read-only).

---

## 6. Other docs

| Doc | Purpose |
|---|---|
| [docs/BUSINESS-RULES.md](BUSINESS-RULES.md) | **Business-rules & assumptions register** — every encoded financial rule (VAT, levy, CAM, late fees, percentage rent…) for **operator/accountant sign-off before go-live**. Verified accurate against code 2026-06-27. |
| [docs/PRODUCTION-RUNBOOK.md](PRODUCTION-RUNBOOK.md) | **Go-live runbook** — env, deploy steps, queue worker, scheduler cron, backups, observability, and the pre-flight gates (integrations:check · billing:reconcile). |
| [README.md](../README.md) | Repo entry — setup, panels, demo accounts |
| [docs/FUNCTIONAL-REQUIREMENTS.md](FUNCTIONAL-REQUIREMENTS.md) | The FRD — requirements ↔ build status |
| [docs/PROGRESS.md](PROGRESS.md) | Feature-by-feature validation workbook |
| [docs/VALIDATION-GUIDE.md](VALIDATION-GUIDE.md) | How to validate each feature in the app |
| [docs/api/](api/) | Mobile API reference |
| [docs/gap-analysis/](gap-analysis/) | Per-feature technical gap analysis + deferred backlog + production checklist |
| [INFRA.md](../INFRA.md) · [PAYMOB-SETUP.md](../PAYMOB-SETUP.md) · [PAYMOB-FLUTTER.md](../PAYMOB-FLUTTER.md) · [MOBILE-APP-BRIEF.md](../MOBILE-APP-BRIEF.md) | Ops / integration / mobile |

---

## 7. Working on the project — where to look

- **Changing business logic of a module** → read its `docs/modules/NN-*.md` first (esp. *Business rules* + *Extension points* + *Gotchas*), then the cited service/model.
- **Money/AR changes** → respect `Invoice::recomputeTotals` as the single source of truth (payments pivot + `credit_applied_amount`).
- **Adding a Filament field** → mirror the surrounding fields; property-scope any cross-property select via `TenantScope::selectable*` helpers; add validation.
- **Adding a role/permission** → `database/seeders/RolesPermissionsSeeder.php`; resources gate via `RoleGatedActions`.
- **A scheduled job** → `routes/console.php`; make it idempotent + lock-safe (see the scan commands).
- **Always**: build → test (`pest --parallel`) → update the relevant `docs/modules/*` + this overview in the same commit.
