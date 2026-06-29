# Plan 2 — Full QA & Coverage Program (go-live confidence)

> **Goal.** Be *confident* the whole system works before production — not just unit tests, but a complete QA program: high automated coverage **with a CI gate**, test-quality verification (mutation testing), static analysis, expanded E2E, the specialised testing we have *none* of today (performance, security, accessibility, contract, RTL), and a structured **manual QA + UAT** pass with a release sign-off. Nothing left behind.
>
> **Status:** IN PROGRESS — Phase 1 (tooling) started 2026-06-29. Drafted 2026-06-28 from a full test-surface investigation.
>
> **Reality check up front:** "100% coverage" is a *means*, not the goal. 100% line coverage with weak assertions proves nothing. The real confidence levers are **(a) a coverage gate so coverage can't regress, (b) mutation testing so we know tests actually catch bugs, and (c) a manual/exploratory QA pass for everything automation can't see.** This plan targets all three.

---

## ✅ Progress log (live)

- **[done] Static analysis (Larastan/PHPStan)** — `larastan/larastan ^3` installed; `phpstan.neon` at **level 5** over `app/`; the 250 pre-existing findings are grandfathered in `phpstan-baseline.neon` so the gate now enforces **no new errors**. Run via `composer analyse`. A `static-analysis` job is wired into `.github/workflows/ci.yml` (ready; CI triggers remain dispatch-only — flip on when you want CI billing). *(this commit)*
- **[done] composer QA scripts** — `composer analyse` (PHPStan), `composer lint` / `composer lint-fix` (Pint, opt-in — codebase isn't Pint-clean yet; a one-time `pint` reformat is a separate task), `composer qa` (analyse + full suite).
- **[done] Model factories** — 16 factories for the core domain models (Asset, Unit, Tenant, TenantUser, Department, Vendor, Lease, Invoice, Payment, CreditNote, TenantRequest, TenantSalesDeclaration, OwnerRequest, MeterReading, UtilityMeter, MarketingBudget), each producing a fully-valid persistable record; `FactoriesSmokeTest` guards them. Added `HasFactory` to Department + Vendor. *(commit `71241ea`)*
- **[blocked] Mutation testing (Infection)** — **not viable with this suite**: Infection's runner invokes `vendor/bin/phpunit`, but **Pest hard-refuses to run under raw PHPUnit** ("Please run ./vendor/bin/pest instead"), and there's no stable Infection↔Pest adapter. Installed + scoped-config tried, then cleanly reverted. *Options for later:* a Pest-native mutation tool if/when one matures, or run Infection only over the few pure-PHPUnit `tests/Unit` files. Test-*quality* confidence meanwhile leans on adversarial review + the regression suite.
- **[deferred] Coverage gate** — a *meaningful* % needs the **merged Pest + Playwright** report (`scripts/coverage-all.sh`); Pest-only coverage understates the Filament UI (covered only by E2E), so a Pest-only `--min` gate would be misleading. Wire the gate to the merged report once CI runs E2E. Tooling already exists (`composer coverage` / `coverage-all`).
- **[deferred — your call] Enable CI on push/PR** — outward-facing (consumes GitHub Actions minutes); the PHPStan job is wired + ready, triggers stay dispatch-only until you opt in (uncomment the `push`/`pull_request` blocks in `.github/workflows/ci.yml`).
- **[done] Phase 2 wave 1 — coverage gaps** — *re-verified the gap list against current code first: most of the investigation's "untested" items (PaymentLinkController, InvoiceIssued mailable, MarketingLevyService, DepartmentMessageService, FcmPushSender, per-resource authz) already have tests.* Genuine holes filled (+66 tests, suite 1224→1290): `Support/TenantScopeTest`, `Support/ModulesTest`, `Support/PortalTest`, and dedicated command tests for `cam:reconcile` / `billing:reconcile` / `billing:scan-overdue-invoices` / marketing ensure+backfill, plus `DeviceToken`/`Note` model tests. *(commit `2560509`)*
- **[done] API contract / OpenAPI** — `dedoc/scramble` generates the `/api/v1` spec; a custom `api:export-spec` command post-processes it to **camelCase** (matching the live `KeyCase::camelKeys` wire convention, since Scramble reads the backend's snake_case) and writes [`docs/api/openapi.json`](../api/openapi.json) — the machine-readable spec the mobile dev imports. `composer api-spec` regenerates it. `ApiSpecContractTest` is the drift guard: it fails if any live `/api/v1` route is undocumented (33 paths, all covered). *(this wave)*
- **[done] Security probes** — cross-tenant 404 isolation + Paymob HMAC + SecurityHeaders were already covered; filled the structural gaps in `Security/SecurityProbesTest`: an **auth-guard matrix** (every non-public `/api/v1` route must carry `auth:tenant-api` — future-proof), the abuse **throttles actually fire** (login 5/min, password-reset 3/min → 429), and **ownership-spoofing + privilege mass-assignment are blocked** (client-supplied `tenant_id`/`status`/`csat_rating` on create are ignored). No app bugs surfaced — the posture held. *(this wave)*
- **[done] Performance / N+1 budgets** — `Performance/QueryBudgetTest` measures the query count at a small vs larger page (both under the page size) and asserts it doesn't grow per-row — the definitive N+1 catch. `/me/invoices` + `/me/maintenance-requests` are constant (eager-loading correct) and now guarded; the pattern extends to the other list endpoints. *(this wave)*
- **[next]** remaining **specialised** testing is now the Playwright/E2E-based work (needs the browser stack against Herd): accessibility (axe-core), RTL layout checks, visual-regression snapshots, multi-browser. These are a distinct E2E workstream. (Spectator per-endpoint response-schema validation deferred — camelCase duality; the spec-completeness guard + per-endpoint feature assertions cover most of the value.)

---

## 1. Where we are today (the honest baseline)

**Strong:** ~1,188 tests — 178 feature files (~1,050 cases) + 18 Playwright E2E specs (~71 cases). Excellent **scenario** coverage (28 multi-step business-flow files), **regression** guards (24, one per fixed bug + validation invariants), near-complete **API endpoint** coverage (37/38), strong **RBAC/scoping/access-control** (just audited), and a clever **combined Pest + Playwright coverage merge** (`scripts/coverage-all.sh`) so Filament UI exercised only by the browser still counts.

**Weak / missing (the gaps to close):**
- **No CI gate.** `.github/workflows/ci.yml` is `workflow_dispatch`-only (disabled on push/PR); coverage runs locally with no minimum threshold.
- **No static analysis** (no PHPStan/Larastan); Pint is a dep but not run in CI.
- **No mutation testing** (no Infection) — test *quality* is unverified.
- **Almost no model factories** (only `UserFactory`); tests build data via `tests/Pest.php` helpers.
- **1 real Unit test** — the suite is integration-heavy (fine, but isolated unit tests are thin for pure logic).
- **Thin/untested specifics:** 9/12 console commands lack a dedicated test; ~13/30 services thin (notably `FcmPushSender`, `CamReconciliationService` unit, ETA signers, `MarketingLevyService`, `BooksReconciliationService`, `AssetStaffRecipients`, `DepartmentMessageService`); `PaymentLinkController` (public endpoint) untested; `InvoiceIssued` mailable untested; thin models (`Department`, `DeviceToken`, `Note`, `InvoiceItem`, `*Comment`); support utils `Modules`/`TenantScope`/`Portal` only tested implicitly.
- **No per-resource authorization tests** (the canCreate/canEdit/canDelete × role matrix) — exactly the class the authz audit just exposed.
- **Whole testing TYPES missing:** performance/load, accessibility (WCAG), API contract/schema, RTL layout, visual regression, browser/device matrix, concurrency, migration rollback, structured security testing.
- **No manual QA artefacts** — no test-case repository, no UAT scripts, no release sign-off checklist.

---

## 2. Coverage strategy & targets (with gates)

Set explicit, enforced targets. Coverage is measured on `app/` minus the already-declared Filament-UI exclusions in `phpunit.xml` (Pages/Schemas/Tables/RelationManagers/Importers/Exporters — those are covered by E2E, merged in).

| Scope | Target | Gate |
|---|---|---|
| Overall `app/` line coverage (Pest+E2E merged) | **≥ 95%** | CI fails below |
| **Critical domains** — `app/Services` (money), `app/Support` (RBAC/scoping), state machines, `app/Http/Controllers/Api`, `app/Notifications` | **100% line** | CI fails below |
| Branch coverage on critical domains | **≥ 90%** | tracked |
| **Mutation score (MSI)** on critical domains (Infection) | **≥ 80%** | CI (scheduled) fails below |
| Translation parity (en/ar) | 100% | already enforced (`TranslationCoverageTest`) |

> "100% of every detail" = 100% on the **critical money/auth/state-machine code** + ≥95% everywhere else + a mutation score that proves the tests bite + the manual matrix in §5 fully ticked. That's the achievable, meaningful definition.

---

## 3. Workstreams

### A. Close automated coverage gaps (get to the numbers)

1. **Turn on coverage measurement + gate** (Xdebug/PCOV already present). Add `--coverage --min=95` for `app/` and a `--min=100` profile for the critical paths; fail CI below.
2. **Model factories** for every model (idiomatic Laravel, composable test data): `Tenant, Asset, Unit, Lease, Invoice, InvoiceItem, Payment, CreditNote, MaintenanceRequest, TenantSalesDeclaration, CamExpensePool/Allocation, Vendor*, Department, OwnerRequest, MarketingBudget/Spend, MeterReading, …`. Keep the `tests/Pest.php` helpers as thin wrappers over factories.
3. **Service unit tests** for the thin/untested ones: `FcmPushSender` (Http::fake — partly done), `CamReconciliationService`, `BooksReconciliationService`, `MarketingLevyService`, `AssetStaffRecipients`, `DepartmentMessageService`, `EtaSubmissionService`/`EtaApiClient`/`EtaDocumentSigner`/`UnsignedEtaSigner`.
4. **Console-command tests** for the 9 untested: `ApplyLateFees`, `RunMonthlyBilling`, `CamAnnualReconciliation`, `ReconcileBooks`, `ScanOverdueInvoices`, `CheckIntegrations`, `EnsureMarketingBudgets`, `BackfillMarketingBudgets` (assert idempotency + dry-run + lock-safety).
5. **Untested endpoints/mailables/models:** `PaymentLinkController` (public pay link + callback redirect), `InvoiceIssued` mailable (render assertion), `Department`/`DeviceToken`/`Note`/`InvoiceItem`/`*Comment` model tests, `Modules`/`TenantScope`/`Portal` direct tests.
6. **Per-resource authorization matrix:** one parameterised test asserting `canViewAny/canCreate/canEdit/canDelete/canDeleteAny` for **every Filament resource × every role** (extend `AuthorizationMatrixTest`). This codifies the authz audit so the bug class can't return.

### B. Test QUALITY — mutation testing (the real confidence lever)

Add **Infection**. Run it on the critical domains (`app/Services`, `app/Support`, the state machines, money math). A passing test suite with a low MSI means the tests don't actually catch regressions. Target MSI ≥ 80% on critical code; raise over time. Run nightly (it's slow), not on every push.

### C. Static analysis & code quality (catch bugs before runtime)

- **Larastan/PHPStan** — start at a level that passes, ratchet up (target level 6–8 on `app/`). Catches null/typing/undefined-method bugs (the kind the IDE flagged this session).
- **Pint** in CI as a **lint gate** (formatting — already a dep).
- **Pest type-coverage** (`--type-coverage --min=...`) — ensures params/returns are typed.
- (Optional) **Rector** for safe automated upgrades.

### D. E2E expansion (full user journeys, every surface)

- **Coverage of journeys:** audit the 18 existing specs against a journey matrix (every admin resource CRUD, every portal action, owner oversight, every report/PDF, every custom action — lock/dispute/void/submit-to-ETA/change-status/assign). Fill gaps.
- **Mobile API E2E:** the browser can't hit `/api/v1`; add a dedicated **API-journey suite** (Pest or a Postman/newman/Playwright-request run) exercising the full tenant journey end to end (login → summary → invoice → paymob-session/pay-demo → poll paid → notifications → maintenance/request → sales declaration → logout), incl. cross-tenant 404s.
- **Multi-browser + mobile viewport:** add Firefox + WebKit + a mobile viewport project to `playwright.config.js`.
- **Visual regression:** Playwright snapshot baselines for key screens + the PDFs (catches layout/RTL breakage).

### E. Specialised QA (the types we have NONE of)

- **Security testing** (codify + extend the audits): the RBAC/scoping/access-control regression we built, plus SQLi/XSS/CSRF/mass-assignment probes, rate-limit/throttle tests, webhook HMAC (Paymob), secret-handling, the cross-tenant 404 invariant, and a periodic dependency scan (`composer audit`). Consider one external pentest pass before go-live.
- **Performance & load:** an **N+1 / query-count budget** asserted in feature tests for hot pages (list resources, dashboard, monthly billing) — fail if queries exceed a budget. A **large-dataset** suite (10k invoices / 1k tenants) timing monthly billing, AR-aging, and the reconcile harness. An **API load test** (k6 or Artillery) hitting `/api/v1` at the throttle ceiling to validate the limits + response times.
- **Concurrency:** assert the lock-safety we built (over-allocation, CAM bill, auto-close, SLA scan, demo-pay) via parallel-request tests against **MySQL** (sqlite can't lock) — extend the existing concurrency approach.
- **Accessibility:** `axe-core` in Playwright on the main admin + portal pages (WCAG 2.1 AA): labels, contrast, keyboard nav, focus order.
- **i18n / RTL:** full translation-completeness (have parity; add *usage* completeness) + **RTL layout** checks (Arabic mirror, number direction — we already lock Western digits) via Playwright on `?locale=ar`.
- **API contract:** publish an **OpenAPI spec** for `/api/v1` (the mobile dev needs it anyway) and add **schema-validation tests** asserting every endpoint's response matches the spec — prevents silent contract drift.
- **Data integrity:** promote `billing:reconcile` into a CI assertion on seeded + generated data; add a broader integrity scan (orphans, negative balances, status invariants).
- **Migration safety:** a `migrate:fresh` + **rollback (`migrate:rollback`) + re-up** test; seeder-integrity test; assert no destructive migration without a backup note.
- **Email/notification rendering:** render every mailable + every notification's mail/database/push payload (assert no missing translation key, no broken variable).

### F. Manual QA & UAT (the "QA testing" you asked for)

Automation can't judge "does this *feel* right / is the workflow correct end to end". Build the human layer:

1. **Test-case repository** — a structured doc (or sheet) per module: **every feature × every role × happy / negative / boundary / permission path**, with steps, expected result, pass/fail. This is the exhaustive "every single detail" matrix. Start from the per-module list in §5. Keep it in `docs/qa/test-cases/` (one MD per module) so it's versioned.
2. **Exploratory charters** — time-boxed exploratory sessions per module ("break the billing run", "abuse the request workflow", "try cross-tenant everything") with notes.
3. **UAT scripts** — end-to-end business scenarios for each persona (Eltizam operator, Jawad owner, tenant on web, tenant on mobile) that the *business* signs off: "issue the month, take a payment, handle a complaint, lock a sales declaration, run the close report".
4. **Cross-surface QA** — the same scenario verified on admin + portal + mobile (e.g. raise a request on mobile → triage in admin → tenant sees the update on portal + push).
5. **Device/browser matrix** — manual smoke on the real target devices (Android-first per the brief, iOS, common browsers).
6. **Bug triage loop** — log → reproduce → fix → add a regression test (close the loop into Workstream A).

### G. CI/CD & process (make it stick)

- **Enable CI on push + PR** (it's disabled). Pipeline: Pint (lint) → PHPStan → Pest with coverage gate → E2E (Chromium) → upload coverage + Playwright report. Nightly: Infection (mutation) + multi-browser E2E + load test.
- **Coverage trend + flaky-test tracking.**
- **Definition of Done** includes tests + docs (already the convention — enforce in CI/PR template).
- **A release sign-off gate** (§6).

---

## 4. Tooling to add (with how)

| Tool | Purpose | Add |
|---|---|---|
| Larastan/PHPStan | static type analysis | `composer require --dev larastan/larastan` + `phpstan.neon` |
| Infection | mutation testing (test quality) | `composer require --dev infection/infection` + `infection.json5` |
| Pest type-coverage | param/return typing | built into Pest 4 (`--type-coverage`) |
| axe-core/playwright | accessibility | `npm i -D @axe-core/playwright` |
| k6 *or* Artillery | API load testing | standalone (k6 binary / `npm i -D artillery`) |
| OpenAPI + validator | API contract | `vyuldashev/laravel-openapi` or hand-written spec + `spectator`/`league/openapi-psr7-validator` |
| Playwright projects | multi-browser + mobile | config-only (`projects: [chromium, firefox, webkit, 'Mobile Chrome']`) |
| Model factories | composable test data | `php artisan make:factory` per model |
| `composer audit` / `npm audit` | dependency CVEs | CI step |

---

## 5. Module-by-module test matrix ("every single detail")

For each module, the QA repo must cover: **happy path · negative/validation · boundary · every role's permission (view/create/edit/delete + module actions) · property-scoping · API + portal + admin parity · notifications fired · money/state invariants · i18n (en/ar)**. Modules (mirrors `docs/modules/`):

`01 Properties/Units · 02 Tenants · 03 Tenant-portal users · 04 Leases (create/renew/terminate/escalate/multi-unit) · 05 Billing/Invoices (monthly run, VAT, proration, frequencies) · 06 Payments/allocation/late-fees/Paymob · 07 Credit notes (issue/apply/void) · 08 CAM (pools/allocations/true-up) · 09 Tenant sales / percentage rent (submit/lock/dispute/void) · 10 Utility meters · 11 Maintenance → Tenant Requests (per Plan 1) · 12 Vendors/contracts · 13 Marketing (levy/budget/spend) · 14 Departments · 15 Owner requests + owner-as-RBAC · 16 ETA e-invoicing · 17 Reports (close/AR-aging/statements) · 18 RBAC/scoping · 19 Notifications/scans · 20 Mobile API (every /me/* endpoint)`. 

Cross-cutting matrices: **the auth-surface matrix** (admin/portal/api × roles), the **money invariant matrix** (reconcile after every financial op), the **notification matrix** (every event → right recipients × channels), the **scoping matrix** (every restricted role sees only its properties, incl. All-Properties mode).

---

## 6. Release sign-off gate (the go-live checklist)

Production-ready when **all** are true:
- ✅ Coverage gate green (`app/` ≥95%, critical domains 100%) on CI.
- ✅ Mutation score ≥80% on critical domains.
- ✅ PHPStan + Pint clean; type-coverage target met.
- ✅ Full E2E green (multi-browser); mobile-API journey suite green.
- ✅ Specialised passes done: security (incl. the audits + one external pentest), load (within SLA at throttle ceiling), accessibility (no WCAG-AA blockers), RTL, contract (responses match OpenAPI).
- ✅ `billing:reconcile` ties out on generated data; data-integrity scan clean.
- ✅ Migration fresh + rollback + re-up clean.
- ✅ **Manual QA matrix 100% executed + passed**; UAT signed off by the business (operator + a tenant + an owner persona).
- ✅ The `docs/PRODUCTION-RUNBOOK.md` pre-flight (`integrations:check`, worker/scheduler/backups) verified.
- ✅ Zero open high/critical bugs; every fixed bug has a regression test.

---

## 7. Phased execution (actionable order)

**Phase 1 — Tooling & gates (foundation).** Enable CI on push/PR; add coverage gate, PHPStan, Pint-in-CI, Infection, model factories, baseline coverage report. *Now coverage can't regress and quality is measured.*

**Phase 2 — Close automated gaps to target.** Workstream A (services/commands/endpoints/models/per-resource authz) + raise mutation score. *Hit the §2 numbers.*

**Phase 3 — Specialised QA.** Workstream D+E (E2E expansion, mobile-API journeys, security, performance/load, accessibility, RTL, contract/OpenAPI, migration safety, concurrency on MySQL).

**Phase 4 — Manual QA & UAT.** Workstream F: build the test-case repo (`docs/qa/test-cases/`), execute the full matrix, run UAT with the business, close the bug loop. *This is the human confidence layer.*

**Phase 5 — Sign-off.** Run the §6 gate; fix anything outstanding; ship.

---

## 8. Risks & realistic expectations

- **100% line coverage ≠ correctness** — that's why mutation testing + manual QA are in the plan; don't chase the last few % of trivial getters at the cost of the QA matrix.
- **The Filament-UI exclusions** in `phpunit.xml` mean some UI is covered only by E2E (merged) — keep E2E healthy or those lines go dark.
- **E2E flakiness** — invest in stable selectors + the existing global-setup; quarantine + fix flaky specs, don't `--retries` them away.
- **Load/concurrency need MySQL** (sqlite can't lock) — run those suites against the MySQL profile, not the default in-memory one.
- **This is a multi-week program**, not a sprint — Phases 1–2 give the biggest confidence jump (gate + quality + gaps); 3–4 are the "leave nothing behind" depth.
