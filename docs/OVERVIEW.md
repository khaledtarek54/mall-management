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
| **PropEzy** | A competitor (the benchmark verdicts live in [`docs/gap-analysis/`](gap-analysis/README.md)) | — |

**Status:** all original requirements built + validated; a large Pest suite (**live counts are generated into [PROJECT-MAP.md](PROJECT-MAP.md)** — never hand-typed here, which is how this line drifted before) + a Playwright E2E suite; production-ready, in a live pilot with Eltizam. Being extended per the **Eltizam FRD** into facility management — see [ROADMAP.md](ROADMAP.md).

---

## 2. Architecture at a glance

Three authenticated surfaces over one MySQL source-of-truth:

| Surface | Path | Auth guard | Identity model |
|---|---|---|---|
| **Admin app** (operator + owners) | `/admin` | `web` (session) | `User` + spatie roles |
| **Tenant portal** | `/portal` | `portal` (session) | `TenantUser` (multi-user per tenant) |
| **Mobile API** | `/api/v1/*` | `tenant-api` (Sanctum) | `Tenant` (company login) |

- **Stack:** Laravel 13 · PHP 8.4 · Filament 4 · MySQL (prod/local) / SQLite `:memory:` (tests) · Pest 4 + ParaTest. Packages: spatie **permission / settings / activitylog / medialibrary**, Laravel **Sanctum**, **Paymob** (card payments), **ETA** (e-invoicing).
- **Multi-property tenancy (property-first):** the admin panel's Filament "tenant" is an **`Asset` (property)**; resource *tables* auto-scope to the selected property via `App\Support\TenantScope`. The operator always works **inside one real mall** — the switcher no longer offers the "All Properties" pseudo-asset, and `/admin/ALL` 404s (see [PROPERTY-ISOLATION.md](PROPERTY-ISOLATION.md)). The `Asset::ALL_PROPERTIES_CODE` pseudo-asset + its consolidation plumbing are **kept** for a future read-only portfolio surface. See [`18-rbac-scoping`](modules/18-rbac-scoping.md).
- **Single-action services** hold business logic; controllers/Filament pages stay thin.

---

## 3. The modules

**[`docs/modules/README.md`](modules/README.md) is the index** — all 37 modules, grouped by the
money spine · recoveries and variable rent · counterparties · facility and operations ·
cross-cutting.

Each module file is the authoritative reference for that module: purpose, domain model, business
rules, lifecycle, services, Filament fields, **extension points (how to change it safely)**,
gotchas and tests. **Read the relevant one before changing that module's logic**, and update it in
the same commit.

> The list used to be repeated here as a table. It is not any more, for the reason this whole tree
> was reorganised on 2026-08-19: a second copy of a list is a copy that goes stale, and the reader
> cannot tell which of the two is current.

---

## 4. Core business rules (quick reference)

| Rule | Value | Where |
|---|---|---|
| VAT | Standard rate (**14%** today) on service charges; **base rent is VAT-exempt**. Master data, not a setting — a dated rung on the `VAT_14` tax code, resolved for the DOCUMENT's date via `App\Support\Vat`, so a rate change can be entered in advance and a back-dated invoice keeps the rate that was in force. Only origination reads it, so an issued invoice keeps the rate it was billed at | Billing / General Ledger → Tax codes |
| Marketing levy | **5%** of base rent (configurable, captured per-charge) | Marketing |
| AR balance | **Four settlement channels, and every calculation of "how much of this invoice is settled" must count all four**: captured payments + `credit_applied_amount` + applied tenant credit (`TenantCreditApplication`) + netted security deposit (`DepositApplication`). `balance = total − paid_amount`. Never set `paid_amount`/`balance` directly anywhere else | `Invoice::recomputeTotals()` |
| Delete | **Money records are never deletable — not even by super_admin** (invoice, payment, journal entry, credit note, vendor bill, expense, deposit txn, payroll, cheque): correct via cancel / void / credit note. **Master data with history is refused too** (tenant, vendor, lease, unit, property, employee) — deactivate instead. Everything else: super_admin only, bulk-delete off | `App\Support\DeletionPolicy` |
| Tenant writes | **only admin `TenantUser`s** submit/pay in the portal; others read-only | Portal |
| Terminal work-orders | closed/cancelled maintenance + responded owner-requests are **immutable** | Maintenance / Owner Requests |
| Cross-tenant API | returns **404** (not 403) — no existence enumeration | Mobile API |
| Document issuer | Every generated document — 12 PDFs, the invoice email, the hosted payment page — names the **operator**, resolved in one place from `TaxSettings::seller_legal_name`, never a template literal. A tax document (invoice **and** credit note) also carries `seller_tax_registration_number` and a taxable-value-by-rate split; both settings default to empty and the lines print only when set, because a placeholder TRN reads as valid and fails on audit | `App\Support\IssuingEntity` · `App\Support\VatSummary` |

---

## 5. Quality & testing

QA ran in layers — see [`docs/modules/`](modules/) gotchas sections and the regression suite:

