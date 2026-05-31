# PropEzy — Detailed Feature & Market Analysis

**Date:** 2026-05-31
**Audience:** Atriom team — understand how the closest regional competitor works end-to-end.
**Scope:** Every module, every integration, every workflow, every reported weakness. Sourced from public materials (official sites, press releases, app stores, review platforms, LinkedIn). Compiled by two parallel research agents on 2026-05-31.

Companion docs:
- [GAP-ANALYSIS-PROPEZY-DASHBOARD.md](GAP-ANALYSIS-PROPEZY-DASHBOARD.md) — dashboard-specific comparison
- [MASTER-PLAN.md](../MASTER-PLAN.md) §2 — strategic positioning

---

## TL;DR

PropEzy is the **MENA proptech SaaS built by EAST-O Holdings** (Dubai), now under Aldar Estates after the July 2023 Aldar / IHC / ADNEC merger created the region's largest property + facilities management entity. It's marketed as a three-product suite (**Community / Property / Workplace**) with five role-tailored web interfaces, two mobile apps, and one BI integration (Power BI).

The platform is feature-broad — covering residential community ops, commercial leasing, workplace booking, visitor/access control, accounting via SAP, and BI — but its **public market footprint is shockingly thin**: zero verified customer reviews on Capterra, GetApp, G2, Software Advice, or TrustRadius, and the consumer-facing iOS app sits at **1.5 ⭐ from 99 UAE reviews** with payment-failure complaints in the wild. Every named customer is inside the Eltizam / IHC / ADQ family — there's no public evidence of arms-length open-market wins.

For Atriom's Egyptian mall positioning, the implications are:
- PropEzy is invisible on the channels operators actually consult — Atriom doesn't have a brand-awareness fight to win
- PropEzy's reliability gap (broken registration, slow loads, payment-recording failures) is publicly visible
- PropEzy's pricing is per-residential-unit — **doesn't map to mall lease/tenant economics**
- PropEzy has **no Arabic / RTL support** in any directory listing despite the MENA target — a regulatory + UX hole for Saudi government communities and Egyptian mall operators
- PropEzy's Egypt presence was announced Q3 2022; **no Egypt-specific customer wins are publicly verifiable**

The rest of this doc unpacks each of those findings with citations.

---

## 1. Company + product context

