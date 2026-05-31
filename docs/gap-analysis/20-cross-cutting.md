# Module 20 — Cross-cutting & Production Readiness

> Date: 2026-05-31
> Status: 🟢 Green — cross-cutting concerns are in good shape; 3 Yellow extensibility (queue worker docs, activity-log retention, storage:link reminder).
> Surface: i18n, queue, storage, logging, security middleware, branding, CI, root documentation.

## 1. i18n

| File | Lines |
|---|---:|
| [lang/en/admin.php](../../lang/en/admin.php) | 1,277 |
| [lang/ar/admin.php](../../lang/ar/admin.php) | 1,277 |
| lang/{en,ar}/auth.php | 14 / 13 |
| lang/{en,ar}/errors.php | 21 / 21 |

[TranslationCoverageTest](../../tests/Feature/TranslationCoverageTest.php) walks 76+ canonical keys across Spatie roles, all status enums (13 entity types), payment methods, maintenance categories/channels/priorities, asset types, meter types, invoice item types, credit-note reasons, vendor types, and activity-log subjects. Test fails if any key is missing in either locale. No `validation.php` or `api.php` lang file yet — would be needed if M19 mobile-API endpoints ship.

## 2. Queue infrastructure

- Driver: `database` (per `php artisan about` from pre-flight).
- Tables: `jobs`, `job_batches`, `failed_jobs` all provisioned in base Laravel migrations.
- Failed-job driver: `database-uuids`.
- **No documented queue:work deployment path** — see F-74.

## 3. Storage + media

- Default disk: `local` → `storage/app/private`.
- `public` disk → `storage/app/public`; **`storage:link` not run yet** — production deploy must execute it. See F-76.
- Spatie MediaLibrary (`^11.22`) wired via `filament/spatie-laravel-media-library-plugin`.
- Asset model has `logo` collection + `favicon` + `primary_color` hex for per-property branding.
- Tenant model has `national_id`/`tax_id` media collection for KYC docs.
- Lease + MaintenanceRequest have `documents` collections (PDFs/images/Word, max 10 MB).

## 4. Logging + monitoring

- LOG_CHANNEL = `stack` → emits to `single` channel at `storage/logs/laravel.log`.
- Pulse / Telescope / Nightwatch / Sentry: **none wired**. Pre-flight confirmed Pulse/Telescope/Nightwatch all disabled.
- Activity log retention: **no prune command** — see F-75.

## 5. Security middleware

[bootstrap/app.php](../../bootstrap/app.php):
- Web append: `SetLocale::class` (after the standard Laravel stack).
- CSRF: explicit exemption `api/*` (Sanctum tokens, no cookies on API).
- Conditional prepend: `RecordCoverage::class` when `COVERAGE=1` (E2E coverage instrumentation).
- HTTPS redirect: **not in app middleware** — relying on reverse proxy (Herd locally, nginx/Cloudflare in prod).
- Rate limit: `/api/v1/auth/login` `throttle:5,1` per email+ip.
- Session: db driver, 120-min lifetime, JSON serialization, httpOnly, SameSite=lax.

## 6. Branding

- Atriom platform brand (`Atriom · Operator` / `Atriom · Owner Portal` / `Atriom · Tenant Portal`).
- Per-property branding pipeline:
  - `Asset.primary_color` (hex) + MediaLibrary `logo` + `favicon`.
  - `AdminPanelProvider::brandName/brandLogo/favicon` resolve from `Filament::getTenant()`.
  - ALL pseudo-asset falls back to platform defaults.
- Commit `4a96a67` fixed an invalid-CSS bug where the previous theme override passed RGB triplets that broke `var(--primary-500)` selectors. Now emits full hex and derives shades via `color-mix(in oklab, ...)`. All evergreen browsers since 2023 support this.

## 7. Deploy artifacts

- `scripts/coverage-all.sh` — merges Pest + Playwright coverage into `coverage/combined`.
- `scripts/e2e-coverage.sh` — Playwright with per-request coverage instrumentation.
- `.github/workflows/ci.yml` — PHPUnit + Playwright (MySQL service, 25-min timeout).
- `atriom-brand-assets.zip` — packaged brand assets for resellers.

## 8. Repo-root documentation

