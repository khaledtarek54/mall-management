---
name: qa-sweep
description: Run a multi-agent QA sweep over the project — scenario coverage, adversarial bug-hunt, field-validation audit, concurrency, security pentest, and E2E. Use to harden the system or after a batch of changes. Requires the user to have opted into multi-agent workflows.
argument-hint: "[full|scenario|adversarial|field-audit|concurrency|security|e2e] (default full)"
---

# Atriom QA sweep

A layered QA pass driven by the **Workflow** tool. Each mode below is one fan-out. `full` runs them in sequence (read each result before the next). **Only run if the user has opted into workflows** (ultracode on, or they asked). Always keep `vendor/bin/pest --parallel` green; add a `tests/Feature/Regression/` guard for every bug fixed.

These modes encode what's already proven valuable on this codebase (see the `project_qa_scenario_suite` memory). Pick by `$ARGUMENTS`.

## scenario
Extend `tests/Feature/Scenarios/` — one agent per module, each adding cases by class: happy / negative / boundary / RBAC / state-transition / scoping. **Don't duplicate** existing scenario files; extend them. Render Filament tables **with rows**; name column closure params `$state`. Seed `RolesPermissionsSeeder` for any `canViewAny/canCreate/...` assertion.

## adversarial  (the highest-yield mode)
Read-the-code-to-break-it. Pattern: parallel finders over integration **seams** (credit+payment on one invoice, re-running a generator, session/stamp reuse, parallel scans, terminal-record mutation) → **2-skeptic adversarial verify** per finding (each prompted to *refute*; keep only if it survives) → fix each survivor + a regression guard. Scenario tests assert *expected* behavior and miss seams — this catches what they don't.

## field-audit
One auditor per Filament resource: compare every form field to its **column constraint** (NOT-NULL/unique/length/type) + business rule, flag fault-tolerance gaps (optional field over NOT-NULL column, unbounded money, missing unique/maxLength, illogical dates, **cross-property select leaks**), then apply fixes + write `tests/Feature/Regression/Validation/` guards. Skip false-positives: a `Select`'s options already validate; self-healing projections (`units.status`) stay editable. (This is the 2026-06-27 hardening flow.)

## concurrency
**Must use real MySQL** — SQLite `:memory:` can't test `lockForUpdate`. Spawn N parallel processes hitting the same idempotent path (late-fee apply, SLA/overdue scans, payment receipt) and assert exactly-once. The fix pattern is always `lockForUpdate()->find()` + re-check the `*_notified_at`/status stamp **inside** the transaction.

## security
Adversarial pentest workflow: candidates across auth/scoping/IDOR/webhook-HMAC/secrets → verify → fix. Conventions: cross-tenant API returns **404 not 403** (no enumeration); portal writes only for admin `TenantUser`s; Paymob callback HMAC-verified.

## e2e
`npx playwright test --project=chromium` against Herd `mall-management.test`. Auth states in `storage/playwright-state/*.json` via global-setup; helpers use the demo logins (`admin@mall.test` / `tenant1@atriomwalk.test` / `owner@atriom.test`). Watch for missing i18n keys rendering raw, and stale specs.

## After any mode
Run the full suite, commit fixes to `main` (with regression guards + `Co-Authored-By:`), and update the `project_qa_scenario_suite` memory + the affected `docs/modules/*` gotchas section.
