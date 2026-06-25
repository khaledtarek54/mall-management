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
| `/admin` | `maintenance@mall.test` | operations |
| `/admin` | `accounting@mall.test` | accounting |
| `/admin` | `marketing@mall.test` | marketing |
| `/admin` | `hr@mall.test` | hr |
| `/admin` | `owner@atriom.test` | owner — RBAC user scoped to Atriom Walk |
| `/portal` | `tenant1@haya.test` | Café Crema (tenant) |
| `/portal` | `tenant2@haya.test` | Optix Eyewear (tenant) |
| `/portal` | `tenant3@haya.test` | The Burger Joint (tenant) |

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

**Product & requirements**

| Doc | When to read it |
|---|---|
| [docs/PROGRESS.md](docs/PROGRESS.md) | **Start here** — single sign-off tracker: build / test / validate status per business feature, what's left, next part |
| [docs/FUNCTIONAL-REQUIREMENTS.md](docs/FUNCTIONAL-REQUIREMENTS.md) | ERP/department requirements + **live build status** (§3) — departments, requests, marketing, owner requests |
| [docs/VALIDATION-GUIDE.md](docs/VALIDATION-GUIDE.md) | Hands-on per-feature checklist to validate shipped features against the business model |
| [FEATURES.md](FEATURES.md) | Full feature inventory + roadmap + demo playbook |

**Technical**

| Doc | When to read it |
|---|---|
| [TECH-DEEPDIVE.md](TECH-DEEPDIVE.md) | Stack rationale, security, multi-tenancy design, ETA architecture, testing, scaling, deployment |
| [FULL-DOCUMENTATION.md](FULL-DOCUMENTATION.md) | Comprehensive technical reference — models, APIs, console commands, deployment |
| [INFRA.md](INFRA.md) | Infrastructure / hosting notes |
| [PAYMOB-SETUP.md](PAYMOB-SETUP.md) | Paymob payment-gateway setup |
| [PAYMOB-FLUTTER.md](PAYMOB-FLUTTER.md) | Paymob + Flutter integration guide |
| [MOBILE-APP-BRIEF.md](MOBILE-APP-BRIEF.md) | Business briefing for the mobile developer (Q2 tenant app) |

**Strategy & sales**

| Doc | When to read it |
|---|---|
| [MASTER-PLAN.md](MASTER-PLAN.md) | Internal strategy + competitive context (Eltizam pursuit) |
| [PITCH-DECK.md](PITCH-DECK.md) | 12-slide partnership pitch for prospects |
| [PILOT-PROPOSAL.md](PILOT-PROPOSAL.md) | One-page pilot commercial proposal |
| [DEMO.md](DEMO.md) | 10-minute base live-demo script |
| [DEMO-ELTIZAM.md](DEMO-ELTIZAM.md) | 25-min Eltizam-tuned live-demo script |

**Deeper docs under [`docs/`](docs/)**

- [`docs/api/`](docs/api/) — mobile API reference + v1 architecture
- [`docs/gap-analysis/`](docs/gap-analysis/) — per-module audit reports (20 modules + plan / progress / production checklist)
- [`docs/PROPEZY-DEEP-DIVE.md`](docs/PROPEZY-DEEP-DIVE.md) · [`docs/GAP-ANALYSIS-PROPEZY-DASHBOARD.md`](docs/GAP-ANALYSIS-PROPEZY-DASHBOARD.md) — competitor (PropEzy) research

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