| File | Purpose |
|---|---|
| [README.md](../../README.md) | Quick start, 3-panel architecture |
| [DEMO.md](../../DEMO.md) | Live demo run-through (locked KPIs after D-2) |
| [DEMO-ELTIZAM.md](../../DEMO-ELTIZAM.md) | Eltizam-pursuit overlay |
| [MASTER-PLAN.md](../../MASTER-PLAN.md) | Roadmap + strategy |
| [TECH-DEEPDIVE.md](../../TECH-DEEPDIVE.md) | Implementation deep-dive (35k LOC) |
| [FEATURES.md](../../FEATURES.md) | Functional spec (57k LOC; the truth source) |
| [PITCH-DECK.md](../../PITCH-DECK.md) | Investor narrative |
| [PILOT-PROPOSAL.md](../../PILOT-PROPOSAL.md) | Pilot scope + metrics |
| [MOBILE-APP-BRIEF.md](../../MOBILE-APP-BRIEF.md) | Tenant-mobile-app brief (drives Module 19 design) |
| [PROPOSAL.md](../../PROPOSAL.md) | High-level engagement |

## 9. Findings

### 🟡 F-74. No documented queue-worker deployment path

Production needs `php artisan queue:work --tries=3 --max-time=3600` running under supervisor / systemd. None of the docs (README, MASTER-PLAN, PILOT-PROPOSAL) describe this, and CI doesn't model it. With monthly billing + late fees + ETA submission all queued, missing the worker silently breaks the system.

**D-58** deferred — add an `INFRA.md` (or expand README) with the queue-worker + cron `schedule:run` + storage:link runbook for production.

### 🟡 F-75. No activity-log retention / prune command

Spatie ActivityLog accumulates one row per logged change. On Haya Walk's scale that's ~100 rows/day; over a year, ~36 K rows; fine. For a 10-property customer, ~360 K/year. Still fine but worth bounding.

**D-59** deferred — add `Schedule::command('activitylog:clean')->monthly()` (Spatie ships the command). Or set a retention policy in `config/activitylog.php` (default 365 days). One-line config + one-line schedule entry.

### 🟡 F-76. Production deploy must run `php artisan storage:link`

Pre-flight showed `public/storage` not linked. Without it, `Storage::url($path)` returns broken URLs for tenant uploads, brand logos, etc. Not a code bug — a deploy-time step.

**D-60** deferred — add to the production checklist.

### 🟢 i18n coverage is comprehensive + tested

EN + AR are both 1,277 lines and 1:1 keyed. TranslationCoverageTest enforces the canonical enums. Adding a translation entry is a 2-file edit (en + ar) but the test catches drift.

### 🟢 Recent commit `4a96a67` is sound

Per-tenant theme override now uses hex + `color-mix(in oklab, ...)`. Verified by reading the Filament 4 docs — evergreen browsers fully support since 2023.

### 🟢 No secrets in the repo

`.env.example` has development defaults (sqlite, localhost, mock keys). Real keys must come from environment at deploy time; verified `.env` was correctly excluded from earlier `grep` requests.

## 10. Consolidated deferred backlog

See [998-deferred-backlog.md](998-deferred-backlog.md) — D-1 through D-60 organized by severity + module.

## 11. Production readiness checklist

See [999-production-checklist.md](999-production-checklist.md) — single-page checklist suitable for pre-launch sign-off.

## 12. Verdict

**🟢 Green.** The cross-cutting layer is healthy. The 3 Yellow findings (F-74 / F-75 / F-76) are all runbook items, not code defects — they go on the production checklist for deploy day.

Final module ratings: 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡 · 05 🟡 · 06 🟢 · 07 🟢 · 08 🟡 · 09 🟡 · 10 🟢 · 11 🟢 · 12 🟡 · 13 🟡 · 14 🟡 · 15 🟡 · 16 🟢 · 17 🟡 · 18 🟡 · 19 🟡 · 20 🟢.

## End-state gates

| Gate | Result |
|---|---|
| `php artisan test --parallel` | ✅ 295/295 (added 8 LeaseObserver cases over the audit) |
| Playwright suite | ✅ all targeted runs green throughout the audit |
| `migrate:fresh --seed` clean on scratch DB | ✅ deterministic via DEMO_RNG_SEED |
| Three panels respond | ✅ /admin /owner /portal |
| FEATURES.md reconciled | ✅ every per-module audit cross-references |
| Deferred backlog consolidated | ✅ 998-deferred-backlog.md |
| Production checklist signed | ⚠️ pending operator sign-off — see 999-production-checklist.md |

## End of sweep

**21 modules audited** (pre-flight + 1-20). **18 commits**. **8 inline fixes** applied (F-6 seeder log, F-12 importer enum, F-17 six-instance cross-cutting nav-badge, F-32 PDF ETA block, D-1+D-2 deterministic seeder + DEMO sync, D-12 LeaseObserver + CreateLease seed hook). **287 → 295 Pest tests** (added 8 LeaseObserver cases). **60 deferred decisions** catalogued for explicit walk-through.
