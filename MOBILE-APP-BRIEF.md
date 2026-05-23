# Mobile App Brief — For the Mobile Developer

> This is a business briefing, not a technical one. Build it however you build mobile apps well.
> Audience: an experienced mobile dev coming into this project cold.

---

## What this product is

A mall operations platform for the Egyptian market. There's already a working web app — admin side at `/admin` and tenant side at `/portal`. Both work today. Demo property is **Haya Walk**, a retail walk in 6th of October City with 50 units across 3 zones (A/B/C), operated by Jawad Developments.

This mobile app is the **tenant-facing mobile companion** to the web tenant portal. It's the shop owner's phone app. Not the admin app. Not the owner app. Not a resident community app.

If you only remember one thing: **a mall tenant on the go needs to pay bills, see maintenance status, and declare last month's sales — from their phone, in Arabic, with WhatsApp-level UX expectations.**

---

## Who actually uses this

The user is a **mall tenant** — meaning a retailer / café / restaurant / kiosk / service shop that rents a unit in the mall. In Haya Walk's case, examples:

- **Café Crema** — coffee shop, A-zone, 80sqm, run by Hana Mostafa
- **Optix Eyewear** — eyewear retailer, B-zone, 45sqm
- **The Burger Joint** — F&B, A-zone, 120sqm
- **Andiamo Italian** — F&B, C-zone
- **Wellness Spa Sanctuary** — service, C-zone

These users are:
- Small-business owners or their finance/ops staff
- Mostly Arabic-speakers; some bilingual EN/AR
- Heavy WhatsApp users — that's the default communication channel in Egypt
- Comfortable with banking apps and InstaPay (instant bank transfer in Egypt)
- Time-poor — they're running a shop, not browsing an app for fun
- On phones constantly, but mostly Android; iOS share is smaller in this segment

**Critical:** they're NOT residents of an apartment building. They're not community members. They're business operators. Their relationship with the property is a commercial lease, not a residential one. That changes everything about the app's framing.

---

## The day-to-day of a mall tenant

To design this app well, you need to understand what a mall tenant actually deals with in a month:

### Around the 1st of every month
- Receives an invoice for the new month: base rent + service charge (+ VAT on the service charge, not on rent)
- Sometimes there's an extra line: utilities reconciliation, percentage rent from last month's sales, late fees
- Has 7 days to pay (varies by lease) before it's marked overdue

### Around the 5th-10th of every month
- For F&B and retail tenants: they need to **declare last month's gross sales** to the mall. This is mall-specific (more on this below).
- The mall uses that figure to bill them percentage rent if they exceeded their threshold.

### Whenever something breaks
- The AC dies, the plumbing leaks, a tile cracks, the fire alarm faults
- They open a maintenance request, attach a photo, set a priority
- They want to know: is it acknowledged? has someone been assigned? when's it being fixed?

### Whenever they want their books in order
- Download invoices as PDF (their accountant needs them)
- Download a statement of account for the year
- Forward the PDF to their accountant via WhatsApp

### Quarterly / annually
- Lease renewal conversations
- New unit / additional unit (rare but happens)

