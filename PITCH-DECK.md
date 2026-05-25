# Eltizam Partnership Pitch Deck

> **Status:** Internal speaker reference. Render as slides via [Marp](https://marp.app/), [Slidev](https://sli.dev/), or paste into Keynote / Google Slides.
> **Length:** 12 slides + a closing artifacts slide. Target ~25-30 minutes including 10 min live demo and Q&A.
> **Tone:** Partnership and specialization, not competition. Acknowledge PropEzy's strength. Never attack.
> **Audience:** Eltizam Group decision-makers (EAMG, iFM, EAST-O Holdings) + their technical evaluators.

---

## Slide 1 — Cover

**Egyptian Mall Operations Platform**
A specialized layer alongside PropEzy for retail vertical operations

[Your company name / logo]
Egypt — 2026

**Speaker notes:**
Open warm. "Thank you for the time. We've been building an Egyptian-mall operations platform with Jawad Developments at Haya Walk over the past year. After looking at where PropEzy sits in your stack today, we wanted to propose something specific — not a replacement, a complement."

---

## Slide 2 — Acknowledge their strength

**PropEzy is impressive for community and residential management.**

What it does well (we've studied it):
- Three integrated modules (Community, Workplace, Property)
- iOS + Android tenant apps with residential-grade UX
- Mature OAM workflows + service requests
- Already deployed in Egypt at Ora ZED Sheikh Zayed (residential)

**Speaker notes:**
Do NOT skip this slide. Spend 30 seconds genuinely complimenting PropEzy. The Eltizam team built it — leading with "we're better than your thing" closes the conversation. Lead with respect.

Source the Ora deployment specifically. It signals you did your homework.

---

## Slide 3 — The Egyptian mall vertical is different

**Three things malls need that residential platforms aren't built for:**

1. **Tenant sales declarations & percentage rent** — monthly turnover from F&B / retail tenants drives lease economics
2. **CAM (Common Area Maintenance) reconciliation** — annual true-up of shared expenses pro-rata across leases
3. **Egyptian regulatory layer** — ETA mandatory e-invoicing, Arabic-shaped PDFs, EG VAT model (rent exempt, service 14%), EGP payment rails

PropEzy serves five use cases well. We optimize *one* harder.

**Speaker notes:**
This is the core thesis. Specialization > generalization. The three bullets are the three modules we'll demo: tenant sales / CAM / ETA. Each is something PropEzy doesn't publicly advertise.

If they push back on "we can add this to PropEzy" — that's slide 9's job. Don't engage here.

---

## Slide 4 — What we've built (numbers, not adjectives)

**Production-grade today, in Egypt, for Egyptian malls.**

- **22-entity data model** — every real PMS entity from Asset down to CreditNoteItem, Vendor, VendorContract, MaintenanceRequestComment
- **3 Filament panels** — Admin (`/admin`), Tenant (`/portal`), Owner (`/owner`) — plus a Sanctum REST API at `/api/v1/*` for the upcoming mobile app
- **12 admin dashboard widgets, role-tailored** — leasing managers see leasing pipeline + tenant mix; maintenance managers see open MR + energy; finance sees AR aging + ETA compliance
- **Egyptian-CFO-grade signal** — ETA Compliance tiles (Valid/Submitted/Rejected/Pending, clickable to filtered invoice lists), Leasing Pipeline funnel (Draft → Active with EGP/mo per stage), Sales Density column on Top Tenants
- **170+ Playwright E2E specs** + **36 PHPUnit service tests** (124 assertions) locking the billing math
- **81 granular RBAC permissions** across 18 modules, **custom role creator UI**, 6 built-in roles
- **Property-staff assignment** via `asset_user` pivot — admins assign staff to specific properties
- **Dynamic Settings** — every config value editable from `/admin/settings` with tabs (Modules / Billing / Maintenance / ETA / Integrations)
- **Module Feature Flags** — turn entire modules on/off live; disabled modules vanish from the sidebar, block direct URL access, hide their widgets
- **~1,100-line Arabic translation file** + mPDF Arabic shaping + bidi for invoices, statements, monthly close PDF
- Multi-property tenancy with per-operator dynamic branding (logo + name + favicon swap)
- **Reports module** — downloadable Monthly Close PDF (EN + AR), AR Aging drilldown page
- Full **Credit Notes & Refunds** AR lifecycle (issue · apply · void with idempotent service-layer math)
- **Vendor management** — vendors + contacts + contracts + routing maintenance to external vendors

**Speaker notes:**
Tight, specific, numerical. Don't editorialize. Let the numbers carry the credibility. The next slide is the demo handoff.

If anyone asks tech stack: Laravel 13.8 + PHP 8.4 + Filament 4 + MySQL + Sanctum + Spatie (Permission / Settings / ActivityLog / MediaLibrary). Industry-standard, easy to onboard developers.

---

## Slide 5 — Live demo

**[Switch to browser]**

10-minute walk-through:
1. Mall admin — dashboard, occupancy map, maintenance triage
2. Tenant Sales Declaration → Lock → Percentage rent charge auto-billed
3. CAM Reconciliation — generate allocations pro-rata, bill the true-up
4. ETA submission — Valid response with submission ID
5. Multi-operator switch — Jawad brand → "Eltizam Egypt" brand swap, same login
6. Owner Portal — portfolio KPIs across owner's assets
7. Arabic toggle on every screen

**Speaker notes:**
Use [DEMO-ELTIZAM.md](DEMO-ELTIZAM.md) for the exact click flow. Hit each module in under a minute. The brand-swap moment is the showstopper — pause for it.

If demo wifi dies, you have the backup video.

---

## Slide 6 — Egyptian-first features (specific, not abstract)

| Feature | Status | What it means |
|---|---|---|
| **ETA e-invoicing** | Architected, mock-ready | Document JSON spec implemented (v1.0, T1/V009 tax codes); flip `ETA_MOCK=false` when creds arrive |
| **ETA Compliance dashboard widget** | ✓ Live | 4-tile posture (Valid/Submitted/Rejected/Pending) deep-linking to filtered invoice lists — the headline CFO moment |
| **Arabic PDF rendering** | ✓ Production | mPDF with autoArabic + autoLangToFont; DomPDF (Filament default) emits broken Arabic |
| **EG VAT model** | ✓ Production | Rent exempt, service 14% — per-charge `vat_applicable` + `vat_rate`; VAT summary in Monthly Close PDF |
| **Tenant Sales Declaration + Percentage Rent** | ✓ Live | Both formulas (artificial + natural breakpoint); 6 PHPUnit tests lock the math |
| **CAM Reconciliation** | ✓ Live | Pro-rata by sqm; idempotent allocation generator + per-allocation true-up charges |
| **Credit Notes & Refunds** | ✓ Live | Full AR lifecycle (issue → apply → void) with idempotent service-layer math |
| **Vendor Management** | ✓ Live | Vendors + contacts + contracts; FK on `maintenance_requests.assigned_to_vendor_id` |
| **Custom Roles + 81 Permissions** | ✓ Live | UI to create custom roles with any combo of granular permissions |
| **Module Feature Flags** | ✓ Live | Turn any optional module on/off from `/admin/settings`; live toggle in the demo |
| **Property Staff Assignment** | ✓ Live | `asset_user` pivot — assign staff to specific properties |
| **Dynamic Settings panel** | ✓ Live | Late-fee %, SLA hours, ETA flags, integrations — editable from UI, not env files |
| **EGP / DD-MM-YYYY** | ✓ Throughout | No retrofit; engineered in |
| **Mobile API (Sanctum)** | ✓ Auth shipped | Tenant login + token issue at `/api/v1/auth/login`; resource endpoints arriving Q2 |
| **Paymob (card/InstaPay/wallet)** | Architected | Gated by `integrations.paymob_enabled` flag; wires up at sandbox-cred arrival |
| **WhatsApp Business** | Architected | Gated by `integrations.whatsapp_enabled` flag; ready for Meta or BSP integration |

**Speaker notes:**
This is the proof slide. Every row maps to code that exists today. Be ready to open the codebase if asked.

The three headline rows for Egyptian buyers: **ETA Compliance widget** (the only PMS in Egypt that shows ETA posture on the dashboard), **Tenant Sales + Percentage Rent** (mall-vertical specialization PropEzy doesn't advertise), **Custom Roles + Module Flags** (operator can shape the platform without touching code).

---

## Slide 7 — How we fit alongside PropEzy

```
                ELTIZAM GROUP
                      │
   ┌──────────────────┼──────────────────────────┐
   │                  │                          │
  EAMG            iFM Holdings              EAST-O Holdings
 (Omnius)       (Tafawuq, Three60)         (PropEzy, OrionTEK)
                                                  │
                       SERVICES LAYER             ▼
       (Tafawuq · Three60 · Omnius · OAM · Brokerage)
              │                              │
              ▼                              ▼
       ┌────────────┐                ┌────────────┐
       │  PropEzy   │  ←──────────→  │ Our Platform│
       │ Community  │   alongside    │ Egyptian   │
       │ Workplace  │                │ Mall       │
       │ Residential│                │ Specialist │
       └────────────┘                └────────────┘
              │                              │
       Residents / office          Mall tenants / operators
       Community owners            Mall owners / Eltizam ops
```

**Speaker notes:**
This is the most important slide for diffusing competitive anxiety. Show the diagram and say it plainly:

*"We're not competing. We're sitting next to PropEzy. Same operator hierarchy, different vertical. Your residential clients stay on PropEzy. Your mall clients get us."*

If they ask about data sharing — say honest answer: "Today, separate stores. APIs can bridge when there's a real workflow that needs it."

---

## Slide 8 — The pilot proposal

**Haya Walk · 6 months · defined scope**

| Item | Detail |
|---|---|
| Scope | Haya Walk (Jawad Developments) — 50 units, 33 active leases |
| Duration | 6 months (extendable) |
| Branding | Co-branded or white-labeled to Eltizam |
| Deliverables | Full deployment, training, ongoing support |
| Setup | One-time fee covering customization + data migration |
| Operating | Monthly retainer for platform + support |
| Custom dev | Hourly, sold in sprints, against your priority list |
| Success criteria | Defined together, before signature |

See [PILOT-PROPOSAL.md](PILOT-PROPOSAL.md) for full terms and commercials.

**Speaker notes:**
Don't read the table. Lead with: *"Six months, one property, defined scope. De-risks both sides."*

Hand them the one-pager. Don't read prices off the deck — let them ask.

---

## Slide 9 — "Why not just extend PropEzy?"

**Anticipating the question.**

Three honest answers:

1. **Speed.** PropEzy's UAE roadmap competes with your Egyptian mall needs for engineering attention. We move at the speed of one vertical, one geography.
2. **Specialization.** Generalist platforms make trade-offs against any specific vertical. Mall-specific features (CAM, percentage rent, anchor tenant analytics) optimize harder when they're the *only* thing the team thinks about.
3. **Opportunity cost.** Your UAE engineering capital stays focused on what already wins — community, workplace, residential. We carry the Egyptian mall feature load.

**Speaker notes:**
This will come up. Don't be defensive. Answer once, calmly, then move on.

If they push: "By the time PropEzy's roadmap prioritizes Egyptian mall workflows, we've shipped five iterations. That's not a knock — it's just where focus lives in each team."

---

## Slide 10 — Commercial model — three tiers

| Tier | Branding | Setup | Monthly | Post-pilot per property |
|---|---|---|---|---|
| **Our-brand** | Your company name visible | 120K EGP | 15K EGP/mo | 30K-60K EGP/mo |
| **Co-brand** | Eltizam + us | 150K EGP | 18K EGP/mo | 35K-70K EGP/mo |
| **White-label** | Eltizam-only branding | 180K EGP | 22K EGP/mo | 45K-85K EGP/mo |

- Custom development: 2,000 EGP/hour, 40-hour sprints
- Pilot value: 210K-330K EGP over 6 months depending on tier

**Speaker notes:**
Pricing is honest, not aggressive. Egyptian retail-tech market reference points: international platforms are 2-3x these numbers without the Egyptian-native features.

White-label premium is ~30% — that's the right answer if they push for "no co-branding, Eltizam only."

---

## Slide 11 — Roadmap (what's coming, ordered by what they care about)

| Quarter | Focus |
|---|---|
| **Live today** | Lease lifecycle · monthly billing engine · multi-property tenancy · maintenance + vendor routing · tenant sales + percentage rent · CAM reconciliation · ETA (mock) + dashboard compliance widget · Arabic PDF · multi-operator dynamic branding · Owner portal · Energy data + 12-month consumption chart · **Credit Notes & Refunds** · **Vendor Management** · **Custom Roles + 81 granular permissions + role manager UI** · **Property Staff Assignment** · **Dynamic Settings + Module Feature Flags** · **Reports module (Monthly Close PDF + AR Aging drilldown)** · **Role-tailored dashboards** · **Mobile API auth (Sanctum)** · CSV imports · scheduled jobs · CI on every push |
| **Q1 2026** | Paymob live (sandbox merchant in flight) · ETA preprod credentials (taxpayer profile in flight) · email-on-issue Mailable (shipped, awaiting SMTP) · Property-staff query scoping enforcement · Recurring Maintenance Schedules · Reports: collections report + tenant statement enhancements |
| **Q2 2026** | Mobile tenant app — Egyptian-mall-tenant specialist — see [MOBILE-APP-BRIEF.md](MOBILE-APP-BRIEF.md) (login API already shipped) · ETA production cert · CAM auto-true-up wizard · Anchor tenant performance widget + foot-traffic placeholders |
| **Q3 2026** | IoT integration hooks · energy optimization workflows · accounting close export · predictive maintenance |
| **Q4 2026** | AI-assisted lease abstraction · tenant ratings · churn prediction · anchor performance analytics |

Roadmap is steered by **your priority input**, not our backlog.

**Speaker notes:**
Frame the roadmap as collaborative. The last line is critical: "Steered by your priority input." That's the partnership signal.

If they push on any specific feature, write it down on the spot. Tells them their input has weight.

---

## Slide 12 — The next step

**A 90-minute working session, next week, with your team.**

We bring:
- Demo environment with your team's accounts pre-provisioned
- Pilot proposal PDF
- Architecture deep-dive deck for your technical evaluators
- A whiteboard

By the end of the session:
- You have a written 90-day plan
- You have a commercial number you can act on
- We have a yes / no / specific conditions

**Speaker notes:**
Concrete next step. Specific. Time-bounded. Action-oriented. Don't leave the room without one of three things: a scheduled session, a written objection list, or a clean no.

If they want the deck + proposal sent over instead of an in-person session — send them within 24 hours. Then push for the working session in the follow-up email.

---

## Closing — Artifacts you can take with you

- This deck: `PITCH-DECK.md`
- Pilot proposal: [PILOT-PROPOSAL.md](PILOT-PROPOSAL.md)
- Demo script: [DEMO-ELTIZAM.md](DEMO-ELTIZAM.md)
- Strategy + competitive context: [MASTER-PLAN.md](MASTER-PLAN.md)
- Full feature inventory: [FEATURES.md](FEATURES.md)
- Mobile app brief (Q2): [MOBILE-APP-BRIEF.md](MOBILE-APP-BRIEF.md)
- Working demo: `https://demo.[your-domain]` (admin@mall.test / password)

**Speaker notes:**
Print this slide as a leave-behind. The Eltizam team will want to circulate internally — make it easy.

---

## Pre-meeting checklist (1 day before)

- [ ] Backup demo video recorded (5 min, narrated)
- [ ] Mobile hotspot tested
- [ ] Laptop charged + charger in bag
- [ ] Phone has /portal loaded and logged in (for step 9 of demo)
- [ ] Browser at clean state — no devtools, no random tabs
- [ ] Private window per role pre-logged-in (admin / owner / tenant)
- [ ] Print: deck (12 slides), pilot proposal (1 page), architecture diagram
- [ ] Eltizam attendee names + roles researched on LinkedIn
- [ ] Last 30 days of PropEzy LinkedIn / press scanned — anything new?
- [ ] Demo script read aloud once, timed

## Objection-handling cheat sheet (read on the way in)

| If they say | You say |
|---|---|
| "We already have PropEzy" | "PropEzy is great for community. We're complementary for Egyptian retail. Show me a mall-specific feature in PropEzy." |
| "Why not extend PropEzy?" | See slide 9. Speed + specialization + opportunity cost. |
| "What about UAE deployment?" | "Egypt-first today. UAE is a Q3 conversation once Egyptian operations are proven." |
| "Show us your ETA integration" | Open the Invoices page, click Submit to ETA, show the Valid response. "Mock today, live when creds arrive. Plus the ETA Compliance widget on the dashboard — at-a-glance Valid/Submitted/Rejected/Pending posture." |
| "Mobile app?" | "Auth API already shipped against the new Sanctum endpoint. Resource endpoints next. Brief at [MOBILE-APP-BRIEF.md](MOBILE-APP-BRIEF.md). Today we lean on web tenant portal + WhatsApp, which is how Egyptian tenants actually communicate." |
| **"What about role-based access control?"** | "81 granular permissions across 18 modules. 6 built-in roles (super_admin, manager, viewer, owner, leasing_manager, maintenance_manager). UI to create custom roles with any combination. Show `/admin/roles` if asked." |
| **"Can we turn modules off?"** | "Yes — every optional module has a feature flag in `/admin/settings → Modules`. Toggle off live: the module disappears from the sidebar and blocks direct URL access. Demo it: turn off Vendors → it's gone." |
| **"Can we configure billing rules?"** | "Yes — `/admin/settings → Billing` has late-fee percentage, grace days, billing day, CAM reconciliation schedule. All DB-backed via Spatie laravel-settings, audit-logged." |
| **"Custom Egyptian mall workflows we'd need"** | "List them. We've shipped sales declarations, percentage rent (both formulas), CAM reconciliation, ETA submission, channel-attributed maintenance. Anything else, we'd scope as a sprint." |
| "Pricing seems high / low" | Let them lead. If high — point to international platform comparables (Yardi, MRI). If low — emphasize the local-team velocity premium they're skipping. |
| "Who else is using it?" | "Jawad Developments at Haya Walk. We're being selective on the next deployment — vertical fit matters more than logo collection." |
| "What if Aldar acquires Eltizam?" | "Same answer either way — Egyptian retail is a tractor without a driver right now. We solve a specific problem regardless of corporate structure." |

---

> **Remember:** Specialization, partnership, respect. Never attack. Numbers, not adjectives. Show, don't tell.
