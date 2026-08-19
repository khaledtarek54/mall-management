# Atriom

**Egyptian mall operations, end to end.**

Atriom is a specialized operations platform for the Egyptian retail vertical — leases, monthly billing, tenant sales declarations, CAM reconciliation, and ETA e-invoicing — across three role-aware portals on one source of truth.

| Panel | Path | Audience |
|---|---|---|
| Admin Console | `/admin/{property}` | Mall operators — per-property tenancy with an "All Properties" portfolio view for users assigned to multiple malls |
| ~~Owner Portal~~ (retired) | `/owner` | Off by default — **Jawad owners are now RBAC users in the Admin Console**, scoped to their owned properties |
| Tenant Portal | `/portal` | Mall tenants (the retailers / F&B / service shops) |

---

## Quick start

```bash
git clone <repo>
cd mall-management
composer install
npm install
cp .env.example .env
php artisan key:generate

# Set DB credentials in .env, then:
php artisan migrate:fresh --seed
npm run dev      # one terminal
php artisan serve  # another terminal (or use Herd / Valet)
```

Visit the landing page at `http://localhost:8000/` (or `http://mall-management.test/` if using Herd).

### Demo accounts (all use password `password`)

| URL | Email | Role |
|---|---|---|
| `/admin` | `admin@mall.test` | super_admin |
| `/admin` | `manager@mall.test` | manager |
| `/admin` | `viewer@mall.test` | viewer |
| `/admin` | `leasing@mall.test` | leasing |
| `/admin` | `operations@mall.test` | operations |
| `/admin` | `accounting@mall.test` | accounting |
| `/admin` | `marketing@mall.test` | marketing |
| `/admin` | `hr@mall.test` | hr |
| `/admin` | `owner@atriom.test` | owner — RBAC user scoped to Atriom Walk |
| `/portal` | `tenant1@atriomwalk.test` | Tenant **admin** — can submit requests + payments |
| `/portal` | `staff1@atriomwalk.test` | Same tenant, **read-only** user (can't submit) |
| `/portal` | `tenant2@atriomwalk.test` | Tenant admin |
| `/portal` | `tenant3@atriomwalk.test` | Tenant admin |

Hitting `/admin` bare redirects to `/admin/{first-property}/...`. Users with more than one assigned property see an **All Properties** option in the top-nav switcher that bypasses scoping for a portfolio-wide view.

---

## What's inside

- **Lease lifecycle** — Quick lease wizard, renewals with charge inheritance, terminations that won't orphan paid amounts
- **Monthly billing engine** — One-click run, EG VAT rules (rent exempt, service 14%), idempotent per period
- **Tenant Sales Declarations** — Mall-specific moat: tenants declare sales, admin locks, percentage rent auto-bills
- **CAM Reconciliation** — Annual common-area-expense pools with pro-rata allocations and per-allocation billing
- **ETA e-invoicing** — Document JSON builder, signing, status persistence. Module-toggleable from `/admin/settings → Modules`; mock mode by default; flip `ETA_MOCK=false` when preprod credentials land
- **Per-property tenancy (Filament panel)** — URL-scoped to `/admin/{property-code}/...`, property switcher in top nav, "All Properties" portfolio view for users with multi-mall access, per-tenant tables / widgets / forms throughout
- **RBAC** — 6 roles (super_admin / manager / leasing / operations / viewer / owner) × 81 permissions × per-user property assignment via the `asset_user` pivot. New users default to every property selected
- **Module feature flags** — credit_notes, maintenance, tenant_sales, cam, utility_meters, vendors, notes, reports, activity_log, eta — each toggleable from Settings; disabled modules hide from sidebar + dashboard + block route access
- **Maintenance** — Tenant submissions, admin triage with SLA tracking, polymorphic comments, photo attachments
- **Energy & Utilities** — Meter management with monthly readings + 12-month consumption chart
- **Tenant Communications log** — Polymorphic notes (calls, WhatsApp, meetings, site visits, emails) for collections workflows
- **Activity log** — Spatie ActivityLog on every governance-relevant entity, surfaced with field-by-field diffs (humanised labels, strikethrough old → highlight new, XSS-safe). Six preset date windows + custom range filter
- **Onboarding** — SetupGuide dashboard widget walks new operators through Properties → Units → Tenants → Leases → Invoices; empty states with "Create your first…" CTAs on every list page
- **Document attachments** — Spatie MediaLibrary for contracts, IDs, maintenance photos
- **Arabic-native** — Full RTL, mPDF Arabic shaping + bidi for invoice/statement PDFs, locale-aware month names

---

## Documentation

**[`docs/README.md`](docs/README.md) is the index.** The tree answers four questions and nothing
else — how the system works, how each module works, what is missing, and how to run it.

| Read | For |
|---|---|
| [docs/OVERVIEW.md](docs/OVERVIEW.md) | **Start here** — the domain and how the parts fit together |
| [docs/PROJECT-MAP.md](docs/PROJECT-MAP.md) | Where everything lives: the generated census, every route family, the scheduled automation |
| [docs/modules/](docs/modules/README.md) | **Per-module reference** — business rules, lifecycle, fields, **extension points**, gotchas. The source of truth before changing any module's logic |
| [docs/gap-analysis/](docs/gap-analysis/README.md) | **One** gap analysis: Atriom vs Yardi Voyager, the FM specialists and Odoo — open gaps, declined items, and what changed at the last re-verification |
| [docs/BUSINESS-RULES.md](docs/BUSINESS-RULES.md) | Every financial rule in plain language, for operator + accountant sign-off |
| [docs/operations/](docs/operations/) | Go-live gate · staging cutover · production runbook · infrastructure |
| [docs/qa/](docs/qa/README.md) | The pre-staging harness, the release checklist, UAT |
| [docs/integrations/](docs/integrations/) | Paymob (reference · operator setup · Flutter) · ETA certification · pay link · push |
| [docs/api/](docs/api/MOBILE-API.md) | The mobile API contract + generated `openapi.json` |
| [docs/accounting/](docs/accounting/README.md) | For the accountant, bilingual: the walkthrough, the posting map, the Egyptian tax catalogue |

The **visual handbook** is in the panel at `/admin/handbook` (bilingual, built by `npm run build`).

---

## Stack

- Laravel 13.8 · PHP 8.4 · Filament 4 (with built-in panel tenancy keyed on `Asset.code`)
- MySQL · Spatie Permission + ActivityLog + MediaLibrary + Settings
- mPDF (for Arabic-shaped PDF rendering)
- **Pest 4 + ParaTest** for the test suite — 184 cases across tenancy, models, services, widgets, RBAC, activity log, auth, and the deep-link query-string format. ~3.5 s parallel runtime.
- Playwright (chromium) for E2E — 18 spec files covering auth, every panel, CRUD, locale, PDFs, multi-property, tenant sales, CAM, ETA, owner portal, energy

```bash
vendor/bin/pest --parallel             # full Pest suite (~3.5s)
npx playwright test                    # E2E (~2 min)
php artisan migrate:fresh --seed       # rebuild demo state
```

---

## License

Proprietary. Atriom is a commercial product. Demo and pilot deployments are governed by individual customer agreements.