### Almost never
- They DON'T browse "community news"
- They DON'T book the gym
- They DON'T see notices about lobby cleaning
- They DON'T use a marketplace to sell unwanted items
- (These are residential-app features. Mall tenants don't care.)

---

## Core business concepts you need to know

These are the words you'll see in the UI. Get the meanings right and the app will feel native.

### Asset
A property. In our case the only one is "Haya Walk" but the system supports many. Think of it as **the mall itself**. For a multi-property tenant (rare), they might rent in more than one asset.

### Unit
A single rentable space inside an asset. Identified by a code like `A-01`, `B-12`, `C-07`. Has a floor, a zone (A/B/C), a category (retail/F&B/service/wellness/kiosk), and an area in square meters. A unit is either **occupied** (has an active lease) or **vacant**.

### Tenant
The renter — meaning the business, not a residential tenant. In our DB it's a model called `Tenant` with brand name (e.g. "Café Crema"), legal name, tax ID, contact info. A tenant can have multiple leases over time (renewals) and theoretically multiple units (rare in a mall, common in office buildings).

### Lease
The contract between the tenant and the operator. It has:
- A **reference** (e.g. `LSE-HW-2024-0007`)
- A **commencement date** and **expiry date**
- A **term** in months (typically 12, 24, 36)
- A **base rent monthly** (in EGP)
- A **service charge monthly** (in EGP — covers shared services, AC, security, cleaning)
- A **security deposit** (typically 3 months' rent)
- An **escalation rate** (annual increase, typically 7%) and escalation type (fixed_percent / index_linked / step / none)
- Optional **percentage rent terms** for retail/F&B (more below)
- Status: `draft` / `active` / `expired` / `renewed` / `terminated`

A lease has a "renewal chain" — when you renew, the old lease links to the new one via `previous_lease_id`. So you can see the full history of who occupied a unit and how their terms changed.

### Charge
A recurring or one-off cost on a lease. Examples:
- "Base Rent" — monthly, EGP 30,000, VAT exempt
- "Service Charge" — monthly, EGP 4,500, 14% VAT applies
- "Utility Reconciliation" — quarterly, variable
- "Parking" — monthly, EGP 500
- "Percentage Rent — Aug 2025" — one-time, calculated from sales

When the mall runs monthly billing, it pulls every active charge on every active lease and bundles them into invoices.

### Invoice
A bill. Generated automatically each month from the lease's charges. Has:
- A **number** (e.g. `INV-2025-08-00142`)
- Issue date, due date
- Period start / period end (which month this bill is for)
- Line items (one per charge that applied to this period)
- Subtotal, VAT, total
- Paid amount, balance
- Status: `draft` / `issued` / `partially_paid` / `paid` / `overdue` / `cancelled`

Tenants will look at this constantly. Their accountant will need the PDF. The PDF must be Arabic-aware (more on this below).

### Payment
Money that came in. Has an amount, a method (`bank_transfer` / `card` / `instapay` / `cheque` / `cash` / `wallet`), a reference number, and an allocation to one or more invoices (yes — one payment can clear multiple invoices; we track per-invoice allocation).

The tenant sees their payments as a history list. They can't edit them — only the mall admin records payments. (Future: when Paymob is wired up, tenant-initiated payments will appear here automatically.)

### Maintenance Request
A ticket the tenant raises when something needs fixing. Has:
- A **reference** (e.g. `MR-HW-2025-0034`)
- A **title** and **description**
- A **category**: `electrical` / `plumbing` / `hvac` / `structural` / `cleaning` / `safety` / `other`
- A **priority**: `low` / `medium` / `high` / `urgent`
- A **status flow**: `submitted` → `acknowledged` → `in_progress` → `awaiting_tenant` → `resolved` → `closed` (with `cancelled` as a side branch)
- Photos / videos as attachments
- A target resolution date (SLA — auto-calculated from priority)
- A comment thread between tenant and admin (admin can also make internal-only notes the tenant doesn't see)

This is a primary mobile workflow. Phone cameras for photos, urgency triage, status notifications.

### Tenant Sales Declaration (MALL-SPECIFIC, IMPORTANT)
This is the most distinctive workflow and the reason this app is for *mall* tenants specifically.

In commercial mall leases, F&B and retail tenants typically pay **two layers of rent**:
1. **Base rent** — a fixed monthly amount, always due
2. **Percentage rent** — additional rent calculated as a percentage of monthly gross sales above a threshold

To bill percentage rent, the mall needs the tenant to **declare** their gross sales for each month. Tenant submits → mall reviews → mall locks → percentage rent is calculated and added to next invoice.

Workflow:
- 1st-5th of month: tenant declares last month's sales (e.g. Café Crema declares EGP 320,000 for August)
- Mall reviews — if it looks right, they **lock** it
- System calculates percentage rent: `max(0, (sales - threshold) × rate)`. Example: threshold EGP 150,000, rate 6%, sales 320,000 → `(320,000 - 150,000) × 6% = EGP 10,200` percentage rent owed
- A "Percentage Rent — Aug 2025" charge gets added to the lease and shows up on next month's invoice
- If the mall thinks the declared sales look wrong (e.g. POS audit shows higher), they can **dispute** it — sends it back to the tenant

Why this matters for the app: tenant needs a clear, low-friction monthly sales submission flow. Number pad, EGP prefix, period picker, optional photo of a POS report. Confirmation. Status visibility.

Three statuses for declarations:
- `submitted` — tenant declared, awaiting mall review
- `locked` — mall confirmed and percentage rent billed
- `disputed` — mall thinks the number's wrong, conversation needed

---

## What the tenant has to do, ranked by frequency

Design priority should roughly match this list:

| # | Action | Frequency | Friction tolerance |
|---|---|---|---|
| 1 | Check balance / outstanding amount | Daily glance | Should be zero-tap on app open |
| 2 | View invoices | Monthly + ad-hoc | Low — list with status badges |
| 3 | Pay an invoice | Monthly | Lowest — this is the money moment |
| 4 | Submit monthly sales (F&B/retail only) | Monthly | Low — 30 seconds end-to-end |
| 5 | Open a maintenance ticket | When needed | Low — photo, dropdown, send |
| 6 | Track maintenance status | Daily until resolved | Push notification on status change |
| 7 | Download invoice PDF | When accountant asks | Two taps — view → share |
| 8 | Download statement of account | Annually | Two taps |
| 9 | View payment history | When accountant asks | List view |
| 10 | Read account notifications | As they come | Push + in-app inbox |

---

## Egyptian context that's load-bearing

Things that international apps get wrong about Egypt. Don't repeat the mistakes.

### Arabic-first, not Arabic-translated
- Most users will use the app in Arabic
- Numerals: depends — most tenants are fine with Western Arabic numerals (1, 2, 3) for amounts, but full Arabic numerals (٠١٢٣) for some labels. Match the web app, which uses Western numerals throughout for consistency.
- Currency display: `EGP 30,000.00` or `30,000.00 جنيه` — both forms used. Match the web app.
- Dates: **DD/MM/YYYY**. Never MM/DD/YYYY. Never YYYY-MM-DD in tenant-facing surfaces.
- Month names in Arabic: use Carbon's isoFormat MMMM YYYY equivalent in your stack — "أغسطس 2025" not "August 2025" when locale is Arabic

### Right-to-left UI
- Not just text direction. Full mirror: nav, icons, table chrome, all of it
- The web app already handles this end-to-end as a reference

### EGP / InstaPay / Paymob
- All money in Egyptian Pounds (EGP)
- Payment methods in order of relevance for this segment:
  1. **InstaPay** — instant inter-bank transfer, very common in Egypt. The shop owner has it on their banking app.
  2. **Card** — Visa/Mastercard. Standard.
  3. **Wallets** — Vodafone Cash, Etisalat Cash, Orange Money. Smaller share but real.
  4. **Bank transfer** — slower, more common for large amounts
- Cash and cheque exist but those are admin-recorded, not in-app
- The actual payment gateway is **Paymob** (Egyptian payment processor). That integration isn't live yet (the gateway accounts are being applied for), but when it is, this app needs to deep-link or in-app web-view their checkout

### WhatsApp culture
- Egyptians live on WhatsApp. Tenants will share invoice PDFs with their accountant via WhatsApp. They'll receive payment reminders via WhatsApp.
- The mall admin would ideally send invoice reminders via WhatsApp Business API (planned but not live). Your app should support sharing a PDF via the native share sheet — and the user will almost always pick WhatsApp.

### ETA (Egyptian Tax Authority) e-invoicing
- Egypt has a national e-invoicing mandate. Invoices must be submitted to ETA for B2B transactions.
- Each invoice has an `eta_submission_id` once submitted. The tenant doesn't really care about this — but their accountant does.
- Surface this on the invoice view: small line "ETA Submitted ✓" or similar when the status field has a value. Don't make it the headline.

### VAT model (do NOT generalize)
- **Base rent is VAT-exempt** in Egypt. Period.
- **Service charge has 14% VAT**.
- Other charges depend on type. The data carries `vat_applicable` (bool) and `vat_rate` per charge — trust it; don't hardcode.
- Don't show a generic "+VAT" line — show per-line items where VAT applies, sum it, total it. The web app does this correctly; use it as the visual reference.

---

## The competitive frame (why this app exists at all)

Eltizam Group, the partner we're targeting, already has a mobile app called **PropEzy**. It's a generalist platform built in UAE — community management, residential, workplace, with some property management. Their mobile app supports residential workflows: news, notices, document library, marketplace, service requests.

We're not competing with PropEzy on residential. We're complementing them on the Egyptian mall vertical. **PropEzy doesn't have:**
- Tenant sales declaration / percentage rent (this is our biggest moat)
- Arabic-native UI with proper Arabic PDF rendering
- Egyptian ETA e-invoicing integration
- Paymob / InstaPay-native payments
- Egypt-first defaults (EGP, DD/MM/YYYY, EG VAT model)
- Mall-tenant-specific workflows (CAM, anchor performance, tenant sales analytics)

So this mobile app is the **Egyptian-mall-tenant specialist** sitting in their pocket. Don't try to be everything PropEzy is. Be *better* at the narrow thing: a retailer's monthly money + maintenance + sales-declaration phone app.

---

## What's out of scope (do NOT build)

The product is deliberately narrow. Things the mobile app does NOT do:

- ❌ Admin / mall operator workflows (that's the web admin panel)
- ❌ Owner portal (different audience, web for now)
- ❌ Community / residential features — no news feed, no neighbor directory, no marketplace, no event booking
- ❌ Mall map / wayfinding for shoppers (that's a customer-facing app, different product entirely)
- ❌ POS integration (the tenant runs their own POS; we just collect declared totals)
- ❌ Inventory / stock management
- ❌ Staff scheduling
- ❌ Loyalty / promotions
- ❌ Marketing campaigns
- ❌ Energy / IoT dashboards (Q3+ roadmap, web first)

When in doubt: would a small-business owner in a mall use this in the next 5 minutes? If no, it doesn't belong in v1.

---

## Permissions / authentication

There are two completely separate user populations:

1. **Mall staff** (admin, manager, viewer) — they use the web admin. They will NOT use this mobile app.
2. **Mall tenants** (the business owners) — they use the web portal today, mobile app tomorrow.

Tenant login on the web today is email + password. Same for mobile, plus likely biometric unlock (Face ID / fingerprint) since they'll be in the app multiple times a day. A "remember me" type flow is essential — entering passwords on Egyptian mobile keyboards is friction.

The mall admin currently **generates a tenant password** on their behalf via a "Setup/Reset Portal Access" admin action. So the first-time flow is: admin shares the password with the tenant via WhatsApp → tenant logs in → tenant changes password OR uses the auto-set one. Plan for an optional change-password flow on first login.

---

## What the web tenant portal does today (mobile parity, then expansion)

The web portal at `/portal` already implements most of what mobile needs. Visit it logged in as `tenant1@haya.test` / `password` to see it in action. Mobile should match parity then add native polish.

Today's portal features:
- **Account Balance** widget — outstanding total, overdue total, open invoice count
- **Open Maintenance** widget — own open maintenance request count
- **Invoices list** — own invoices, status badges, sortable
- **Invoice view** — line items, totals, PDF download
- **Pay Now button** — stubbed today, will be Paymob when wired
- **Payments list** — own payments, allocations, methods
- **Statement of Account** — header action on Invoices page, generates a multi-page PDF
- **Maintenance Requests** — list, submit, view status timeline, comment, cancel-if-not-started
- **Tenant Sales Declarations** — submit, view own history (note: only visible to F&B/retail tenants whose lease has `has_percentage_rent = true`)
- **Language switch** EN/AR — segmented pill, top right

Mobile expansion opportunities beyond parity:
- Push notifications on invoice issued, payment received, maintenance status change, declaration locked/disputed, payment reminder
- Biometric unlock for return visits
- Native camera capture for maintenance photos (already photo-uploads via web, but mobile cameras feel better)
- Share invoice PDF via native share sheet (the WhatsApp share is the killer feature)
- Offline mode for invoice viewing (downloaded once, available without signal)
- A simple "income vs outgoings" mini-chart for the tenant's own monthly view (this is a tenant ask we've heard about, not in web today)

---

## The core data relationships (vocabulary, not schema)

When you build screens, you'll need to walk this graph. Here's how things connect in plain English:

- An **Operator** (e.g. Jawad Developments) owns one or more **Assets** (e.g. Haya Walk).
- An **Asset** contains **Units** (e.g. A-01 through C-50).
- A **Tenant** signs a **Lease** for a specific **Unit**.
- A **Lease** has multiple **Charges** (base rent, service charge, parking, etc.) — each charge has a frequency.
- Once a month, the mall runs billing — every active **Charge** on every active **Lease** generates one **Invoice Item** that bundles into a single monthly **Invoice** per lease.
- A **Tenant** pays via **Payments**, each of which can clear one or more **Invoices** (with explicit per-invoice allocation).
- A **Tenant** can submit **Maintenance Requests** against their **Unit** (or the lease's unit).
- A **Tenant** can submit **Sales Declarations** against their **Lease** (only relevant for F&B/retail with percentage rent terms).
- Locking a **Sales Declaration** creates a new "Percentage Rent" **Charge** on the lease, which then enters next month's billing.

From the tenant's mobile perspective, they're scoped to one **Tenant** identity, which gives them visibility into:
- Their own **Lease(s)** (usually one)
- Their own **Invoices** (history + current)
- Their own **Payments** (history)
- Their own **Maintenance Requests**
- Their own **Sales Declarations** (if applicable)

That's their world. Don't show them anything else.

---

## Real-world numbers for design sanity

To calibrate your designs, here are real-ish figures from Haya Walk:

- Mall total leasable area: 8,500 sqm across 50 units
- Active leases: ~33 (66% occupancy)
- Vacant units: 17
- Typical small retail unit: 40-60 sqm, EGP 20,000-30,000 monthly rent
- Typical F&B unit: 80-120 sqm, EGP 45,000-70,000 monthly rent
- Typical anchor unit (rare in Haya Walk): 200+ sqm, EGP 100,000+ monthly rent
- Service charge: ~15% of rent
- Total mall outstanding AR at any given time: EGP 400,000-600,000
- Typical tenant's monthly invoice: EGP 20,000 to EGP 80,000
- Typical percentage rent line when it fires: EGP 5,000 to EGP 20,000

A tenant logging in sees their own slice. Café Crema's monthly invoice might be ~EGP 40,000-50,000 with periodic percentage-rent lines on top.

---

## What "done" looks like for v1

A mobile app that lets a mall tenant, on their phone, in Arabic, in under 30 seconds:

- See what they owe right now
- Pay an open invoice (when Paymob is live)
- Submit last month's sales
- Open a maintenance ticket with a photo
- Check whether a previous ticket was actioned

If those five workflows feel as easy as a banking app's bill-pay flow, the app has won. Everything else is gravy.

---

## Reference materials

- Web admin at `/admin` (login: `admin@mall.test` / `password`)
- Web tenant portal at `/portal` (logins: `tenant1@haya.test`, `tenant2@haya.test`, `tenant3@haya.test`, all `password`)
- The web portal is the closest visual + flow reference for what mobile should match-then-improve
- See `MASTER-PLAN.md` for strategic context (Eltizam partnership, competitive positioning vs PropEzy)
- See `FEATURES.md` for the full feature inventory of what's built on the web side

When in doubt about a label, a workflow, or a status meaning — open the web portal, log in as a tenant, and look. The web portal is the source of truth for tenant-side behavior.
