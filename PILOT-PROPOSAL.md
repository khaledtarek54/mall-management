# Atriom Pilot Proposal

> **Platform:** Atriom — Egyptian Mall Operations
> **Prepared for:** Eltizam Asset Management Group
> **Property:** Haya Walk, 6th of October City (Jawad Developments)
> **Term:** 6 months, extendable
> **Prepared by:** [Your company]
> **Date:** [Issue date]
> **Validity:** 30 days from issue

---

## The proposal in one sentence

A six-month pilot deployment of **Atriom** — a production-grade Egyptian-mall operations platform — at Haya Walk, branded and operated alongside PropEzy as Eltizam's specialized retail-vertical layer, with a defined commercial and a path to multi-property rollout.

---

## What you get on day one

| Capability | Status |
|---|---|
| Lease lifecycle: create, renew, terminate, expire | ✓ Live |
| Monthly billing engine — one-click run, EG VAT (rent exempt, service 14%), idempotent | ✓ Live |
| **Credit Notes & Refunds** — issue / apply / void with idempotent service-layer math | ✓ Live |
| **Late-fee automation** — scheduled daily, idempotent per invoice, % + grace days configurable from Settings | ✓ Live |
| Tenant sales declarations + percentage-rent auto-billing (both formulas: artificial + natural breakpoint) | ✓ Live |
| CAM (common-area maintenance) reconciliation — pro-rata allocation by leased sqm | ✓ Live |
| ETA e-invoicing (Egyptian Tax Authority) — JSON document builder + submission | ✓ Mock; live on credentials |
| **ETA Compliance dashboard widget** — Valid / Submitted / Rejected / Pending tiles, clickable to filtered invoice lists | ✓ Live |
| **Reports module** — downloadable Monthly Close PDF (EN + AR) + AR Aging drilldown | ✓ Live |
| Maintenance ticketing — admin triage + tenant portal submission + SLA tracking + **channel attribution** | ✓ Live |
| **Vendor Management** — vendors + contacts + contracts; routes external maintenance via `assigned_to_vendor_id` | ✓ Live |
| Multi-property tenancy with per-operator brand swap (logo, name, favicon) | ✓ Live |
| **Property-staff assignment** — `asset_user` pivot assigns staff to specific properties | ✓ Live |
| Three Filament panels: Admin, Tenant Portal, Owner Portal | ✓ Live |
| **Mobile API** at `/api/v1/*` — Sanctum tenant auth (login + me + logout) shipped | ✓ Live (auth) |
| Arabic-native UI + mPDF Arabic-shaped invoice / statement / monthly-close PDFs | ✓ Live |
| **12 role-tailored dashboard widgets** — leasing / maintenance / finance see only relevant signal | ✓ Live |
| **Sales Density column** on Top Tenants — mall-vertical benchmark (declared sales ÷ sqm) | ✓ Live |
| **Leasing Pipeline widget** — Draft → Active funnel with EGP/mo per stage | ✓ Live |
| **81 granular RBAC permissions** across 18 modules + 6 built-in roles + **custom role creator UI** | ✓ Live |
| **Dynamic Settings panel** at `/admin/settings` — Modules / Billing / Maintenance / ETA / Integrations tabs | ✓ Live |
| **Module Feature Flags** — turn any optional module on/off live (hides nav + blocks URLs + hides widgets) | ✓ Live |
| Spatie audit trail across 13 entities | ✓ Live |
| Spatie media library — contract / ID / maintenance-photo uploads | ✓ Live |
| **CSV imports** for bootstrapping (Tenants / Units / Leases) with sample templates | ✓ Live |
| **Scheduled jobs** — monthly billing, late fees daily, CAM annual reconciliation | ✓ Live |
| **GitHub Actions CI** — PHPUnit (sqlite) + Playwright (MySQL) on every push | ✓ Live |
| Paymob integration (card / InstaPay / wallet) | ⏸ Architected; live on sandbox credentials |
| WhatsApp Business API outbound (invoice reminders) | ⏸ Architected; live on Meta/BSP credentials |

Full feature inventory in [FEATURES.md](FEATURES.md). Strategic context in [MASTER-PLAN.md](MASTER-PLAN.md). Dashboard parity vs PropEzy in [docs/GAP-ANALYSIS-PROPEZY-DASHBOARD.md](docs/GAP-ANALYSIS-PROPEZY-DASHBOARD.md).

---

## Pilot scope

### In scope

- Deployment to your infrastructure of choice (your servers / our managed hosting / split — your call)
- Data migration of Haya Walk's current lease + tenant + invoice data (CSV-based; format aligned during onboarding)
- One round of Eltizam-tailored branding (logo, primary color, favicon, login screen)
- Training: 2 sessions (admin staff + property manager) of 90 minutes each
- Slack / WhatsApp support channel with same-business-day response
- Weekly progress demo for the first 6 weeks; bi-weekly thereafter
- Quarterly roadmap review aligned with your priorities
- ETA test-environment submission (mock today, real flow once your taxpayer profile credentials are issued)

### Out of scope (for the pilot — extendable)