| Attribute | Detail |
|---|---|
| **Product** | PropEzy ([propezy.com](https://propezy.com), staging mirror: [propezy-dd81a30e1637af986-6f340b515381f.webflow.io](https://propezy-dd81a30e1637af986-6f340b515381f.webflow.io/)) |
| **Vendor** | EAST-O Holdings — technology division of Eltizam Asset Management |
| **Parent (post-merger)** | Aldar Estates (Aldar / IHC / ADNEC merger, July 2023) — see [The National](https://www.thenationalnews.com/business/2023/07/04/aldar-ihc-adnec-to-create-largest-middle-east-property-and-facilities-management-entity/) |
| **HQ** | Level 17, Tamouh Tower, Abu Dhabi (ADGM-registered) |
| **Founded** | 2019 per LinkedIn / publicly launched 2021–2022 (inconsistent across sources) |
| **Employees** | 11–50 (LinkedIn) |
| **LinkedIn followers** | 1,263 — modest organic engagement for a 4-year regional product |
| **Live markets** | UAE (primary), KSA, Egypt, Oman |
| **Capterra listed languages** | English only |
| **Deployment** | Cloud SaaS only — no on-prem option |
| **Hosting region** | Not publicly disclosed [unverified] |
| **Self-reported scale** | 150+ communities · 30,000+ homes and offices · 3,500+ monthly service requests · 10,000+ monthly amenity bookings |

**Three product lines** (sold standalone or integrated):

| Product | Target | Use cases |
|---|---|---|
| **PropEzy Community** | Residential communities, mixed-use | HOA / community ops, amenity booking, visitor mgmt, resident communications |
| **PropEzy Property** | Residential + commercial leasing | Lease lifecycle, rent collection, maintenance, accounting via SAP |
| **PropEzy Workplace** | Corporate workplaces, coworking | Desk/room booking, access control, badge mgmt, Outlook calendar sync |

Notably **none of the three products is mall-vertical-specific**. PropEzy markets to "commercial real estate" generically; tenant sales declarations, percentage rent, CAM reconciliation, and mall-grade leasing terms (step-rent, anchor tenants, kiosk vs unit distinctions) are **not in any public material**.

---

## 2. Module-by-module breakdown

### 2.1 Property & Asset Management

- **Data model**: Country → Emirate/State → Community → Building → Unit. Unit types exposed in the tenant registration flow: `Apartment`, `Villa`, `Retail`, `Townhouse` ([customer.propezy.com](https://customer.propezy.com/Registration/Registration)).
- **Use cases**: vacancy/occupancy tracking, property-wise accounting + payment processing, CXO portfolio dashboards.
- **Scale**: bulk-managed at ~1 to 10,000+ units (Capterra listing).
- **Limitations**: no public evidence of floor-plan visualisation, BIM ingestion, or bulk CSV/XLSX import flows.

### 2.2 Lease Management

- **Lifecycle**: `draft → e-signature → active → auto-reminder before expiry → renewal | termination`.
- **Quote**: *"Manage lease renewals easily. Track lease expiry, sent out auto reminders"* ([Webflow staging property page](https://propezy-dd81a30e1637af986-6f340b515381f.webflow.io/property)).
- **Sub-features**: digital lease creation with **e-signatures**, lease document storage, automated renewal reminders, **move-in / move-out permit workflows** (exposed to tenants via the PropEzy-PM mobile app — [App Store](https://apps.apple.com/ae/app/propezy-pm/id6446902839)).
- **Limitations**:
  - **No public mention of CPI-linked escalation clauses, percentage-rent for retail/F&B, mid-term break clauses, or step-rent schedules** — a notable gap vs Yardi/MRI for commercial real estate teams
  - **No mall-vertical concepts** (anchor tenants, kiosk-vs-unit, sales density)

### 2.3 Tenant / Resident Management

- **Onboarding flow** (verified live): customer-type selection (Owner / Tenant, Individual / Corporate) → OTP via phone or email (4-digit) → personal details (name, gender, nationality, UAE residency) → property/unit selection → document upload (gif/png/pdf/jpg, **max 3 MB**) → End-User Agreement acceptance. Corporate adds Company Name, Country of Incorporation, Trade Number, Trade Expiry Date.
- **Tenant screening**: advertised on Capterra/GetApp but **workflow depth not described in marketing** — likely document-upload + manual review rather than integrated credit-bureau screening.
- **Limitations**: no references to blacklisting, **KYC vendor integration (UAE Pass, Tahaluf, Jumio)**, or AML screening. No mention of multi-tenant-per-contract handling (anchor tenant subleases, kiosk operators) — again, residential framing.

### 2.4 Maintenance / CAFM (Service Request Management)

- **Ticket intake channels** (the headline feature): **mobile app · call centre · concierge · email** — explicitly tracked as origin on each ticket.
- **Workflow**: created → triaged → assigned → SLA-tracked → resolved → real-time customer feedback.
- **Sub-features**: SLA tracking with escalations, live dashboards, vendor permit issuance (PropEzy's own LinkedIn says *"obtain independent vendor permits in three steps"* — [post](https://www.linkedin.com/posts/propezy_propezy-proptech-propertyapp-activity-7039494525278400512-1s_o)).
- **Photo attachments**: implied by the 3 MB file-upload cap but **not explicitly advertised as a ticket field** [unverified].
- **Limitations**:
  - **No explicit preventive maintenance (PPM) scheduling** in public materials
  - **No asset register** for tracking serviceable equipment
  - **No work-order printing / PDF batch workflows**
  - This is thinner than dedicated CAFM (Archibus, FSI Concept Evolution, MRI ManhattanONE)

### 2.5 Accounting & Finance

- **Advertised sub-features**: ledger, accounts payable/receivable, financial reports, online payments, rent tracking, expense monitoring, payment history (Capterra).
- **Named ERP integration**: **SAP S/4HANA** (only one called out).
- **Limitations** — and this list is the loudest gap for Egyptian mall operators:
  - **No UAE FTA VAT-specific invoicing certification** mentioned
  - **No ZATCA (KSA) e-invoicing Phase 2 compliance** mentioned
  - **No Egyptian ETA e-invoicing** — at all
  - **No multi-currency** support evidence
  - **No IFRS 16 lessor reporting**
  - **No accruals/cash-basis toggles**
  - **No off-the-shelf connectors to QuickBooks, Xero, Oracle NetSuite** — mid-market customers would need custom builds
- This is the single biggest functional gap. For Egyptian buyers, **PropEzy lacks the e-invoicing layer regulators mandate**.

### 2.6 Communications

- **Use cases**: announcements, notices, emergency broadcasts, 1:1 manager-tenant chat.
- **Sub-features**: post-engagement tracking (views, likes, comments), emergency notification system, news & notices via app or email.
- **Channels confirmed**: in-app push, email. **Twilio** named as a comms integration (SMS + voice; possibly WhatsApp via Twilio).
- **Limitations**: **Native WhatsApp Business API is NOT explicitly listed** — only inferable via Twilio. This is a real gap in MENA where WhatsApp is the dominant customer-comms channel.

### 2.7 Amenity Booking (Community module — residential-only)

- **Use cases**: residents reserve pools, gyms, halls, courts.
- **Sub-features**: amenity calendar, digital bookings, cancellations, capacity limits, usage statistics, reviews.
- **Scale**: ~10,000+ bookings/month.
- **Mall relevance**: zero — mall tenants don't book amenities. PropEzy's strength here doesn't transfer to mall vertical.

### 2.8 Visitor / Access Management

- **Sub-features**: QR-code entry, time-restricted guest passes, automatic host notifications, **ANPR (automatic number plate recognition)** for vehicle entry, AI-enabled plate recognition (deployed at ZED Sheikh Zayed per [Arab Finance](https://www.arabfinance.com/en/news/newdetails/4752)).
- **Integrations**: **OnGuard, Lenel.S2, HikVision, Suprema** (enterprise access-control / VMS / biometrics).
- **Note**: biometrics arrives via the third-party integrations, not native to PropEzy.
- **Mall relevance**: secondary — useful for staff entry / parking but not core to mall ops.

### 2.9 Concierge

- Positioned as **one of four origination channels for service requests**, not a separate hospitality module.
- **Limitations**: no booking workflow for concierge-only services (limo, restaurant, dry-cleaning) — thinner than dedicated hospitality-tech competitors.

### 2.10 BI / Reporting

- **Named BI integration**: **Microsoft Power BI** — the single integration listed on both Capterra and GetApp.
- **Dashboards on**: occupancy, move-ins/outs, amenity usage, reported issue trends, problem response/resolution analytics, financial reports.
- **Implications**:
  - Relying on Power BI means report customisation requires a **customer-side BI engineer**
  - **No Tableau, Looker, or Metabase connectors** listed
  - Microsoft-stack lock-in for customers wanting deep analytics

### 2.11 Authentication / RBAC

- **Five role-tailored interfaces**: CXO, portfolio manager, property manager, leasing manager, maintenance manager.
- **AD/LDAP**: available via the workplace access-control integrations (OnGuard, Lenel.S2).
- **Limitations**: **no SAML 2.0 SSO, no Okta / Azure AD / Microsoft Entra ID / OIDC, no MFA enforcement at admin level** mentioned [unverified]. Granular per-property RBAC is not described.

### 2.12 Multi-language / RTL

- **Capterra-listed languages: English only.**
- **No Arabic, no RTL** in any public material despite the MENA target.
- This is a regulatory + UX hole for Saudi government-owned communities, Egyptian operators, and any client that needs Arabic tenant communications.

### 2.13 Deployment

- **Cloud-only SaaS** — Web + iOS + Android.
- **No on-prem option** mentioned.
- **No public data-residency commitment** (could block KSA government deployments).

---

## 3. Integrations summary

| Category | Named integration | Notes |
|---|---|---|
| ERP / Finance | **SAP S/4HANA** | Only ERP listed |
| BI / Analytics | **Microsoft Power BI** | Only BI listed; Microsoft lock-in |
| Communications | **Twilio** | SMS / voice; WhatsApp likely via Twilio not native |
| Calendar | **Microsoft Outlook** | Meeting-room booking sync (workplace module) |
| Access control / VMS | **OnGuard · Lenel.S2 · HikVision · Suprema** | Enterprise / biometrics |
| Vehicle access | **ANPR** + AI plate recognition | Deployed at ZED Sheikh Zayed |
| IoT | Air-quality + foot-traffic sensors | Generic; not productised as a module |
| **Identity / SSO** | **None named** | [unverified — likely a gap] |
| **Payment gateway** | **None named publicly** | Surprising for MENA — Telr, Tap, PayTabs, Network International are table-stakes |
| **Accounting (QB / Xero / NetSuite)** | **None named** | Custom builds required |
| **Public API** | Listed as a feature but no developer portal, OpenAPI spec, or rate-limit doc public | Marketing-feature without engineering evidence |
| **Tax authority e-invoicing** (UAE FTA / KSA ZATCA / EG ETA) | **None named** | Single biggest gap for Egyptian buyers |

---

## 4. Mobile apps

### 4.1 PropEzy-PM ("Property Manager" — but actually tenant-facing)

- iOS + Android, developer Eltizam Asset Management
- Tenant-facing despite the "PM" naming (a marketing misstep)
- **Features**: view lease details, request move-in/move-out permits, submit service requests + feedback, book amenities, read news/notices
- iOS 10.0+, current version **1.3 (Nov 2023)** — **not updated in ~2.5 years**
- Age rating 16+, English only
- App Store rating: 5.0 (1 review) — statistically meaningless

### 4.2 PropEzy-CM ("Community Manager") — the real consumer app

- iOS + Android, version 2.1.0 (Dec 2025), 83.1 MB, English-only
- **Features**: community news, service-charge bill viewing + payment, service requests, document centre, issue reporting, used-items marketplace, retail offers, events, direct-message manager
- Open only to pre-registered communities
- **App Store ratings**:
  - US: **1.7 ⭐** (6 reviews)
  - **UAE: 1.5 ⭐ (99 reviews)** — the most credible local-market signal
- **Google Play (com.easto.propezy)**: **3.0 all-time / 1.4 recent** across ~130 reviews
- Recent-rating collapse from 3.0 → 1.4 means user experience is **getting worse, not better**

### 4.3 What's missing

- **No dedicated property/leasing-manager mobile app** — property managers operate via web role-tailored interfaces only
- The "PropEzy-PM" naming creates buyer confusion in head-to-head shopping

---

## 5. Pricing

| Tier | Price | Min commit |
|---|---|---|
| Monthly | **$2 / unit / month** | 250–300 units min |
| Annual | **$1.50 / unit / month** (25% off) | 250–300 units min |
| Workplace | Custom-quoted | Enterprise |

- Anchored on a **per-unit residential price** — doesn't map to mall economics where the unit of value is the lease or the tenant
- Vendor data is **inconsistent**: GetApp says "free trial available", Capterra says "free trial not available" — sloppy GTM
- "No upfront CAPEX" repeatedly emphasised — implying month-to-month is possible

---

## 6. Customer base — and the captive-customer concern

**Named launch partners** (per public press + their site):
- **Kingfield Owners Association Management Services** (rebranded to **Asteco** 2024)
- **Omnius Property Management**
- **OrionTEK Innovations** (security/access control — sister Eltizam company)
- **Inspire Integrated**
- **Colliers** (MENA arm)
- **Next 50**
- **ADQ**
- **Three60 Communities**

**The pattern**: every named customer is inside the **Eltizam / IHC / ADQ corporate family**. There's no publicly verifiable arms-length, open-market win.

**Scale anchor**: one unnamed customer processes ~6,000 monthly transactions; Aldar Estates (the post-merger destination) manages ~155,000 residential units + 2M sqm GLA — but only some portion uses PropEzy.

**Egypt presence**: announced Q3 2022 with the Ora Developers ZED Sheikh Zayed deployment ([Arab Finance](https://www.arabfinance.com/en/news/newdetails/4752)). **No additional Egypt-specific customer wins have surfaced publicly since.**

---

## 7. Reported user feedback (verbatim, where available)

### Recurring complaints from mobile-app reviews

**Registration / onboarding broken** (UAE App Store, Google Play):
> *"It has been a month since I am trying to register, every time I get error messages."*
>
> *"The form gets stuck at the beginning when you choose country."*
>
> *"I have never used this App before and when I tried to register it says my email is already registered."*

**Performance**:
> *"Application is very slow on any connection. Requesting any service is impossible."*
>
> *"Slowest and most inaccurate app I have ever used, since the creation of Android itself."*

**Payment failures (most serious)**:
> *"Nothing works on this disgrace of an excuse for an app. Most notably it takes your money for the payment of cooling bills…but doesn't show that your bill has been paid."* — UAE App Store, June 2024

**Auth / session bugs**:
> *"In case of any back space it will log off and start from the beginning."*
>
> *"This app was updated yesterday. Now, when I log in I get a message telling me to get the updated version, but there is no update available."*

**Support responsiveness**:
> *"Support email is not managed and they never respond to emails."*

### The few positive notes

> *"A great improvement for our community. Now everything is online and available 24/7. Great overall look and feel."* — Apple App Store US, December 2021 (pre-decline)

### Review-platform absence

| Platform | PropEzy reviews |
|---|---|
| Capterra | **0** |
| GetApp | **0** |
| Software Advice | **0** |
| G2 | **0** |
| TrustRadius | not listed |

For a 4-year-old MENA proptech with 30,000+ homes managed, **zero verified third-party reviews** is a louder signal than most positive scores would be.

---

## 8. Strategic implications for Atriom

### Where PropEzy is genuinely strong (don't fight here)

- **Visitor / access control integration depth** — OnGuard, Lenel.S2, HikVision, Suprema integrations are real engineering. Don't build this; partner if a mall needs it.
- **Amenity booking** — irrelevant to mall vertical, so this is a non-issue.
- **Power BI integration** — Microsoft-stack customers like the embedded BI story. Atriom counters with built-in reports (Monthly Close PDF, AR Aging drilldown) so customers don't need a separate BI engineer.

### Where PropEzy is weak (and Atriom has answers)

| PropEzy gap | Atriom answer |
|---|---|
| **No Egyptian ETA e-invoicing** | Atriom ships the ETA module with mock + live submission, dashboard compliance widget |
| **No mall-vertical concepts** (anchor tenants, sales density, percentage rent, CAM) | Atriom's Tenant Sales Declaration + CAM Reconciliation + percentage-rent formulas are the moat |
| **English only — no Arabic / RTL** | Atriom is Arabic-native with mPDF shaping + bidi |
| **No WhatsApp Business API native** | Atriom architected for WhatsApp, gated on Meta/BSP credentials |
| **No public payment gateway integrations** | Atriom architected for Paymob (card / InstaPay / wallets), Egypt-relevant |
| **Per-unit pricing doesn't fit malls** | Atriom prices per-property (or per-tenant), aligned with mall economics |
| **Reliability gaps publicly visible** (1.5 ⭐ UAE, payment failures) | Atriom emphasises uptime, audit trail, idempotent billing, Pest test coverage |
| **Captive customer base, weak open-market evidence** | Atriom has its first open-market customer (Haya Walk / Jawad) and Eltizam pitch in play |

### Positioning recommendations for the Eltizam pursuit

1. **Don't position as a PropEzy replacement** — position as the **Egyptian mall specialist**. Eltizam's Tafawuq Egypt + Three60 Egypt teams need ETA, percentage rent, Arabic, EGP — all things PropEzy doesn't ship for the Egyptian market.
2. **Lead with regulatory specificity** — ETA / VAT / Arabic. These are the most credible "we get Egypt, they don't" claims.
3. **Show reliability numbers** — 256 Pest tests, ~5 s parallel runtime, CI on every push. PropEzy's public reviews show reliability gaps.
4. **Don't claim feature parity on workplace/community modules** — Atriom is mall-vertical-deep, not platform-broad. Stay focused; partner where needed.
5. **The captive-customer concern is leverage** — when Eltizam considers their internal PropEzy deployment vs an external partner, the question to surface is: *"Do you want an in-house tool serving Eltizam, or a vertical specialist that also serves Eltizam?"* Different answer in each case.

### What we should NOT build (PropEzy strengths that don't matter for malls)

- Amenity booking / community calendar
- Visitor access integrations (OnGuard, Lenel.S2 — these are 6-figure projects)
- Move-in / move-out workflows (residential concept)
- Power BI embedded analytics
- Workplace desk booking

These are PropEzy moats but **irrelevant to mall ops**. Building them would dilute Atriom's vertical specialisation without winning customers.

---

## Sources

### Vendor + product
- [PropEzy main site (when reachable)](https://propezy.com)
- [PropEzy Webflow staging — Home](https://propezy-dd81a30e1637af986-6f340b515381f.webflow.io/)
- [PropEzy Webflow staging — Property](https://propezy-dd81a30e1637af986-6f340b515381f.webflow.io/property)
- [PropEzy Webflow staging — Community](https://propezy-dd81a30e1637af986-6f340b515381f.webflow.io/community)
- [PropEzy Webflow staging — Workplace](https://propezy-dd81a30e1637af986-6f340b515381f.webflow.io/workplace)
- [PropEzy Webflow staging — Pricing](https://propezy-dd81a30e1637af986-6f340b515381f.webflow.io/pricing)
- [PropEzy customer registration flow](https://customer.propezy.com/Registration/Registration)
- [PropEzy LinkedIn company page](https://www.linkedin.com/company/propezy/)

### Review platforms
- [Capterra — PropEzy listing](https://www.capterra.com/p/10007350/PropEzy-Property-Manager/)
- [GetApp — PropEzy listing](https://www.getapp.com/real-estate-property-software/a/propezy-property-manager/)
- [Software Advice — PropEzy profile](https://www.softwareadvice.com/property/propezy-property-manager-profile/)
- [G2 — PropEzy alternatives page](https://www.g2.com/products/propezy-property-manager/competitors/alternatives)
- [Capterra.ca — PropEzy](https://www.capterra.ca/software/1046487/propezy-property-manager)
- [SoftwareWorld — PropEzy Community reviews](https://www.softwareworld.co/software/propezy-community-reviews/)

### Mobile apps
- [App Store AE — PropEzy-PM](https://apps.apple.com/ae/app/propezy-pm/id6446902839)
- [App Store US — PropEzy-CM](https://apps.apple.com/us/app/propezy-cm/id1567878446)
- [App Store UAE — PropEzy-CM](https://apps.apple.com/ae/app/propezy-cm/id1567878446)
- [Google Play — PropEzy-CM](https://play.google.com/store/apps/details?id=com.easto.propezy)
- [Chrome-Stats — PropEzy-CM reviews aggregation](https://chrome-stats.com/d/com.easto.propezy/reviews)
- [APK Geek — PropEzy-CM verbatim reviews](https://apkgk.com/com.easto.propezy)

### Press + market context
- [CM-Today — EAST-O Holdings launches PropEzy](https://www.cm-today.com/news/proptech/east-o-holdings-launches-propezy-an-integrated-proptech-platform)
- [Zawya — EAST-O Holdings press release](https://www.zawya.com/en/press-release/companies-news/east-o-holdings-disrupts-real-estate-management-in-mena-launching-an-integrated-proptech-platform-xty4v1gc)
- [Zawya — Aldar / IHC / ADNEC merger](https://www.zawya.com/en/press-release/companies-news/aldar-ihc-and-adnec-group-create-regions-largest-property-and-facilities-management-company-mr9n51v3)
- [The National — Largest ME property/FM entity](https://www.thenationalnews.com/business/2023/07/04/aldar-ihc-adnec-to-create-largest-middle-east-property-and-facilities-management-entity/)
- [Gulf Business — How PropEzy is disrupting proptech](https://gulfbusiness.com/how-propezy-is-disrupting-the-proptech-sector-with-innovative-solutions/)
- [ME Construction News — PropEzy case study ($2/unit pricing)](https://meconstructionnews.com/54041/the-future-of-real-estate-management)
- [Arab Finance — PropEzy / Ora Developers ZED Sheikh Zayed](https://www.arabfinance.com/en/news/newdetails/4752)

### LinkedIn signals
- [LinkedIn post — vendor permits feature](https://www.linkedin.com/posts/propezy_propezy-proptech-propertyapp-activity-7039494525278400512-1s_o)
- [LinkedIn post — "UAE #1 Property Management Software" claim](https://www.linkedin.com/posts/propezy_best-property-management-software-in-uae-activity-7104753864645464064-f1dJ)
