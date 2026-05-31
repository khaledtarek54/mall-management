# Atriom Demo Run-through — Haya Walk (Jawad Developments)

## Pre-flight (5 minutes before)

1. Open `http://mall-management.test/admin` in a fresh browser window — clear cookies if you logged in earlier so the login screen shows.
2. Switch language toggle to **EN** for the team review tonight; switch to **عربي** mid-demo to show RTL support.
3. Zoom level 100 % (Cmd + 0 in Brave).
4. Close unused tabs / silence notifications.

## Logins

| Role | Email | Password |
|------|-------|----------|
| Super Admin | `admin@mall.test` | `password` |
| Manager | `manager@mall.test` | `password` |
| Viewer | `viewer@mall.test` | `password` |
| Leasing Manager | `leasing@mall.test` | `password` |
| Maintenance Manager | `maintenance@mall.test` | `password` |

Use **Super Admin** for the demo — full access to create/edit. Swap to the role-specific logins when demoing role-tailored dashboards.

---

## Live narration script

### 1 · Login screen (15 s)

> "This is the operations portal for Haya Walk. Branded login, bilingual right out of the gate."

Click the **عربي** toggle to flip RTL momentarily, then back to EN.

### 2 · Dashboard (2 min) — the headline

Login lands on **All Properties** (the portfolio view).

**Step 0 — switch to Haya Walk:** click the property switcher in the top bar and pick **Haya Walk**. The KPIs below are pinned to Haya Walk's seed data; the All-Properties view also includes the Plaza Annex multi-tenancy demo asset and would show different (lower) occupancy.

**KPI strip (top row):**

- "Four headline KPIs. Each one has a real sparkline drawn from the last 6 months of actual data — not placeholder shapes."
- "Occupancy: 66 % — 33 of 50 units leased. The line shows how it trended."
- "Monthly Recurring Revenue: EGP 1.63M — the recurring rent + service charge contracted across all active leases."
- "Collected This Month: EGP ~170K — note the month-over-month delta. Coloring goes green / amber / red based on collection rate vs expected."
- "Outstanding AR: EGP ~657K, 11 invoices overdue — that's the warning icon's job. (~EGP 588K of that is past due; the remainder is current AR still within payment terms.)"

> **Demo numbers are pinned** by the seeder's RNG seed (`HayaWalkSeeder::DEMO_RNG_SEED`). Re-seeding produces the same values every time; if these drift, re-check that seed.

**Revenue Trend chart:**

- "12 months of billed vs collected, side-by-side bars. The terracotta line is the **collection rate** on a secondary axis — 100 % means everything billed was collected. Hover anywhere…" (hover bars) "…tooltips give exact EGP."

**AR Aging:**

- "Receivables bucketed by days past due — green is current, gold is 1-30, orange 31-60, red after that. We can drill into any bucket later." (hover a bar) "Tooltip shows the EGP value **and** the invoice count."

**Tenant Mix:**

- "Active leases by category — retail vs F&B vs wellness etc. Lets the client see at a glance whether the mall is balanced."

**Below the fold:**

- Leases Expiring next 90 days, Top Tenants by rent, Recent Payments feed.

### 3 · Switch to Arabic (15 s)

Click **عربي** in the top bar.

> "Same dashboard, same data, full RTL. Currency stays EGP, dates are localized."

Switch back to EN before continuing.

### 4 · Properties / Units (1 min)

Sidebar → **Properties**. Open Haya Walk.

> "Asset → units → leases. One property, 50 leasable units, 33 occupied (66 %). Click any unit to see the active lease and tenant. The 'Plaza Annex' row is a stub asset that demos our multi-property tenancy — it has 8 units but no active leases, so it stays empty for this story."

### 5 · Tenant Directory (1 min)

Sidebar → **Tenant Directory**.

> "33 tenants. Each has a portal login we can grant from the Edit screen. Search, filter, sort all work."

### 6 · Create a Lease — show the speed (1.5 min)

Sidebar → **Leases** → **New Lease**.

- "Tenant: existing or new in one form. Searchable picker."
- Pick a vacant unit (the list filters to vacant only).
- Set dates, base rent, service charge. "14 % VAT applied automatically."
- "Percentage rent section — for tenants who pay base + a % of sales above a threshold. Standard in shopping malls."
- Save. "Lease is created, unit flips to occupied automatically."

### 7 · Create an Invoice — the killer feature (2 min)

Sidebar → **Invoices** → **New Invoice**.

- Pick a lease from the dropdown (show the search + the `REF · Tenant · Unit` formatting).
- **Watch the items repeater auto-fill from the lease's monthly charges** — base rent, service charge, with VAT pre-computed.
- Edit an amount — show Subtotal / VAT / Total updating live.
- Add a line: "Late fee", amount 5000, 14 % — totals update.
- Save. "Number auto-generated, status set, items linked."

### 8 · Monthly Billing button (45 s)

On the **Invoices** list page, point out the **Run Monthly Billing** button.

> "Click this and we generate invoices for every active lease for the current period — one invoice per lease, items from each lease's charges. The system skips anyone already billed for this period."

### 9 · Activity Log (30 s)

Sidebar → **Reports → Activity Log**.

> "Every create / update / delete on leases, invoices, payments, tenants is tracked — who, when, what changed. Compliance trail out of the box."

### 10 · Users & roles (30 s)

Sidebar → **Settings → Users**.

> "Three roles: super admin, manager, viewer. The login they're using now is super admin — manager can create/edit but not delete users, viewer is read-only."

---

## Things to flag if asked

- **ETA e-invoicing** is live in mock mode — submit-to-ETA action on invoices returns a stubbed Valid response. Flip `eta.mock` off in `/admin/settings → ETA` when preprod creds land. **ETA Compliance widget** on the dashboard surfaces Valid/Submitted/Rejected/Pending counts at a glance, each tile clickable into a filtered invoice list.
- **Credit Notes & Refunds** — full AR lifecycle at `/admin/credit-notes` (issue → apply → void with idempotent service-layer math).
- **Vendor Management** — `/admin/vendors` with contacts + contracts; maintenance requests route to vendors via the External Vendor select.
- **Reports module** — `/admin/reports` with downloadable Monthly Close PDF + AR Aging drilldown.
- **Settings + Module Flags** — `/admin/settings → Modules` turns any optional module on/off live.
- **Custom Roles + Permissions** — `/admin/roles` lets admins create custom roles with any of 81 granular permissions.
- **Role-tailored dashboards** — log in as `leasing@mall.test` or `maintenance@mall.test` to demo per-role widget sets.
- **Portal** — there's a separate `/portal` panel where tenants log in to see their statements and pay invoices.
- **Mobile API** — `/api/v1/auth/login` ships Sanctum tenant auth today; resource endpoints are Q2.
- **WhatsApp + PDF** — invoice and statement actions support generating PDFs and sharing via WhatsApp.

## Numbers cheat-sheet for tonight

```
Property:        Haya Walk
Units:           50 (33 occupied · 17 vacant)
Tenants:         33
Active leases:   33
MRR:             EGP 1.63M
Invoices:        ~200 total · ~10 overdue
Credit notes:    4 (across draft / issued / applied / void)
Vendors:         8 (with primary contacts + contracts)
Maintenance:     5 seeded across statuses + 5 distinct channels
Permissions:     81 across 18 modules
Roles:           6 built-in + custom-role UI
Dashboard:       12 widgets, role-tailored per role
Tests:           184 Pest (479 assertions, ~3.5s parallel) · 18 Playwright spec files
```

يلا بسم الله 💪
