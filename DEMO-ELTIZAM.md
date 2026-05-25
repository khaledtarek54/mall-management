# Eltizam Demo Script — 30-minute working version

> **Overlay on [DEMO.md](DEMO.md), not a replacement.** Adapts the existing flow into a 30-minute Eltizam-tailored partnership pitch. Since the last refresh we've shipped a dashboard parity sprint (ETA Compliance widget · Leasing Pipeline · Sales Density · Role-tailored views), Credit Notes & Refunds (full AR lifecycle), Vendor Management (vendors + contacts + contracts wired into maintenance), RBAC overhaul (81 granular permissions + custom role creator UI), Property Staff Assignment, Dynamic Settings + Module Feature Flags (turn modules on/off live), Reports module (Monthly Close PDF + AR Aging drilldown).
> **Audience:** Eltizam decision-makers + technical evaluators.
> **Tone:** Confident, specific, partnership-framed. Never "ours is better than yours." Always "ours is specialized for what yours isn't."

---

## Pre-flight (10 minutes before)

1. Confirm DBngin MySQL running; run `php artisan migrate:fresh --seed` if data was edited mid-prep
2. Browser at clean state — no devtools, no tabs except `/admin` and `/portal`
3. Zoom 100% (Cmd+0)
4. Three private windows pre-logged-in:
   - **Window A** — admin (`admin@mall.test / password`)
   - **Window B** — owner (`owner@jawad.test / password`)
   - **Window C** — tenant portal on phone or second screen (`tenant1@haya.test / password`)
5. Language toggled to EN on window A
6. Locale reset: `/locale/en` then `/operator/switch/all` so first impression is clean
7. **Backup video recorded** (5 min narrated, saved locally) — for wifi-died-on-stage scenarios

## Logins (leave on screen for them to write down)

| Role | URL | Email | Password |
|---|---|---|---|
| Super Admin | `/admin` | `admin@mall.test` | `password` |
| Operations Manager | `/admin` | `manager@mall.test` | `password` |
| Viewer (stakeholder) | `/admin` | `viewer@mall.test` | `password` |
| **Leasing Manager** ⭐ | `/admin` | `leasing@mall.test` | `password` |
| **Maintenance Manager** ⭐ | `/admin` | `maintenance@mall.test` | `password` |
| Owner | `/owner` | `owner@jawad.test` | `password` |
| Tenant | `/portal` | `tenant1@haya.test` | `password` |

The two ⭐ logins exist specifically to demo **role-tailored dashboards** — each sees only their relevant widgets (step 9 below).

---

## Demo flow — 25 minutes

### 1 · Frame (1 min) — before opening the browser

Don't open the laptop yet. Set the framing first.

> "Before I show you anything, the framing: we're not pitching to replace PropEzy. PropEzy is impressive for community, workplace, residential. We're showing you a specialized layer for the Egyptian mall vertical that runs alongside it. With that lens, here's what we've built."

### 2 · Dashboard (2 min) — the headline numbers

Open admin window. Land on `/admin`.

> "This is Haya Walk — Jawad Developments' retail walk in 6th of October. 50 units, 33 active leases, real data, real money."

Point at the KPI strip:
- **Occupancy 66%** — "Real sparkline of the last 6 months. Not a placeholder."
- **MRR EGP 1.6M** — "Base rent + service charge across active leases."
- **Collected This Month** — "With month-over-month delta. Green / amber / red based on collection rate."
- **Outstanding AR** — "And the count of overdue invoices."

Scroll down — point at the **ETA Compliance** strip (4 tiles: Valid / Submitted / Rejected / Pending).

> "This is the Egyptian-CFO moment. At a glance you see what % of invoices the regulator has accepted, what's been rejected, what's still queued. Each tile deep-links into a pre-filtered invoice list. PropEzy doesn't surface this anywhere because they don't build for ETA compliance."

Scroll further — point at the **Leasing Pipeline** strip (Drafts / Pending Approval / Active / Renewed with EGP/mo value per stage).

> "Leasing-funnel view. Lease lifecycle by stage with the EGP value sitting at each. Drafts you haven't approved yet, signed leases pending start, active book value, renewals. Click any stage → filtered lease list."

