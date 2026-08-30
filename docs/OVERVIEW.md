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
~~**ETA e-invoicing**~~ (Egyptian Tax Authority compliance) — **🧊 FROZEN 2026-08-22**: module 16 is off in code (`Modules::FROZEN`) and appears nowhere in the running system; the services and their tests are kept dormant.

**Actors / brand map** (easy to confuse — anchor on this):

| Term | Who | Surface |
|---|---|---|
| **Atriom** | The product/platform (this codebase) | — |
| **Eltizam** | The **operator** — manages malls, performs the work. **Live customer (deal closed 2026-06-27).** | Admin app `/admin` (User + roles) |
| **Jawad** | An **owner** customer — owns a property, gets oversight + raises owner-requests | Admin app `/admin`, scoped to owned properties (no separate portal) |
| **Tenants** | The **retailers / F&B / service shops** leasing units | Tenant portal `/portal` + mobile app |
| **Contractors** | The **external vendors** dispatched to maintenance work | Vendor portal `/vendor` (`VendorContact`) |
| **PropEzy** | A competitor (the benchmark verdicts live in [`docs/gap-analysis/`](gap-analysis/README.md)) | — |

**Status:** all original requirements built + validated; a large Pest suite (**live counts are generated into [PROJECT-MAP.md](PROJECT-MAP.md)** — never hand-typed here, which is how this line drifted before) + a Playwright E2E suite; production-ready, in a live pilot with Eltizam. Being extended per the **Eltizam FRD** into facility management — see [ROADMAP.md](ROADMAP.md).

---

## 2. Architecture at a glance

**Four** authenticated surfaces over one MySQL source-of-truth — it read *three* here for two days
after the vendor portal shipped on 2026-08-28, and the public landing page said three as well:

| Surface | Path | Auth guard | Identity model |
|---|---|---|---|
| **Admin app** (operator + owners) | `/admin` | `web` (session) | `User` + spatie roles |
| **Tenant portal** | `/portal` | `portal` (session) | `TenantUser` (multi-user per tenant) |
| **Vendor portal** (contractors) | `/vendor` | `vendor` (session) | `VendorContact` (own reset-token table) |
| **Mobile API** | `/api/v1/*` | `tenant-api` (Sanctum) | `Tenant` (company login) |

The landing page at `/` now **derives** its tiles from `Filament::getPanels()` rather than listing
them, so a fifth panel cannot ship unadvertised (`LandingPageConformanceTest`).

- **Stack:** Laravel 13 · PHP 8.4 · Filament 4 · MySQL (prod/local) / SQLite `:memory:` (tests) · Pest 4 + ParaTest. Packages: spatie **permission / settings / activitylog / medialibrary**, Laravel **Sanctum**, **Paymob** (card payments). *(**ETA** e-invoicing is built but FROZEN — see [modules/16](modules/16-eta-einvoicing.md).)*
- **Multi-property tenancy (property-first):** the admin panel's Filament "tenant" is an **`Asset` (property)**; resource *tables* auto-scope to the selected property via `App\Support\TenantScope`. The operator always works **inside one real mall** — the switcher no longer offers the "All Properties" pseudo-asset, and `/admin/ALL` 404s (see [PROPERTY-ISOLATION.md](PROPERTY-ISOLATION.md)). The `Asset::ALL_PROPERTIES_CODE` pseudo-asset + its consolidation plumbing are **kept** for a future read-only portfolio surface. See [`18-rbac-scoping`](modules/18-rbac-scoping.md).
- **Single-action services** hold business logic; controllers/Filament pages stay thin.
- **The sidebar is ONE file** — `App\Support\Navigation`. Fourteen groups in a fixed order, each an ordered list of screen classes, rendered through Filament's `Panel::navigation()` builder rather than by auto-discovery; **array order IS sidebar order**, so there are no sort integers left to collide. It replaced a `getNavigationGroup()` + `$navigationSort` pair declared in all 99 screen classes, which had produced a group thirteen pages referenced and the panel never declared (the whole financial-reporting section floated at the bottom in discovery order), three pages in no group at all, and fifteen colliding sorts. Each entry splices in the screen's own `getNavigationItems()` so labels, icons, badges and active-state stay where the screen declares them — **but the visibility gate does NOT come with them**: `shouldRegisterNavigation()` and `canAccess()` are checked in `registerNavigationItems()`, which a custom builder never calls, so `Navigation::itemsFor()` restates both. `NavigationConformanceTest` discovers every screen from the PANEL (not from the registry — a gate reading only what it guards cannot see what that registry omits), fails on one the file does not place, and then RENDERS the sidebar as a super_admin and as a restricted role to prove both the completeness and the refusals.
- **Every optional module has a switch, and only super_admin may move it.** `App\Support\Modules::KEYS` grew from 16 entries to 34 on 2026-08-23 — most of the system had been "core" only in the sense that nobody had decided otherwise. A key is also the resources' PERMISSION module, so `RoleGatedActions` gates navigation and every `can*` on it with no per-resource edit; catalogues do not get switches of their own but follow their owner through `Modules::FEATURE_OF` (`trades` → `facility`, `utility_tariffs` → `utility_meters`, …). The switch itself gates on `hasRole('super_admin')`, **not** on `settings.manage` — that permission is grantable, and "remove Owner Statements from this system" reaches every property, every user and every scheduled job at once. Two layers, and the second is the real one: the toggles render disabled, and `Settings::save()` strips the whole `modules` group from the submitted state, because a disabled input's value still arrives in the Livewire payload. See [`18-rbac-scoping`](modules/18-rbac-scoping.md).
- **A screen is SEVERAL Livewire components, and they refresh independently.** A record page, each of its relation managers and each header widget are separate components; Livewire re-renders only the one that handled the click, and Filament's `refreshFormData()` refills from the page's **in-memory** record without re-reading it. Both halves are fixed at one seam each: `App\Support\Filament\RefreshesRecordState` makes the refill re-read first (it was a no-op at 19 call sites across 8 money pages, because every money service re-reads its subject as a locking read into a new instance), and `App\Support\Filament\RecordChanged` is the single event a component announces so the rest of the screen re-reads — dispatched automatically by `AuthorizedAction` and the three `Announcing*Action` bindings, so no call site has to remember it. `RecordStateRefreshConformanceTest` fails the build if a page refills without the trait, or declares a derived path that names no column.

