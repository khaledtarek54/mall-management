# Master Plan — Eltizam Partnership Pursuit

> **Audience:** Internal team. Strategy + sprint plan + competitive read for the Eltizam Asset Management Group pursuit.
> **Status:** Meeting not yet booked; quality of materials matters more than calendar speed.
> **Revision:** V2 — pivoted from generic "we beat them" framing to "complementary mall-specialist alongside PropEzy" framing after correctly identifying Eltizam's real platform.
> **Last updated:** 2026-05-23

---

## 1. Context & how this doc relates to the others

| Doc | Purpose | Stays in scope? |
|---|---|---|
| [FEATURES.md](FEATURES.md) | Source of truth for what's built | ✓ Don't duplicate the built-features list here — link |
| [DEMO.md](DEMO.md) | 10-minute live-demo script | ✓ Keep; Eltizam version is an overlay, not a rewrite |
| [PROPOSAL.md](PROPOSAL.md) | Jawad-direct commercial pitch | ⚠ Superseded for the Eltizam pursuit; commercial skeleton still useful |
| **MASTER-PLAN.md** (this) | Eltizam strategy + sprint plan + competitive position | — |

**What's shipped that V1 of this plan treated as a build:**
- **Maintenance / CAFM module** — model, admin + portal resources, polymorphic comments, SLA config in [`config/maintenance.php`](config/maintenance.php), seeded data, [`MaintenanceRequestService`](app/Services/MaintenanceRequestService.php). See [FEATURES.md § Maintenance](FEATURES.md).
- **Multi-property tenancy** — session-based operator switcher in the admin topbar, dynamic brand logo / name / favicon swap, `Operator` model + `operator_id` on Asset + [`CurrentOperatorScope`](app/Models/Scopes/CurrentOperatorScope.php) global scope. Seeded with Jawad Developments + Demo Operator. 5 Playwright specs cover tenancy isolation + branding swap. 52/52 specs green.

These two changes free ~10 working days versus the original V1 schedule and unlock the architectural story for the demo (one panel, multiple white-labeled operators, with retail-specific workflows).

---

## 2. Competitive read — PropEzy is the real platform

V1 of this plan treated Omnius as the head-to-head. Wrong frame. **Eltizam's real software product is [PropEzy](https://www.propezy.com/), built by EAST-O Holdings (Eltizam Group's tech division).** Omnius is a services-layer brand; PropEzy is the cloud SaaS.

### 2.1 Eltizam Group structure (verified)

Four holding companies, four roles:

| Holding | Role | Examples |
|---|---|---|
| **EAMG Holdings** | Asset-management services | Omnius (UAE-deployed) |
| **iREC Holdings** | Real-estate brokerage | RE/MAX 360 |
| **iFM Holdings** | Facilities + community services | Tafawuq, Three60 (both with Egypt JVs via Al Ahly Sabbour) |
| **EAST-O Holdings** | **Technology — owns PropEzy + OrionTEK** | PropEzy SaaS |

The services subsidiaries run on PropEzy. PropEzy is the unified operational stack.

### 2.2 PropEzy facts (cited, not asserted)