> "Everything you're looking at is also rendered in Arabic, RTL, with the right month names and numerals. I'll flip locales near the end so you don't think this is bolted on."

### 3 · The first Eltizam moment — Multi-property branding (2 min)

Click the operator switcher in the topbar (between user menu and language pill). Currently "All Operators".

> "Multi-property tenancy. One codebase, multiple operators, each with their own brand."

Switch to "Jawad Developments" → topbar logo + name swap to Jawad. Switch to "**Eltizam Egypt**" → swaps to the real Eltizam Group logo (sourced from your public mark) with brand gold `#F0B010`.

> "This is your actual logo and brand color, live. Same login, same data, your white-label. Your retail clients see Eltizam branding throughout. The data underneath is per-operator-scoped — Eltizam Egypt has no assets seeded yet, so the Properties list goes empty. Switch back to Jawad and the Haya Walk data returns."

Switch back to "All Operators". Brand returns to default.

### 4 · Maintenance triage (1.5 min)

Sidebar → **Maintenance Requests**.

> "Standard mall ops. Tenants raise issues, admins triage. Five seeded requests across statuses: submitted, in-progress, awaiting tenant, resolved, closed. Each has a category — HVAC, plumbing, electrical, etc. — and a priority. SLA timer auto-calculated."

Click into one urgent request, show:
- The status timeline
- The comment thread (public + internal admin notes via `is_internal` flag)
- The photo attachment
- The acknowledge / assign / mark-resolved transitions

> "Real audit trail on every transition. Polymorphic comments — admin or tenant authors. Internal notes don't surface to the tenant portal."

### 5 · The differentiator slide — Tenant Sales Declaration (3 min) ⭐

This is the headline moment. Slow down.