- **Pest tests** (`vendor/bin/pest --parallel`; run with `--parallel` per project convention). `:memory:` sqlite. Counts are generated into [PROJECT-MAP.md](PROJECT-MAP.md) — don't hand-type them here; that's how this file drifted.
- **Self-enforcing conformance gates** — the load-bearing ones. `PropertyIsolationConformanceTest` (every model classified + scoped + guarded), `AdminSmokeManifestConformanceTest` (E2E covers every resource), `MediaPrivacyConformanceTest` (no upload silently lands on the public disk), `GlRegistryConformanceTest` (every journalizer is actually dispatched), and `ResourceLinkConformanceTest` (every dashboard deep link opens the right resource, pre-filtered — the filter name resolves on the destination table, the sort column is actually `->sortable()`, and the page really narrows over real HTTP), and `TranslationKeyConformanceTest` (no screen can render a raw translation key: every `__()` key referenced in code exists in **both** locales, the catalogues carry identical key sets, no file declares a key twice — a duplicate is silently won by the later one and parity checking cannot see it — every resource's and page's labels are *rendered* in EN and AR and checked, and no component ships without a `->label()` — Filament humanises the attribute name into English when one is missing, which is untranslated by OMISSION and invisible to every other check). Where a gate exists, drift fails the suite; where one doesn't, drift ships — see the SLA-penalty posting bug in [modules/21](modules/21-general-ledger.md#gl-registry-gate).
  > **These gates are ADVISORY, not enforced.** CI auto-runs are paused (2026-07-29, owner's call — the pipeline was too slow for the push loop): [`.github/workflows/ci.yml`](../.github/workflows/ci.yml) fires only on `workflow_dispatch`. So "fails CI" means "fails when someone runs it", and nothing blocks a bad change on push. **Keep `vendor/bin/pest --parallel` green locally before every push** — a red push is silent, not a red check.
- **Scenario suites** — `tests/Feature/Scenarios/` (RBAC matrix, scoping, every module's happy/negative/boundary/state cases).
- **Regression suite** — `tests/Feature/Regression/` (one guard per fixed bug, each verified to fail without its fix), incl. `Regression/Validation/` — field-validation guards proving each form rule rejects bad input.
- **Field-validation hardening (2026-06-27)** — every Filament resource was audited field-by-field against its column constraints; 26 fault-tolerance fixes applied (property-scoped tenant selects, non-negative money, unique constraints, date ordering, length caps) + 32 regression cases.
- **Reconciliation harness** — `php artisan billing:reconcile [--month=YYYY-MM]` independently re-derives the AR books from source (line items, captured allocations, applied credits) and confirms stored totals tie out (read-only; exits non-zero on any discrepancy). Guarded by `tests/Feature/Reconciliation/`. Run before a monthly close or tax filing.
- **E2E** — `tests/e2e/` Playwright (`npx playwright test --project=chromium` against Herd `mall-management.test`).
- **Concurrency** validated against real MySQL (late-fee + scan idempotency under parallel runs).
- **Security** — adversarial pentest pass (auth/scoping/webhook-HMAC/secrets) found only low info-disclosure, fixed.

Demo logins (password `password`): `admin@mall.test` (super_admin) · `manager@/viewer@/leasing@/operations@/accounting@/marketing@/hr@mall.test` · `owner@atriom.test` (owner) · portal `tenant1@atriomwalk.test` (admin) / `staff1@atriomwalk.test` (read-only).

---

## 6. Other docs

| Doc | Purpose |
|---|---|
| [docs/BUSINESS-RULES.md](BUSINESS-RULES.md) | **Business-rules & assumptions register** — every encoded financial rule (VAT, levy, CAM, late fees, percentage rent…) for **operator/accountant sign-off before go-live**. Verified accurate against code 2026-06-27. |
| [docs/OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) | **The single hand-out of open questions**, grouped by who can answer (accountant/finance · owner · operations · ETA/IT) and by what breaks if the answer differs. Consolidates the old client-questions + the accountant & operations meeting agendas + the FRD open items + the payroll/depreciation/bank-rec questions. |
| [docs/PRODUCTION-RUNBOOK.md](operations/PRODUCTION-RUNBOOK.md) | **Go-live runbook** — env, deploy steps, queue worker, scheduler cron, backups, observability, and the pre-flight gates (integrations:check · billing:reconcile). |
| [README.md](../README.md) | Repo entry — setup, panels, demo accounts |
| [docs/FUNCTIONAL-REQUIREMENTS.md](requirements/FUNCTIONAL-REQUIREMENTS.md) | The FRD — requirements ↔ build status |
| [qa/UAT-SCRIPTS.md](qa/UAT-SCRIPTS.md) | The business walk-through per persona, for sign-off |
| [docs/api/](api/) | Mobile API reference |
| [docs/gap-analysis/](gap-analysis/) | Per-feature technical gap analysis + deferred backlog + production checklist |
| [docs/benchmarks/yardi/](benchmarks/yardi/README.md) | **How Yardi Voyager Commercial does leasing & money flow**, the Atriom gap analysis against it (keep / extend / rebuild per row), scenarios, user stories, and the sequenced phase plan for the 2026-08 cycle |
| [operations/](operations/) · [integrations/](integrations/) · [api/](api/MOBILE-API.md) | Ops · integrations · the mobile contract |
| [integrations/PAYMOB.md](integrations/PAYMOB.md) | **Paymob, end to end** — the complete implementation reference + port checklist for another system |

---

## 7. Working on the project — where to look

- **Changing business logic of a module** → read its `docs/modules/NN-*.md` first (esp. *Business rules* + *Extension points* + *Gotchas*), then the cited service/model.
- **Money/AR changes** → respect `Invoice::recomputeTotals` as the single source of truth (payments pivot + `credit_applied_amount`).
- **Adding a Filament field** → mirror the surrounding fields; property-scope any cross-property select via `TenantScope::selectable*` helpers; add validation.
- **Adding a role/permission** → `database/seeders/RolesPermissionsSeeder.php`; resources gate via `RoleGatedActions`.
- **A scheduled job** → `routes/console.php`; make it idempotent + lock-safe (see the scan commands).
- **Always**: build → test (`pest --parallel`) → update the relevant `docs/modules/*` + this overview in the same commit.
