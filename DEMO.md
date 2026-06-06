# Atriom — Demo Run-through (Atriom Walk)

Audience: **operations team walkthrough**. Goal: show that Atriom runs the
day-to-day of a retail property — leasing, billing, collections, maintenance,
tenant comms, and reporting — faster and cleaner than spreadsheets.

> Environment for this demo: **Laravel Cloud** (deployed). Use your app's Cloud
> URL — find it in the Cloud dashboard (`khaled-tarek/mall-management → main →
> the web app's URL`). Everywhere below, `<APP>` = that URL, e.g.
> `https://<APP>/admin`.

---

## Pre-flight (10 minutes before)

1. **Re-seed fresh demo data** so the numbers are clean and consistent. On Laravel
   Cloud, open the app → **Commands** (or a deploy SSH/console) and run:
   ```
   php artisan migrate:fresh --seed --force
   ```
   ⚠️ This wipes and rebuilds the database — only do it on the demo instance.
   The seed is deterministic (`DemoSeeder::DEMO_RNG_SEED`), so the KPIs
   below come out the same every time.
2. Open `https://<APP>/admin` in a fresh **incognito** window (no stale login).
3. Language: keep **EN**, flip to **عربي** once mid-demo to show RTL, then back.
4. Zoom 100%, close other tabs, silence notifications.
5. Have this file open on a second screen as your script.

## Logins

| Role | Email | Password |
|------|-------|----------|
| Super Admin | `admin@mall.test` | `password` |
| Manager | `manager@mall.test` | `password` |
| Viewer | `viewer@mall.test` | `password` |
| Leasing Manager | `leasing@mall.test` | `password` |
| Maintenance Manager | `maintenance@mall.test` | `password` |
| Owner (read-only `/owner`) | `owner@atriom.test` | `password` |
| Tenant portal (`/portal`) | `tenant1@atriomwalk.test` | `password` |

> All demo users share one password = the `DEMO_USER_PASSWORD` env var on Cloud
> (falls back to `password`). Drive the demo as **Super Admin**; swap to a
> role-specific login only to show role-tailored dashboards.

---

## Live narration script (~15 min core)

### 1 · Login + dashboard (2.5 min) — lead with value
Log in as Super Admin. You land on **All Properties** (portfolio view).

- **Switch to Atriom Walk** in the top-bar property switcher. "Atriom is
  multi-property — this operator runs Atriom Walk plus a second strip, Plaza Annex.
  Everything you see re-scopes to the property you pick."
- **KPI strip:** "Four live KPIs, each with a real 6-month sparkline — not
  placeholders. Occupancy 66% (33 of 50 units), Monthly Recurring Revenue
  ~EGP 1.6M, Collected This Month, and Outstanding AR with an overdue count.
  The AR tile turns amber/red as collection slips."
- **Revenue Trend:** "12 months billed vs collected, with the collection-rate
  line on a second axis. Hover for exact EGP."
- **AR Aging / Tenant Mix / Expiring Leases / Top Tenants / Recent Payments:**
  "The operations cockpit — what's overdue, what's expiring in 90 days, who your
  biggest tenants are, and the latest money in."

### 2 · Arabic / RTL (20 s)
Flip **عربي** in the top bar. "Same data, full RTL, EGP and localized dates."
Flip back to EN.

### 3 · Properties, units & the occupancy map (1.5 min)
Sidebar → **Properties** → Atriom Walk. "One asset, 50 leasable units across
three zones, 33 occupied." Then sidebar → **Occupancy Map**: "A color-coded
floor view — occupied / vacant / reserved / maintenance at a glance. Staff only
see the properties they're assigned to."

### 4 · Tenant directory (1 min)
Sidebar → **Tenant Directory**. "33 tenants — real brands: Cilantro, Buffalo
Burger, Seoudi Market, B.TECH, Magrabi, Cook Door… Each can be granted a portal
login from the Edit screen. Search / filter / sort all work."