Sidebar → **Tenant Sales** (the resource we built specifically because PropEzy doesn't have it).

> "This is what mall specialization actually means. In Egyptian retail leases — especially F&B — tenants pay two layers of rent: a fixed base, plus a percentage of monthly sales above a threshold. To bill that percentage, tenants declare their sales. We submit, the property team reviews, locks, and the percentage rent gets auto-billed."

Show the queue: 72 declarations across 3 months, mix of statuses.

Filter to **Submitted**. Pick one. Click **Lock**.

> "Watch what happens — this declaration is for [Café Crema, August], they declared EGP 320K of sales. The lease threshold is 150K at 6%. Lock the declaration → calculated percentage rent is `(320 - 150) × 6% = EGP 10,200`. That becomes a one-off Charge on the lease, marked as percentage rent. Next monthly billing run picks it up."

Show the notification: "Percentage rent of EGP 10,200 queued for next billing run."

> "PropEzy doesn't have this. Not a knock — it's residential-focused. The retail vertical needs this workflow and we built it for the way Egyptian malls actually structure leases."

### 6 · CAM Reconciliation (2 min)

Sidebar → **CAM Reconciliation**.

> "Common Area Maintenance — security, cleaning, HVAC for corridors, lobby lighting, landscaping. The mall incurs these costs across the year. At year-end, each lease pays their pro-rata share. International platforms don't model this; Egyptian property teams reconcile it in spreadsheets."

Show the 2025 reconciled pool (last year, all 33 allocations billed) and the 2026 draft pool (current year, no allocations yet).

Click into the 2026 draft. Click **Generate Allocations**.

> "Pro-rata distribution by leased square meters. EGP 612K of YTD actual expenses, EGP 580K already collected via monthly estimates, EGP 32K under-collected — that's the true-up. Each lease gets their share, with a visible 'true-up amount' column showing how much they owe (or are owed) at year-end."

Click **Bill** on one allocation.

> "Creates a one-off CAM Reconciliation charge on that lease. Next monthly invoice picks it up."

> "Annual auto-true-up automation is Q2 work. Today we ship the data model and the manual workflow — which is how every Egyptian mall does it now anyway."

### 7 · ETA e-invoicing (2 min) ⭐⭐ — the biggest moat

Sidebar → **Invoices**.

> "Egypt's e-invoicing mandate is real and mandatory for B2B. Every invoice has to be submitted to the Egyptian Tax Authority. The ETA column on the right shows submission status."

Point at the badges — green Valid, red Rejected, blank for unsubmitted.

> "55 invoices already submitted as Valid, 10 marked Rejected to show the failure path. Pick any unsubmitted invoice."

Find one without ETA badge. Click **Submit to ETA**.

Show the modal: "Mock mode — submission returns a stubbed Valid response."

> "Today we're in mock mode because ETA preprod credentials are mid-application. The flow is real — JSON document built to ETA's v1.0 spec, signing pipeline ready, response handling persists submission ID, long ID, and full response payload. Flip `ETA_MOCK=false` when credentials arrive, point at your taxpayer profile, we're live."

Confirm submission. Badge flips to Valid. Notification fires with the submission ID.

> "Open the response in the activity log — full audit trail of every ETA round-trip stored as JSON."

### 7.5 · Credit Notes & Refunds (1.5 min) — AR completeness

Sidebar → **Credit Notes**.

> "AR isn't complete without credit notes. A tenant disputes a service charge, you settle by issuing a credit note. Or stock returns, refunds, goodwill adjustments. Every real PMS has this; PropEzy doesn't advertise it."

Show the list: 4 seeded notes covering every state — draft, issued-with-balance, partially-applied, void.

Open the **issued** one. Header action: **Apply to Invoice**.

> "Pick any open invoice for this tenant. The action caps the application at the minimum of the credit note's remaining balance, the invoice's open balance, and what I request. Idempotent on void — once voided, applying again is a no-op."

Confirm. Show the notification: "EGP 2,000 applied to invoice INV-HW-XXXXX." The credit note's status flips to "applied" or stays "issued" if there's remaining balance. The invoice's balance drops.

> "Six PHPUnit tests lock this math — issue, apply, cap-at-minimum, void-when-applied throws, fully-applied status flip, no-op on voided notes. Service-layer, not UI-layer."

### 8 · Vendor Management (1 min) — Maintenance routing

Sidebar → **Vendors**.

> "First-class vendor entity — contractor / supplier / service-provider / consultant — with primary contacts and contracts. Eight seeded Egyptian-mall vendors: Cool-Air HVAC, BrightSpark Electrical, PureWater Plumbing, CleanFleet Janitorial, SecureGuard, GreenLeaf, PestStop, FireSafe."

Open Cool-Air → show the two relation managers (Contacts + Contracts).

> "Contracts have asset linkage, value, currency, scope, status (draft → active → expired → terminated). Nav badge counts contracts expiring within 30 days so admins see what needs renewal."

Open any Maintenance Request → show the **External Vendor** field on the form + the toggleable Channel column on the table.

> "Maintenance requests now have two assignment lanes: internal staff `assigned_to` and external `assigned_to_vendor_id`. Routing the urgent AC complaint to Cool-Air takes one dropdown click. The Channel column tracks how the request came in — portal, WhatsApp, phone, email, walk-in — so you know which channels need staffing."

### 9 · Role-tailored dashboards (1.5 min) — the PropEzy headline gap closed

Open a **second** window (or use private tab). Log in as `leasing@mall.test / password`.

> "PropEzy's headline differentiator: dedicated interfaces for CXOs, portfolio managers, property managers, leasing managers, maintenance managers. We had one admin dashboard for everyone — that was a real gap. Now closed."

Land on `/admin` as the leasing manager. The dashboard shows: Action Required · Mall Stats · Leasing Pipeline · Tenant Mix · Expiring Leases · Top Tenants (with **Sales Density** column).

> "Leasing-focused widget set. No AR Aging because that's finance, no Open Maintenance because that's ops, no Energy because that's facilities."

Now switch to `maintenance@mall.test`. Reload.

> "Same code, same dashboard route. Different person, different lens. Action Required, Mall Stats, Open Maintenance queue, Energy Consumption. Six built-in roles, custom roles can be created from the UI with any of 81 granular permissions."

Open `/admin/roles` (only super_admin can see it).

> "Custom role creator — name, then collapsible section per module with a checkbox list. System roles can't be renamed or deleted; custom ones can. Every resource gate, every action, every dashboard widget reads from this permissions table."

### 10 · Owner Portal (2 min) — the partnership signal

Switch to **Window B** (already logged in as `owner@jawad.test`).

> "Third panel. This is for property owners — Eltizam's portfolio managers, in the partnership scenario. Read-only, scoped to assets they own across operators."

Show the dashboard:
- Portfolio Stats: 1 Property, Occupancy %, MRR, Outstanding AR
- Sidebar: Properties / Invoices / Maintenance Activity

> "Brand is dynamic. If this owner only owns Jawad assets, they see Jawad branding. If they owned across multiple operators, we'd swap to neutral. The owner views the financials, the maintenance activity, the occupancy — without being able to edit anything."

Click Properties → click Haya Walk → show the Property Performance section with per-asset KPIs.

> "Each operator deploying us into the Eltizam portfolio would get their owner portal here. Customizable per-operator branding, same engine."

### 11 · Tenant portal — the WhatsApp moment (1.5 min)

Switch to **Window C** (phone or second screen — `/portal` logged in as Café Crema).

> "Same data, tenant side. Tenant sees their open balance, their invoices, the PDFs, their maintenance tickets."

Click an invoice → click **PDF** → it downloads. Open it.

> "Notice the Arabic version of the PDF — properly shaped, bidi-aware. We use mPDF for this; the default PHP PDF library (DomPDF) emits broken Arabic. We chose mPDF specifically because Arabic is non-negotiable in this market."

Show the PDF. Then click the native share — point at WhatsApp.

> "Egyptian tenants live on WhatsApp. They share the PDF with their accountant in two taps. Our roadmap also includes admin-side WhatsApp Business outbound reminders — gated behind `WHATSAPP_ENABLED` until you wire Meta or BSP credentials."

### 12 · Energy & Utilities (1 min)

Back to admin. Sidebar → **Energy & Utilities**.

> "Utility meters per asset and per unit. Electric, water, gas. Monthly readings, consumption, cost. 48 meters seeded, 576 readings — a year of data."

Scroll to the dashboard → **Energy Consumption** chart.

> "12-month bar chart across electric, water, gas. Today this is monitoring. The optimization workflows — anomaly detection, peak-demand alerts, IoT sensor integration — are Q3 work, intentionally. We didn't want to ship a fake IoT story."

### 13 · Settings + Module Feature Flags (1.5 min) — "everything is configurable"

Back as super_admin. Sidebar → **Settings**.

> "Every config value the operator should be able to touch — late-fee percentage, grace days, SLA hours per priority, ETA toggles, Paymob/WhatsApp flags, issuer info — lives in this DB-backed Settings panel, not in env files."

Five tabs: Modules / Billing / Maintenance / ETA / Integrations.

Click **Modules** tab. Show the 9 toggles.

> "Every optional module has a feature flag. Watch this."

Toggle **Vendors** off → click **Save settings**. Watch the sidebar — Vendors disappears.

> "Visit `/admin/vendors` directly?" Type it in. Page returns 403.

Toggle it back on → save → it reappears.

> "Audit-friendly: every setting change is a real DB write, with a full ActivityLog audit trail. Operators can also create custom roles that grant `settings.manage` to specific people."

### 14 · Arabic locale flip (45 sec)

Click the **عربي** pill in the topbar.

> "Full RTL flip. Not just text direction — the entire layout mirrors. Sidebar moves to the right, badges reorient, columns reverse. Month names in Arabic, currency right-aligned, dates DD/MM/YYYY which is the Egyptian convention."

Flip back to EN.

### 15 · Roadmap close (1 min)

Back to dashboard.

> "What's coming. Paymob payments — sandbox merchant signup in flight. ETA preprod credentials — application in flight. Mobile tenant app — Egyptian-mall-tenant specialist, Q2, briefed at [MOBILE-APP-BRIEF.md](MOBILE-APP-BRIEF.md); login auth already shipped against the new Sanctum API. Property-staff scoping enforcement (the asset_user pivot exists; query-scoping is the next batch). CAM auto-true-up scheduled command exists; the dashboard wizard is polish. Accounting close export."

> "The roadmap order is your call. Pilot starts at Haya Walk because the data's already realistic. Six months, defined commercial, white-label option ready. What you saw today is production code — 22 entities, 36 PHPUnit service tests locking the billing math, 170+ Playwright specs covering every page across all 3 panels, real audit trail on 13 models, real Arabic with mPDF shaping. Hand-over format is yours; we ship clean."

### 16 · Partnership ask (1 min)

Close laptop. Eye contact.

> "Three things. Number one — does this complement PropEzy in your stack the way we're framing it? Two — is Haya Walk the right pilot, or would another Tafawuq Egypt property be a better fit? Three — what's your priority on the unbuilt items? Paymob, mobile, vendor management, IoT — we ship in the order you steer."

> "Whatever you decide, we walk out of here with one of three things: a working session next week, a written objection list, or a clean no. All three are useful to us."

---

## Backup tactics

### If wifi dies mid-demo

1. Pause. Smile. "Live demo gods are testing us." Open the backup video.
2. The backup video (5 min, narrated) covers the same 12 steps in compressed form.
3. Continue verbally with the partnership-ask close.

### If a screen freezes

- Cmd+R the offending tab. Move on to the next module without commentary.
- If admin freezes, switch to Window B (owner) and demo that instead.

### If they want to drill into something not on the script

Let them lead. The platform is the source of truth — open whatever they ask. Common drill-downs:
- "Show me the activity log" → Sidebar → Activity Log
- "Show me a tenant statement" → Tenants → click any → Header action → Statement of Account → PDF downloads
- "Show me how a new tenant signs in" → Sidebar → Leases → Quick New Lease → walk the 2-step wizard
- "Show me the lease renewal flow" → Leases → row action → Renew → fill new dates → renewed badge appears, charges copied

### If they ask to see the code

- `code .` → walk them through:
  - `app/Models/` (10 entities)
  - `app/Filament/Admin/Resources/TenantSalesDeclarations/` (the mall moat)
  - `app/Services/Eta/EtaJsonBuilder.php` (ETA spec implementation)
  - `tests/e2e/` (68 specs)
- Don't dwell. Code review is for their technical evaluators in the follow-up session.

---

## Timing budget (target — stay flexible)

| Step | Target | Cumulative |
|---|---|---|
| 1. Frame | 1:00 | 1:00 |
| 2. Dashboard (+ ETA Compliance, Leasing Pipeline) | 2:30 | 3:30 |
| 3. Multi-property brand swap | 2:00 | 5:30 |
| 4. Maintenance triage | 1:30 | 7:00 |
| 5. Tenant Sales ⭐ | 3:00 | 10:00 |
| 6. CAM Reconciliation | 2:00 | 12:00 |
| 7. ETA e-invoicing ⭐⭐ | 2:00 | 14:00 |
| 7.5. Credit Notes & Refunds | 1:30 | 15:30 |
| 8. Vendor Management | 1:00 | 16:30 |
| 9. Role-tailored dashboards ⭐ | 1:30 | 18:00 |
| 10. Owner Portal | 2:00 | 20:00 |
| 11. Tenant Portal | 1:30 | 21:30 |
| 12. Energy | 1:00 | 22:30 |
| 13. Settings + Module Flags ⭐ | 1:30 | 24:00 |
| 14. Arabic flip | 0:45 | 24:45 |
| 15. Roadmap | 1:00 | 25:45 |
| 16. Partnership ask | 1:00 | 26:45 |
| Q&A buffer | ~3:15 | ~30:00 |

If you're running long: cut Energy (step 12) — the brand-swap in step 3 already implicitly shows locale support; Energy is the lowest-stakes module. You can also fold Vendor Management (step 8) into the Maintenance triage (step 4) by mentioning the assignment dropdown without leaving the page.

If you're running short: extend Tenant Sales (step 5) — workflow drill into the dispute flow, show audit log entries, show the auto-generated Charge on the lease. Or extend step 13 (Settings) — toggle one module live, walk to the affected resource, show it's gone, toggle back.

---

## After the meeting (within 24 hours)

Send:
- The deck ([PITCH-DECK.md](PITCH-DECK.md))
- The proposal ([PILOT-PROPOSAL.md](PILOT-PROPOSAL.md))
- A short demo recording link (5 min)
- Logins to the live demo environment (your hosted instance, not local)
- Calendar invite for the working session within 14 days

Don't send the codebase or [MASTER-PLAN.md](MASTER-PLAN.md) — those are internal.
