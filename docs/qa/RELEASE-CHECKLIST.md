# Release sign-off checklist (go-live gate)

> Production-ready when **every** box is ticked. Copy this into the release
> ticket/PR and fill it in. A failed item blocks the release until fixed +
> re-verified. Pairs with [docs/PRODUCTION-RUNBOOK.md](../operations/PRODUCTION-RUNBOOK.md).

**Release:** `__________`  **Date:** `__________`  **Signed off by:** `__________`

---

## 1. Automated gates (must be green)

- [ ] **Full test suite green** — `vendor/bin/pest --parallel` (1300+ tests, 0 failures).
- [ ] **Static analysis clean** — `composer analyse` (PHPStan, no new errors above baseline).
- [ ] **E2E green** — `npx playwright test --project=chromium` against a representative build.
- [ ] **Accessibility** — `npx playwright test 19-accessibility` → 0 critical WCAG violations.
- [ ] **API contract in sync** — `composer api-spec` produces no diff (or the diff is intentional + reviewed); `ApiSpecContractTest` green (every `/api/v1` route documented).
- [ ] **Books tie out** — `php artisan billing:reconcile` on production-like data → exit 0 ("Books tie out").
- [ ] **Migrations** — `php artisan migrate:fresh --seed` clean on **MySQL**; every new migration has a working `down()`; no destructive migration without a backup note.
- [ ] **No N+1 regressions** — `tests/Feature/Performance/QueryBudgetTest` green.
- [ ] **Security probes green** — `tests/Feature/Security/SecurityProbesTest` (auth matrix, throttles, ownership/mass-assignment) + cross-tenant 404 isolation.

## 2. Manual QA (this folder)

- [ ] **QA harness green** — `composer qa` (restores the MySQL baseline before each suite, drives the real services). A failure becomes a bug → fix → regression test → re-run.
- [ ] **Cross-surface check** — the same scenario verified on **admin + portal + mobile** (e.g. raise a request on mobile → triage in admin → tenant sees the update on portal).
- [ ] **i18n** — spot-check key screens in **Arabic** (RTL flips, no missing translation keys, Western digits preserved).
- [ ] **Device/browser** — manual smoke on the real target devices (Android-first per the mobile brief, iOS, common browsers).

## 3. UAT (business sign-off — [UAT-SCRIPTS.md](UAT-SCRIPTS.md))

- [ ] **Operator (Eltizam)** persona script signed off.
- [ ] **Owner (Jawad)** persona script signed off (admin RBAC, property-scoped).
- [ ] **Tenant (web portal)** persona script signed off.
- [ ] **Tenant (mobile)** persona script signed off.

## 4. Operational pre-flight ([PRODUCTION-RUNBOOK.md](../operations/PRODUCTION-RUNBOOK.md))

- [ ] **Integrations** — `php artisan integrations:check` green for the enabled integrations (Paymob, ETA, push/FCM, mail).
- [ ] **Secrets** — production `.env` set (Paymob live keys + HMAC, FCM, mail, `APP_KEY`); **no secrets in the repo or logs**; `APP_DEBUG=false`.
- [ ] **Scheduler running** — `schedule:run` cron active; the scheduled scans (`requests:scan-sla-breaches`, `requests:auto-close`, `billing:*`, `cam:reconcile`, `vendors:expire-contracts`) fire.
- [ ] **Queue worker** running + monitored.
- [ ] **Backups** — automated DB backups configured + a restore tested.
- [ ] **Monitoring/alerting** — error tracking (e.g. Sentry) + uptime in place.
- [ ] **HTTPS + security headers** present in prod (`SecurityHeaders` middleware).
- [ ] **Rollback plan** — documented; the release is revertible.

## 5. Sign-off

- [ ] **Zero open critical/high bugs.** Every fixed bug this release has a regression test.
- [ ] **Docs current** — module docs + FRD + this checklist updated in the same release.
- [ ] **Final approval** — engineering + business have signed the relevant sections above.