### 5 · Create a lease — show the speed (1.5 min)
Sidebar → **Leases → New Lease**. Pick a vacant unit (list filters to vacant),
set dates + base rent + service charge ("14% VAT auto-applied"), point out the
**percentage-rent** section ("base rent + a % of sales above a breakpoint —
standard in malls"). Save → "unit flips to occupied automatically."

### 6 · Create an invoice — the killer feature (2 min)
Sidebar → **Invoices → New Invoice**. Pick a lease — "watch the line items
**auto-fill from the lease's monthly charges**, VAT pre-computed." Edit an amount
→ subtotal/VAT/total update live. Add a "Late fee" line. Note: **the due date
must be after the issue date** (try an earlier date — it's blocked). Save →
"number auto-generated, items linked."

### 7 · Monthly billing + collections (1.5 min)
On the Invoices list, point out **Run Monthly Billing**: "one click generates an
invoice per active lease for the period, skipping anyone already billed." Then
open a tenant with a balance and **record a payment** → "AR and the dashboard
update instantly; the tenant gets an email + portal notification."

### 8 · Maintenance (2 min) — the operations heart
Sidebar → **Maintenance**. "Five live tickets across statuses and channels —
WhatsApp, phone, walk-in, portal, email." Open the urgent Cilantro AC ticket:
- "Status workflow with SLA targets; **the resolution-target date can't predate
  the request**."
- "Internal notes vs tenant-visible comments. **A tenant comment pings the
  operations bell; a staff reply notifies the tenant.**"
- "Photo/PDF attachments — restricted to images and PDFs — and they sync to the
  tenant mobile app."
- Assign to a vendor from the **Vendors** directory (8 vendors with contacts +
  contracts).

### 9 · Sales declarations & CAM (1.5 min)
Sidebar → **Sales Declarations**: "Tenants declare monthly sales; you review and
**lock** one → it auto-creates the percentage-rent charge for next billing."
Then **CAM**: "Annual common-area cost pool, pro-rata allocations per leased
sqm, billed as true-ups. Last year reconciled; this year is a draft you can
generate live."

### 10 · Reporting, ETA & audit (1.5 min)
- **Reports** → downloadable Monthly Close PDF + AR aging drill-down.
- **ETA Compliance** widget / invoice action → "Egyptian Tax Authority
  e-invoicing, running in mock mode; flip one flag for live creds."
- **Activity Log** → "every create/edit/delete tracked: who, when, what changed."
- **Notifications bell** → "operator inbox for portal events + SLA breaches."

### 11 · Roles, portal & mobile (1 min)
- **Settings → Users / Roles**: "6 built-in roles + a custom-role builder over
  81 granular permissions. Managers can't delete; viewers are read-only."
- Open `/portal` as `tenant1@atriomwalk.test`: "tenant self-service — statements,
  invoices, pay online, raise maintenance."
- Mention the **mobile API** (`/api/v1`) powering the tenant app: invoices,
  payments, maintenance with attachments, sales declarations, push tokens.

---

## Things to flag if asked

- **Online payments (Paymob)** — wired end-to-end (auth → order → iframe →
  HMAC-verified callback). Disabled in the demo (`PAYMOB_ENABLED=false`) so the
  button is hidden; a `pay-demo` API endpoint lets the mobile app simulate a
  successful payment through the real capture path until live creds land.
- **Credit notes & refunds** — full AR lifecycle (issue → apply → void).
- **Two-factor auth** — TOTP enforced for super-admins; opt-in for others.
- **Module flags** — `/admin/settings → Modules` turns optional modules on/off
  live.
- **WhatsApp + PDF** — invoice/statement sharing.
- **Multi-property scoping** — staff assigned to one property never see another's
  data (Plaza Annex demonstrates this).

## Numbers cheat-sheet

```
Property:        Atriom Walk (Atriom Developments) + Plaza Annex (scoping demo)
Units:           50 (33 occupied · 17 vacant) → 66% occupancy
Tenants:         33 — real Egyptian brands (F&B / retail / wellness / service)
Active leases:   33
MRR:             ~EGP 1.6M
Invoices:        ~200 total · ~10 overdue
Credit notes:    4 (draft / issued / applied / void)
Vendors:         8 (contacts + contracts)
Maintenance:     5 tickets across statuses + 5 channels
Permissions:     81 across 18 modules · 6 roles + custom-role UI
Tests:           444 Pest (green) + Playwright e2e specs
```

---

## Meeting playbook — how to run the session

**Mindset:** you're not listing features, you're showing *their workday* getting
easier. Talk in their language (occupancy, collections, overdue, SLA), not the
software's.

**Open (2 min):** one sentence on what Atriom is, then ask them to describe
*their* current process — what tool do they use today, what's the daily pain
(chasing payments? maintenance tracking? reporting to owners?). Their answers
tell you which sections below to dwell on.

**Run the story, not the menu:** follow the script as "a month in the life of a
property": dashboard → new tenant/lease → invoice → collect → maintenance →
month-end report. Skip sections that don't match their pain.

**Let them drive:** after section 5–6, hand over — ask them to create a lease or
record a payment themselves. Hands-on for 2 minutes beats 10 minutes of you
clicking.

**Anchor on their pain points (pick 2–3):**
- Collections/AR → dashboard AR tile, aging chart, record-payment, statements.
- Maintenance/SLA → ticket workflow, channels, vendor dispatch, attachments,
  tenant notifications.
- Owner reporting → Reports PDF, owner portal (`owner@atriom.test`).
- Compliance → ETA e-invoicing + Activity Log audit trail.

**Discovery questions to ask them:**
- How many properties / units / tenants do you manage today?
- What system are you on now (Excel, ERP, nothing)? Biggest frustration?
- Do you do percentage rent / CAM reconciliation? ETA e-invoicing yet?
- How do tenants reach you for maintenance today? Is there an app?
- Who needs logins, and what should each role see?

**Handle "can it do X?":** if yes, show it. If it's there but you're unsure,
say "yes — let me confirm the exact flow and follow up" rather than fumbling
live. If it's not there, "not yet — easy to add" and note it. Never demo a
half-working edge feature.

**Close (3 min):** summarize the 2–3 things that hit their pain, agree a concrete
next step (pilot on one real property, a follow-up with their data, or a
stakeholder demo). Capture every question/gap they raised.

**Do / Don't:**
- ✅ Re-seed beforehand; rehearse the create-lease→invoice flow once.
- ✅ Keep it to ~15–20 min of driving, leave room for their questions.
- ✅ Use the Arabic toggle — it lands well with an Egyptian ops team.
- ❌ Don't open every module; depth on their pain beats breadth.
- ❌ Don't dive into tech internals (Filament/Laravel) unless they ask.
- ❌ Don't show the raw API/JSON unless someone technical asks.

يلا بسم الله 💪