---

## 3. The modules

**[`docs/modules/README.md`](modules/README.md) is the index** — all 38 modules, grouped by the
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
| Custom fields | The operator adds their own fields to **tenants, leases, units, vendors and properties** — no deploy. Answers live in each record's `metadata` JSON, and only keys the catalogue defines are ever written, so a fillable JSON column is not a mass-assignment surface. The **key and the record type are immutable** (they are the address of every answer); the LABEL is renamed freely and reaches every record at once. Deactivating stops a field being asked and never blanks an answer; deleting is refused once anyone has answered. Money documents are deliberately excluded — an invoice is evidence | `App\Support\CustomFields` · `/admin/custom-fields` |
| Tenant writes | **only admin `TenantUser`s** submit/pay in the portal; others read-only | Portal |
| Terminal work-orders | closed/cancelled maintenance + responded owner-requests are **immutable** | Maintenance / Owner Requests |
| Cross-tenant API | returns **404** (not 403) — no existence enumeration | Mobile API |
| Document issuer | Every generated document — 13 PDFs, the invoice email, the hosted payment page — names the **operator**, resolved in one place from `TaxSettings::seller_legal_name`, never a template literal. A tax document (invoice **and** credit note) also carries `seller_tax_registration_number` and a taxable-value-by-rate split; and every document may carry `seller_billing_email`, the address a tenant writes to about a bill. All three default to empty and each line prints ONLY when set, because a placeholder reads as valid and fails on use — which is exactly what happened: until 2026-08-21 the invoice, tenant statement and asset statement printed `billing@{property-slug}.test`, fabricated in the template from the mall's own name | `App\Support\IssuingEntity` · `App\Support\VatSummary` |
| Document language | **A document is written in the language its READER reads, not its sender's.** Every PDF rendered in `app()->getLocale()` — the operator's panel language, or `config('app.locale')` for a scheduled billing run — so an operator working in Arabic e-mailed Arabic invoices to tenants whose accountants file in English, with no way to produce the other copy. `App\Support\Pdf\DocumentLocale::resolve()` answers in order: what the operator picked on the download modal → the RECIPIENT's stored `locale` → the current request → `config('app.locale')`, each tier clamped to `SetLocale::SUPPORTED` because an unsupported value fails silently into the fallback locale. Counterparty downloads carry a language picker (`App\Support\Filament\PdfDownloadAction`) pre-selected to the recipient; the API takes `?lang=`, where the caller IS the recipient so the REQUEST wins over any stored column. The e-mailed invoice follows the TENANT (a tax document addressed to the company), while the e-mail body follows the reader | `App\Support\Pdf\DocumentLocale` · `locale` on tenants, vendors, employees, users, portal logins — all five registered in `ValueSets` |
| Document rendering | **One renderer, one stylesheet, one font — set in DIRECTION D**, chosen 2026-08-28 from four drawn side by side in both languages: a full-bleed navy band carrying the mall's identity, everything below it white paper with hairlines, and the balance set apart in an amber panel. Adopting it was an edit to three shared files rather than twelve templates. The bleed needs a page with no side margin (`PdfDocument::bleed()`, gated both ways), because an inset band is a coloured box, not a masthead. **Underneath:** All 13 `*PdfService` classes ended in the same twenty lines of mpdf config and had already drifted (12mm vs 14mm margins, 10pt vs 10.5pt), and six templates each carried their own copy of ~150 lines of near-identical CSS. `App\Support\Pdf\PdfDocument` is the only thing that constructs mpdf; `resources/views/pdf/layout.blade.php` + `_styles` + `_issuer` are the shared shell; `App\Support\Pdf\DocumentTheme` is the palette. Every document carries a running footer with its own reference and `page x of y`, and a voided one is watermarked. The font is **IBM Plex Sans Arabic** (checked into `resources/fonts/`), one family across both scripts — mpdf's bundled Arabic face broke the glyph joins in ordinary Egyptian vocabulary («تاريخ الاستحقاق» on the invoice due-date line) | `App\Support\Pdf\PdfDocument` · `App\Support\Pdf\DocumentTheme` |
| Bidi in documents | Operator-typed text keeps its OWN direction inside a document written in the other one. The Unicode algorithm resolves a NEUTRAL character (a full stop, a `+`) by what surrounds it, so an Arabic invoice rendered `.Issued in error` and `201808046413+`. `App\Support\Pdf\Bidi::isolate()` fences a value with the mark matching its FIRST STRONG character; `isolateLines()` does it per line for a block. Marks, not isolates: **mpdf implements neither U+2068 FSI nor `<bdi>`** — measured | `App\Support\Pdf\Bidi` |

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
| [docs/STATUS.md](STATUS.md) | **The single list of questions still waiting on an answer** — and nothing else. Ordered by deadline (before the first invoice · before the first month · *do you need this?* · confirm-a-default) with what ships if nobody answers, and a §7 index of what was CLOSED because the code answers it. Re-verified against the code 2026-08-23. |
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
