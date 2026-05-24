# Atriom

**Egyptian mall operations, end to end.**

Atriom is a specialized operations platform for the Egyptian retail vertical — leases, monthly billing, tenant sales declarations, CAM reconciliation, and ETA e-invoicing — across three role-aware portals on one source of truth.

| Panel | Path | Audience |
|---|---|---|
| Admin Console | `/admin` | Mall operators (super_admin / manager / viewer) |
| Owner Portal | `/owner` | Property owners — read-only portfolio view |
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
| `/owner` | `owner@jawad.test` | owner (owns Haya Walk) |
| `/portal` | `tenant1@haya.test` | Café Crema (tenant) |
| `/portal` | `tenant2@haya.test` | Optix Eyewear (tenant) |
| `/portal` | `tenant3@haya.test` | The Burger Joint (tenant) |

---

## What's inside

- **Lease lifecycle** — Quick lease wizard, renewals with charge inheritance, terminations
- **Monthly billing engine** — One-click run, EG VAT rules (rent exempt, service 14%), idempotent per period
- **Tenant Sales Declarations** — Mall-specific moat: tenants declare sales, admin locks, percentage rent auto-bills
- **CAM Reconciliation** — Annual common-area-expense pools with pro-rata allocations and per-allocation billing
- **ETA e-invoicing** — Document JSON builder, signing, status persistence. Mock mode by default; flip `ETA_MOCK=false` when preprod credentials land
- **Multi-property tenancy** — Session-based operator switcher with per-operator brand swap (logo, name, favicon)
- **Maintenance** — Tenant submissions, admin triage with SLA tracking, polymorphic comments, photo attachments
- **Energy & Utilities** — Meter management with monthly readings + 12-month consumption chart
- **Tenant Communications log** — Polymorphic notes (calls, WhatsApp, meetings, site visits, emails) for collections workflows
- **Audit trail** — Spatie ActivityLog on every governance-relevant entity
- **Document attachments** — Spatie MediaLibrary for contracts, IDs, maintenance photos
- **Arabic-native** — Full RTL, mPDF Arabic shaping + bidi for invoice/statement PDFs, locale-aware month names

---

## Documentation

| Doc | When to read it |
|---|---|
| [FEATURES.md](FEATURES.md) | Full feature inventory + implementation notes |
| [TECH-DEEPDIVE.md](TECH-DEEPDIVE.md) | Stack rationale, security, multi-tenancy design, ETA architecture, testing, scaling, deployment |
| [MASTER-PLAN.md](MASTER-PLAN.md) | Internal strategy + competitive context (Eltizam pursuit) |
| [PITCH-DECK.md](PITCH-DECK.md) | 12-slide partnership pitch for prospects |
| [PILOT-PROPOSAL.md](PILOT-PROPOSAL.md) | One-page pilot commercial proposal |
| [DEMO-ELTIZAM.md](DEMO-ELTIZAM.md) | 25-min Eltizam-tuned live-demo script |
| [MOBILE-APP-BRIEF.md](MOBILE-APP-BRIEF.md) | Business briefing for mobile developer (Q2 tenant app) |

---

## Stack

- Laravel 13.8 · PHP 8.4 · Filament 4
- MySQL · Spatie Permission + ActivityLog + MediaLibrary
- mPDF (for Arabic-shaped PDF rendering)
- Playwright (chromium) for E2E — 68 specs covering auth, every panel, CRUD, locale, PDFs, multi-property, tenant sales, CAM, ETA, owner portal, energy

```bash
npx playwright test                   # run E2E suite (~2 min)
php artisan test                       # PHPUnit baseline
php artisan migrate:fresh --seed       # rebuild demo state
```

---

## License

Proprietary. Atriom is a commercial product. Demo and pilot deployments are governed by individual customer agreements.
