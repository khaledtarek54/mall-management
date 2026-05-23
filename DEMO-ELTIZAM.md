# Eltizam Demo Script — 25-minute working version

> **Overlay on [DEMO.md](DEMO.md), not a replacement.** This script adapts the existing 10-minute Jawad-direct flow into a 25-minute Eltizam-tailored partnership pitch with the new mall-specific modules (Tenant Sales, CAM, ETA, Owner Portal, Multi-property branding).
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
| Admin | `/admin` | `admin@mall.test` | `password` |
| Owner | `/owner` | `owner@jawad.test` | `password` |
| Tenant | `/portal` | `tenant1@haya.test` | `password` |

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

Brief scroll through other widgets — AR Aging, Tenant Mix, Monthly Revenue Trend.

> "Everything you're looking at is also rendered in Arabic, RTL, with the right month names and numerals. I'll flip locales near the end so you don't think this is bolted on."

### 3 · The first Eltizam moment — Multi-property branding (2 min)

Click the operator switcher in the topbar (between user menu and language pill). Currently "All Operators".

> "Multi-property tenancy. One codebase, multiple operators, each with their own brand."

Switch to "Jawad Developments" → topbar logo + name swap to Jawad. Switch to "Demo Operator" → swaps to a neutral brand.

> "If we sign as Eltizam Egypt, this swap is to your logo and colors. Same login, same data, your white-label. Your retail clients see Eltizam branding throughout. The data underneath is per-operator-scoped — Demo Operator has no assets, so the Properties list goes empty. Switch back to Jawad and the Haya Walk data returns."

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

### 8 · Owner Portal (2 min) — the partnership signal

Switch to **Window B** (already logged in as `owner@jawad.test`).

> "Third panel. This is for property owners — Eltizam's portfolio managers, in the partnership scenario. Read-only, scoped to assets they own across operators."

Show the dashboard:
- Portfolio Stats: 1 Property, Occupancy %, MRR, Outstanding AR
- Sidebar: Properties / Invoices / Maintenance Activity

> "Brand is dynamic. If this owner only owns Jawad assets, they see Jawad branding. If they owned across multiple operators, we'd swap to neutral. The owner views the financials, the maintenance activity, the occupancy — without being able to edit anything."

Click Properties → click Haya Walk → show the Property Performance section with per-asset KPIs.

> "Each operator deploying us into the Eltizam portfolio would get their owner portal here. Customizable per-operator branding, same engine."

### 9 · Tenant portal — the WhatsApp moment (1.5 min)

Switch to **Window C** (phone or second screen — `/portal` logged in as Café Crema).

> "Same data, tenant side. Tenant sees their open balance, their invoices, the PDFs, their maintenance tickets."

Click an invoice → click **PDF** → it downloads. Open it.

> "Notice the Arabic version of the PDF — properly shaped, bidi-aware. We use mPDF for this; the default PHP PDF library (DomPDF) emits broken Arabic. We chose mPDF specifically because Arabic is non-negotiable in this market."

Show the PDF. Then click the native share — point at WhatsApp.

> "Egyptian tenants live on WhatsApp. They share the PDF with their accountant in two taps. Our roadmap also includes admin-side WhatsApp Business outbound reminders — gated behind `WHATSAPP_ENABLED` until you wire Meta or BSP credentials."

### 10 · Energy & Utilities (1 min)

Back to admin. Sidebar → **Energy & Utilities**.

> "Utility meters per asset and per unit. Electric, water, gas. Monthly readings, consumption, cost. 48 meters seeded, 576 readings — a year of data."

Scroll to the dashboard → **Energy Consumption** chart.

> "12-month bar chart across electric, water, gas. Today this is monitoring. The optimization workflows — anomaly detection, peak-demand alerts, IoT sensor integration — are Q3 work, intentionally. We didn't want to ship a fake IoT story."

### 11 · Arabic locale flip (45 sec)

Click the **عربي** pill in the topbar.

> "Full RTL flip. Not just text direction — the entire layout mirrors. Sidebar moves to the right, badges reorient, columns reverse. Month names in Arabic, currency right-aligned, dates DD/MM/YYYY which is the Egyptian convention."

Flip back to EN.

### 12 · Roadmap close (1 min)

Back to dashboard.

> "What's coming. Paymob payments — sandbox merchant signup in flight. ETA preprod credentials — application in flight. Vendor management as first-class entities for routing maintenance tickets externally. Mobile app — Egyptian-mall-tenant specialist, Q2, briefed at [MOBILE-APP-BRIEF.md](MOBILE-APP-BRIEF.md). CAM auto-true-up. Accounting close export."

> "The roadmap order is your call. Pilot starts at Haya Walk because the data's already realistic. Six months, defined commercial, white-label option ready. Everything you see today is in production code — 68 Playwright specs, real audit trail, real Arabic. Hand-over format is yours; we ship clean."

### 13 · Partnership ask (1 min)

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
| 2. Dashboard | 2:00 | 3:00 |
| 3. Multi-property | 2:00 | 5:00 |
| 4. Maintenance | 1:30 | 6:30 |
| 5. Tenant Sales ⭐ | 3:00 | 9:30 |
| 6. CAM | 2:00 | 11:30 |
| 7. ETA ⭐⭐ | 2:00 | 13:30 |
| 8. Owner Portal | 2:00 | 15:30 |
| 9. Tenant Portal | 1:30 | 17:00 |
| 10. Energy | 1:00 | 18:00 |
| 11. Arabic | 0:45 | 18:45 |
| 12. Roadmap | 1:00 | 19:45 |
| 13. Partnership ask | 1:00 | 20:45 |
| Q&A buffer | ~4:00 | ~25:00 |

If you're running long: cut Energy (step 10) and Arabic (step 11) — the brand-swap in step 3 already implicitly shows locale support; energy is the lowest-stakes module.

If you're running short: extend Tenant Sales (step 5) — workflow drill into the dispute flow, show audit log entries, show the auto-generated Charge on the lease.

---

## After the meeting (within 24 hours)

Send:
- The deck ([PITCH-DECK.md](PITCH-DECK.md))
- The proposal ([PILOT-PROPOSAL.md](PILOT-PROPOSAL.md))
- A short demo recording link (5 min)
- Logins to the live demo environment (your hosted instance, not local)
- Calendar invite for the working session within 14 days

Don't send the codebase or [MASTER-PLAN.md](MASTER-PLAN.md) — those are internal.