- Multi-property rollout — deferred to post-pilot decision
- Custom integrations beyond Paymob + ETA + WhatsApp + Email
- Native mobile app (Q2 — see [MOBILE-APP-BRIEF.md](MOBILE-APP-BRIEF.md))
- Translation into languages beyond Arabic + English
- White-glove migration from PropEzy-style data (we'll co-design the import format if needed)

### Explicit non-goals

- We are **not** replacing PropEzy for community / residential / workplace properties
- We are **not** charging for the existing built feature set; you pay for the platform + ongoing support, not for what's already shipped

---

## Commercial — three tiers

Pick the tier that matches your go-to-market preference. All tiers include the same software; the difference is branding flexibility.

| | **Our-brand** | **Co-brand** | **White-label** |
|---|---|---|---|
| Brand on tenant portal | Our company name visible | Eltizam + us | Eltizam-only |
| Brand on PDFs / statements | Our company name | Eltizam + us | Eltizam-only |
| Brand on admin panel | Our company name | Eltizam + us | Eltizam-only |
| **One-time setup fee** | 120,000 EGP | 150,000 EGP | 180,000 EGP |
| **Monthly operating fee** | 15,000 EGP/mo | 18,000 EGP/mo | 22,000 EGP/mo |
| **Pilot total (6 months)** | 210,000 EGP | 258,000 EGP | 312,000 EGP |
| **Post-pilot rate (per property)** | 30,000-60,000 EGP/mo | 35,000-70,000 EGP/mo | 45,000-85,000 EGP/mo |

**Setup fee includes:**
- Initial deployment + data migration
- One round of branding customization
- Training sessions (2 × 90 min)
- First-month support

**Monthly operating fee includes:**
- Platform hosting (if managed by us) — otherwise deducted
- Same-business-day support via Slack / WhatsApp
- Routine maintenance, security patches, dependency updates
- Quarterly roadmap reviews
- Bug fixes — no separate charges

**Not included — billed separately:**
- Custom development: **2,000 EGP / hour**, sold in 40-hour sprints (80,000 EGP / sprint)
- Third-party gateway fees (Paymob, WhatsApp BSP, ETA cert) — pass-through at cost
- Production-grade ETA submission certificate procurement — separate engagement once your taxpayer profile is finalized

**Payment terms:**
- Setup fee: 50% on signature, 50% on go-live
- Monthly fee: invoiced at month-end, NET 7 days
- All amounts in EGP, all invoices VAT-exempt where Egypt's tax code permits (rent / SaaS exemptions to be confirmed by your tax counsel)

---

## Success criteria

Defined jointly during onboarding, signed off in writing. Default proposal:

1. **Operational** — 100% of monthly billing runs through the platform within 60 days
2. **Adoption** — All active tenants logged into the portal at least once within 90 days
3. **ETA** — At minimum, mock submission flow proven end-to-end; production submission live once credentials are issued
4. **Audit** — Full activity log + financial reports presentable to Eltizam group governance / Aldar (if applicable) within 30 days of any request
5. **Tenant satisfaction** — At least one maintenance request submitted via the portal by each F&B / retail tenant during the pilot (proxies for portal adoption)
6. **Performance** — All admin pages load under 2s P95 on production data volume

If any of these miss, we sit down at month 3, diagnose, adjust scope or pricing as needed.

---

## Why Haya Walk

- Already operational with realistic data — 50 units, 33 active leases, real lease terms, real billing history, real tenant mix (F&B / retail / wellness)
- Single asset — pilot risk is bounded
- Jawad Developments has been the design partner — they know the platform, they're invested
- Egyptian retail walk format — representative of the broader retail vertical Eltizam services across Tafawuq Egypt + Three60 Egypt
- 6th of October location — accessible for in-person working sessions

---

## What we need from Eltizam

- A single point of contact on your side (operations + technical, can be two people)
- Access to your tax counsel for the ETA + VAT exemption confirmations
- Logo files + brand guidelines for the branding round
- 2 hours/week from a designated property manager for the first 6 weeks
- Sign-off on the success criteria before month 1 ends

---

## What happens after 6 months

Three outcomes, agreed upfront so neither side is surprised:

1. **Expand** — Eltizam wants to roll the platform to a second / third property. We co-scope, sign an extension or master agreement, and the per-property post-pilot rate kicks in.
2. **Continue as-is** — Haya Walk stays on the platform at the monthly rate, no expansion. Either side can give 30 days' notice.
3. **Wind down** — Eltizam exits the engagement. We export all data in open formats, hand over admin credentials, provide a 30-day transition window. No exit fees.

---

## Risk disclosures (in writing, so you don't have to ask)

- **External-credential dependencies.** Paymob (Egyptian payments) and ETA (preprod submission) both require credentials we cannot procure on Eltizam's behalf. Mock flows demo today; live integration begins on credential arrival.
- **Mobile app gap.** PropEzy has iOS + Android apps today; we don't. The Q2 roadmap closes this with an Egyptian-mall-tenant-specialist app — see [MOBILE-APP-BRIEF.md](MOBILE-APP-BRIEF.md). For the pilot, the web tenant portal + WhatsApp share workflow covers the same operational ground.
- **Team capacity.** We're a small team, deliberately. The post-pilot per-property rate is calibrated to maintain that quality. We will be transparent if expansion outpaces what we can sustain at quality.
- **Aldar merger uncertainty.** If Eltizam's corporate structure changes during the pilot, our default position is: contract continues, scope continues, we re-discuss commercials at month 6 in the regular review cycle.

---

## Acceptance

To proceed, sign and return this proposal or send written acceptance via email referencing:

- **Proposal date:** [Issue date]
- **Selected tier:** [Our-brand / Co-brand / White-label]
- **Effective start date:** [Mutually agreed date]
- **Signatory authority:** [Name, title, Eltizam entity]

Within 5 business days of acceptance, we deliver:
- Signed mutual NDA
- Detailed onboarding plan with weekly milestones for the first 6 weeks
- Branding spec request + technical access checklist
- Slack / WhatsApp channel invitations

---

**Contact:**
[Your name]
[Your role]
[Email] · [Phone] · [WhatsApp]

---

*This proposal references publicly-visible information about PropEzy (propezy.com, app stores, press) and Eltizam Group (eltizam.ae, recent press). No confidential information is implied or used. All Eltizam-related claims are sourced and citable in [MASTER-PLAN.md](MASTER-PLAN.md) § 2.*