| Attribute | Detail | Source |
|---|---|---|
| Product | Cloud SaaS, three modules: Community / Workplace / Property | [propezy.com/community](https://www.propezy.com/community), [propezy.com/property](https://www.propezy.com/property) |
| Parent | EAST-O Holdings (Eltizam Group's tech arm) | [Zawya press release](https://www.zawya.com/en/press-release/companies-news/east-o-holdings-disrupts-real-estate-management-in-mena-launching-an-integrated-proptech-platform-xty4v1gc) |
| Founded / launched | Founded 2019, UAE launch 2022, Egypt entry Q3 2022 | [CM-Today coverage](https://www.cm-today.com/news/proptech/east-o-holdings-launches-propezy-an-integrated-proptech-platform) |
| Leadership | Uros Trojanovic, Divisional CEO, EAST-O Holdings | [Egypt Facility Management Forum speaker page](https://efmf.me/speaker/uros-trojanovic-23/) |
| Egypt deployment | **Ora Developers — ZED Sheikh Zayed** (delivery H2 2023). Scope: digitize facilities management. Residential mega-project. | [Arab Finance](https://www.arabfinance.com/en/news/newdetails/4752) |
| Mobile apps | PropEzy-CM / PropEzy-PM / PropEzy Workspace on [iOS](https://apps.apple.com/ae/app/propezy-pm/id6446902839) + [Google Play](https://play.google.com/store/apps/details?id=com.easto.propezy) | App stores |
| Base | ADGM Abu Dhabi | EAST-O corporate |

### 2.3 What PropEzy does NOT advertise (verified gaps in public materials)

Light research of propezy.com + press + app-store listings turned up **zero mention** of any of the following:

- **Egyptian ETA e-invoicing** — Egypt's mandatory tax-authority submission system. Not mentioned anywhere.
- **Arabic language UI or Arabic PDF rendering**. Not advertised; app store listings English-only.
- **Mall-specific features** — no CAM reconciliation, no percentage rent, no tenant sales reporting, no anchor-tenant analytics.
- **Paymob / InstaPay / Egyptian payment rails** — no Egyptian payment-gateway integration mentioned.
- **Retail vertical case studies** — Ora's deployment is residential (ZED is a residential mega-project, not a mall).

This is the strategic air. PropEzy is a generalist proptech platform built in UAE, deployed once in Egypt for residential. **We can be the Egyptian retail/mall specialist that runs alongside it.**

### 2.4 What we own (the wedges)

1. **Egyptian retail vertical specialization** — CAM, percentage rent, tenant sales declarations, anchor performance. Not in PropEzy.
2. **ETA-native architecture** — `eta_submission_id` / `eta_submitted_at` / `eta_response` columns already on Invoice; just need credentials.
3. **mPDF Arabic shaping + bidi** — DomPDF (the common default) emits broken Arabic. See [resources/views/invoices/pdf.blade.php](resources/views/invoices/pdf.blade.php).
4. **Egypt-first defaults** — EGP, DD/MM/YYYY, EG VAT (rent exempt, service 14%), Arabic — engineered in, not retrofitted.
5. **White-label flex** — multi-property tenancy already shipped; one codebase, multiple operator brands.
6. **Egyptian iteration speed** — days, not UAE-team quarters.

---

## 3. The strategic positioning — complementary, not competitive

### Master statement (the one-liner)

> *"PropEzy is excellent for community management, residential, and workplace properties. We're a complementary specialist tool for Egyptian retail and mall operations specifically. Where PropEzy is 'all-in-one,' we're 'best-in-one' for the mall vertical."*

### Three positioning pillars

1. **Specialization beats generalization.** PropEzy covers community + workplace + property in 3 countries. We optimize one vertical (Egyptian malls) harder than a generalist can.
2. **Egyptian-native, not UAE-adapted.** PropEzy was built in UAE and brought to Egypt. We were built in Egypt from day one. The difference shows in ETA compliance, Arabic rendering, payment rails, and how Egyptian malls actually operate.
3. **Partnership, not replacement.** Eltizam can offer developer clients an "all-stack" solution: PropEzy for residential / workplace, us for retail. They get vertical coverage without UAE team cycles spent building mall-specific features that don't serve their core residential market.

### Language discipline in the meeting

| Don't say | Say instead |
|---|---|
| "We're better than PropEzy" | "PropEzy is impressive for community management" |
| "PropEzy doesn't work for malls" | "Malls have specialized requirements" |
| "You should replace PropEzy" | "We complement PropEzy in the retail vertical" |

---

## 4. Gap analysis vs PropEzy

Maintenance and multi-property both removed (shipped). What remains:

| Capability | PropEzy | Us | Status |
|---|---|---|---|
| Lease management | ✓ Mature | ✓ Built | Match |
| Invoicing + billing | ✓ Mature | ✓ Built + Arabic PDF | We exceed |
| Tenant portal (web) | ✓ Mature | ✓ Built | Match |
| Audit trail | ✓ Mature | ✓ Built (Spatie ActivityLog on 7 models) | Match |
| RBAC | ✓ Mature | ✓ Built | Match |
| Document mgmt | ✓ Mature | ✓ Built (Spatie MediaLibrary) | Match |
| Maintenance / CAFM | ✓ Mature | ✓ **Built** ([FEATURES.md](FEATURES.md)) | Match |
| Multi-property tenancy | ✓ Mature | ✓ **Built** (session switcher + dynamic brand) | Match |
| Reporting / BI widgets | ✓ Mature | ✓ Built (10 widgets) | Roughly match |
| Occupancy mapping | Generic | Visual grid | We exceed |
| **Egyptian ETA e-invoicing** | ❌ Not advertised | ⚙ Architected — needs creds | Big differentiator once activated |
| **Arabic-native UI + PDF** | ❌ Not advertised | ✓ Native shaping + 685-line lang files | We exceed |
| **Mall-specific (CAM, % rent, tenant sales)** | ❌ Not advertised | 🚧 To build (Module E + F below) | **Our headline moat** |
| **Egyptian payment rails (Paymob, InstaPay)** | ❌ Not advertised | ⏸ Stubbed | Credential-blocked |
| **Vendor management** | Generic | Not built | Gap — Week 1 build |
| **Owner portal** | ✓ Mature | Not built | Gap — Week 2 build |
| **Energy management** | ✓ Mature | Not built | Gap — Week 3 stub |
| Tenant mobile app | ✓ iOS + Android | None | Defer — counter with WhatsApp |
| White-label | Limited | ✓ Multi-operator + dynamic brand | We exceed |
| Iteration speed | UAE-controlled | Egyptian local | We exceed |

**Five gaps to close before the meeting:** Tenant Sales Declaration (NEW — mall moat), CAM Reconciliation stub (NEW — mall moat), Vendor management, Owner portal, Paymob + ETA activation.

---

## 5. The 3-week sprint plan (revised)

Capacity available because maintenance + multi-property are shipped. Each week ends with Playwright specs added and translation keys in both [`lang/en/admin.php`](lang/en/admin.php) + [`lang/ar/admin.php`](lang/ar/admin.php).

### Week 1 — Mall-specific moats (Tenant Sales + CAM) + Vendor management

**Days 1-4: Tenant Sales Declaration** (THE differentiator vs PropEzy)
- Migration: `TenantSalesDeclaration` (`lease_id`, `period_start`, `period_end`, `declared_sales`, `declared_at`, `declared_by_type` + `declared_by_id` polymorphic, `status` enum [`draft` / `submitted` / `locked` / `disputed`], `locked_at`, `audit_notes`, soft deletes).
- Migration: `LeasePercentageRent` (`lease_id`, `threshold_amount`, `rate_percent`, `calculation_type` enum [`natural_breakpoint` / `artificial`], `effective_from`, `effective_to`).
- Tenant portal: monthly sales submission form (numeric + optional PDF receipt upload via existing MediaLibrary pattern).
- Admin: review queue → review → lock → audit. Status badges. Filter by period + asset.
- Service: `PercentageRentCalculationService` — given a locked declaration + lease's `LeasePercentageRent` row, generates a percentage-rent Charge auto-linked into next monthly billing run.
- Dashboard widget: "Tenant Sales — submission status" (admin) + "Submit Sales" (tenant portal).
- Seed: ~3 months of historic declarations for Haya Walk leases to make the workflow demo-ready.

**Days 5-6: CAM Reconciliation (basic structure)**
- Migration: `CamExpensePool` (`asset_id`, `period_year`, `total_actual_expense`, `total_estimated_collected`, `status`).
- Migration: `CamAllocation` (`pool_id`, `lease_id`, `allocated_amount`, `cap_amount`, `exclusions` JSON, `status`).
- Admin Resource showing the pool + allocations. Show the structure even if annual true-up logic is deferred.
- Visible-but-shallow: enough to point to in the demo as "Egyptian malls reconcile CAM annually; here's the model — we'll automate the true-up in Q2."

**Day 7: Vendor management**
- Migrations: `Vendor` (name, category, contact_email, contact_phone, tax_id, vat_id, status, rating, metadata), `VendorContact` (vendor_id, name, role, email, phone, is_primary), `VendorContract` (vendor_id, asset_id, service_type, start_date, end_date, monthly_value, sla_terms, status).
- `VendorResource` under Operations nav. Use [`RoleGatedActions`](app/Filament/Admin/Resources/Concerns/RoleGatedActions.php).
- Add `assigned_to_vendor_id` to `maintenance_requests` so triage can route to vendors.
- Seed 10 realistic Egyptian vendors (HVAC, plumbing, security, cleaning, electrical, fire safety).

### Week 2 — Owner portal + Paymob activation

**Days 8-10: Owner portal**
- New Filament panel at `/owner`. New `OwnerPanelProvider`.
- `Owner` model with M2M to Asset via `asset_owner` pivot.
- Read-only resources: Property Performance, Financial Summary, Occupancy Trends, Maintenance Activity, **Tenant Sales summary**.
- Reuse the multi-operator brand swap from the admin panel — owners see their portfolio branded.

**Days 11-13: Paymob activation**
- Already kicked off externally (sandbox merchant signup in flight).
- `PaymentInitiationController` + `PaymobWebhookController` with HMAC signature verification.
- Wire portal Pay Now button (already gated by `PAYMOB_ENABLED` in [config/integrations.php](config/integrations.php)).
- Flip `PAYMOB_ENABLED=true` after webhook round-trips on sandbox.

**Day 14: i18n + Playwright pass.**

### Week 3 — ETA test + Energy stub + sales materials

**Days 15-17: ETA test environment** (HIGHEST strategic value — the direct moat)
- Already kicked off externally (ETA test cred application in flight).
- `EtaJsonBuilder` service: serialize Invoice + InvoiceItems to ETA JSON shape.
- `EtaApiClient` + signing service.
- `EtaSubmissionJob` (queued): POST to ETA test endpoint, store response in already-migrated `eta_response` column.
- ETA status badge on invoice list (Submitted / Accepted / Rejected / Pending).
- If credentials don't land in time: ship a mocked end-to-end demo where the JSON is built and submitted to a stub endpoint, and a fake response populates. Show the architecture even if the live integration isn't there yet.

**Days 18-19: Energy stub** (deliberately light)
- Migrations: `UtilityMeter` (asset_id, unit_id, meter_number, type [electric/water/gas], provider, status), `MeterReading` (meter_id, reading_value, reading_date, consumption, cost).
- Filament Resource + "Energy consumption by month" chart widget.
- Seed realistic meter data for Haya Walk units. Don't build optimization workflows — defer to roadmap.

**Days 20-21: Sales materials.** See § 7.

---

## 6. Module build specs — reuse what's there

| Module | Pattern to reuse |
|---|---|
| **Tenant Sales Declaration** | Polymorphic `declared_by` mirrors the [`MaintenanceRequestComment`](app/Models/MaintenanceRequestComment.php) polymorphic-author pattern. PercentageRentCalculationService follows the [MonthlyBillingService](app/Services/MonthlyBillingService.php) shape. Tenant-portal form via Filament `Resources/MaintenanceRequests/Pages/CreateMaintenanceRequest.php` template. |
| **CAM Reconciliation** | Two-table parent-child like Invoice → InvoiceItem. Status enum like Invoice status. Defer the auto-true-up service; v1 is admin-managed. |
| **Vendor + VendorContact + VendorContract** | Same shape as Tenant + (yet-to-exist tenant contacts). Resource pattern from [`LeaseResource`](app/Filament/Admin/Resources/Leases/LeaseResource.php). |
| **Owner portal** | Clone the Portal panel structure ([app/Filament/Portal/](app/Filament/Portal/)). Read-only resources, `auth('owner')` scoping. Reuse multi-operator brand swap. |
| **UtilityMeter / MeterReading** | Standard parent-child Eloquent. Resource pattern from Charge / Lease. |
| **PaymobWebhookController** | Use [`LeaseRenewalService`](app/Services/LeaseRenewalService.php) and [`MaintenanceRequestService`](app/Services/MaintenanceRequestService.php) as service-layer templates — thin controller, business logic in service. |
| **EtaJsonBuilder / EtaApiClient** | Mirror [`InvoicePdfService`](app/Services/InvoicePdfService.php) shape — single-responsibility, DI-injected. |
| **Any new model needing uploads** | Spatie MediaLibrary `HasMedia` — same as Lease / Tenant / MaintenanceRequest. |
| **Any new model needing audit** | Spatie `LogsActivity` — same whitelist + dirty-only config as the existing 7 audited models. |
| **Any new resource needing permissions** | [`RoleGatedActions`](app/Filament/Admin/Resources/Concerns/RoleGatedActions.php) trait. |

---

## 7. Sales materials — 6 artifacts (PropEzy-aware)

| # | Artifact | Key positioning |
|---|---|---|
| 1 | **Eltizam partnership pitch deck** (12 slides) | Theme: specialization + partnership, not replacement. Acknowledge PropEzy's strength on slide 2; introduce mall-vertical thesis by slide 4. |
| 2 | **Pilot proposal PDF** (1-pager) | Haya Walk, 6 months, defined scope. Lift commercials from [PROPOSAL.md](PROPOSAL.md). |
| 3 | **Architecture diagram** | Visual showing PropEzy and our platform sitting side-by-side under Eltizam's service layer — not stacked, not competitive. Section 8 below sketches it. |
| 4 | **Roadmap document** | Q1: ship now-in-progress sprint. Q2: mobile + advanced analytics + ETA prod + CAM auto-true-up. Q3: IoT hooks + energy full. Q4: AI insights, predictive maintenance. |
| 5 | **Eltizam-tuned demo script** | Overlay on [DEMO.md](DEMO.md). Add: maintenance walkthrough (Tafawuq angle), tenant sales declaration (mall-specific differentiator), owner portal (Eltizam-as-operator), close on partnership framing. |
| 6 | **Pricing one-pager** | Three tiers: our-brand / co-brand / white-label (white-label premium ~30%). |

### Pricing tiers (working numbers — refine before send)

| Tier | Setup | Monthly | Custom dev | Post-pilot |
|---|---|---|---|---|
| Our-brand | 120,000 EGP | 15,000 EGP/mo | 2,000 EGP/hr, 40-hr sprints | 30,000-60,000 EGP/mo per property |
| Co-brand (Eltizam + us) | 150,000 EGP | 18,000 EGP/mo | Same | 35,000-70,000 EGP/mo per property |
| White-label (Eltizam-only) | 180,000 EGP | 22,000 EGP/mo | Same | 45,000-85,000 EGP/mo per property |

Pilot = 6 months at monthly. Pilot value ~210K-330K EGP depending on tier.

---

## 8. Architecture diagram (sketch for the slide)

```
                    ELTIZAM GROUP
                          │
   ┌──────────────────────┼──────────────────────────┐
   │                      │                          │
   EAMG               iFM Holdings              EAST-O Holdings
   (Omnius)         (Tafawuq, Three60)        (PropEzy, OrionTEK)
                                                     │
                                                     ▼
                       SERVICES LAYER                ▼
       (Tafawuq · Three60 · Omnius · Brokerage · OAM)
              │                              │
              ▼                              ▼
        ┌────────────┐                ┌────────────┐
        │  PropEzy   │ ←─ alongside ─→│ Our Platform│
        │            │                │            │
        │ Community  │                │ Egyptian   │
        │ Workplace  │                │ Mall       │
        │ Residential│                │ Specialist │
        └────────────┘                └────────────┘
              │                              │
              ▼                              ▼
        Residents / office          Mall tenants / operators
        Community owners            Mall owners / Eltizam ops
```

The dotted line is the integration story. Same operator hierarchy, different vertical specialization. They don't compete in the same row.

---

## 9. Risks & mitigations — PropEzy-aware

| Risk | Mitigation |
|---|---|
| "Why not just extend PropEzy for malls?" | "By the time PropEzy's UAE team prioritizes Egyptian mall features, we've shipped 5 iterations. Plus we don't make you carry the opportunity cost — your UAE roadmap stays focused on community." |
| "You're PropEzy lite" | Demo the headline mall-only workflows (tenant sales declaration → percentage rent → invoice). PropEzy doesn't have these. Show ETA submission. PropEzy doesn't have this. |
| "We already deployed PropEzy at Ora" | "Ora is residential. Have you deployed PropEzy at an Egyptian mall? — Different vertical, different requirements." |
| White-label-only pressure | Premium tier ready (~30% uplift). Don't discount white-label. |
| ETA credentials delay the moat | Have mocked end-to-end ready. Commit to live demo at follow-up meeting. |
| They show PropEzy mobile app as superior | Counter: WhatsApp Business API is Egypt's dominant tenant channel. Mobile native is Phase 2; WhatsApp is in our roadmap. |
| Demo-day technical failure | Recorded backup video, hotspot tested, phone pre-loaded at `/portal`, dress-rehearsed 3+ times. |

---

## 10. Success metrics + first move

### "Ready for meeting" checklist

- [ ] Tenant Sales Declaration module functional with seeded historic declarations
- [ ] CAM Reconciliation model + admin view visible (full automation deferred)
- [ ] Vendor management functional with 10 seeded vendors + maintenance assignment FK
- [ ] Owner portal accessible at `/owner` with at least one owner login seeded
- [ ] Paymob test transaction completed end-to-end (or scripted mock)
- [ ] ETA test submission successful (or scripted mock)
- [ ] Energy stub showing seeded meter data
- [ ] 12-slide deck with complementary-partnership framing
- [ ] Pilot proposal PDF
- [ ] Architecture diagram (side-by-side with PropEzy, not stacked)
- [ ] Eltizam-tuned demo script — opens with mall-specific workflow, not generic property management
- [ ] Playwright suite green (current: 52 specs; target after this sprint: 75+)
- [ ] Backup demo video recorded

### Post-meeting success (30 days)

- [ ] Follow-up within 24 hours: deck, proposal, demo link, roadmap
- [ ] Working session scheduled within 14 days
- [ ] Written response (yes / no / conditions) within 30 days
- [ ] If yes: pilot scoped + contract drafted

### First move — start here

**Week 1, Day 1: Tenant Sales Declaration module.** Reasons in order:

1. It's the clearest differentiator vs PropEzy — they don't have it (and can't quickly add it without retraining their generalist platform on retail).
2. It's mall-specific — demonstrates the specialization thesis end-to-end.
3. It's demo-impressive: tenant declares sales → percentage rent auto-calculated → next invoice picks it up. One narrative, three screens.
4. It's reasonably scoped: ~4 days for a solid v1.
5. It justifies the entire positioning. Without it the "we're a mall specialist" claim is just words.

Maintenance was Day 1 in V1 (shipped). Multi-property was Day 1 in this doc's previous revision (also shipped). Tenant Sales is now the highest-leverage thing not yet started.
