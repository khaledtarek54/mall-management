# Dashboard Gap Analysis — Atriom vs PropEzy

Date: 2026-05-25
Audience: internal — for the Eltizam pursuit and dashboard roadmap.
Scope: dashboard / analytics layer only. Resource-level features are covered in [MASTER-PLAN.md §4](../MASTER-PLAN.md).

---

## TL;DR

Our dashboard **matches PropEzy on every shared property-management KPI** (occupancy, AR aging, revenue, expiring leases, recent payments) and **exceeds it on mall-specific signal** (CAM reconciliation, tenant sales, ETA compliance badges, Arabic-native KPIs, operator-switcher portfolio view).

The real gaps are **breadth of audiences served** (PropEzy ships per-role dashboards for CXO / portfolio manager / property manager / leasing manager / maintenance manager — we have one), **leasing pipeline visibility** (where in the funnel are deals?), and **mobile dashboard parity** (their PM mobile app shows the same KPIs; ours hasn't been built yet).

Closing those three would put us at full dashboard parity with no remaining differentiator gap.

---

## 1. What we ship today

### 1.1 Admin dashboard — 10 widgets

| # | Widget | Type | Signal |
|---|---|---|---|
| 1 | [`ActionRequired`](../app/Filament/Admin/Widgets/ActionRequired.php) | Inbox cards | Urgent maintenance · SLA breached · overdue invoices · unbilled leases · expiring <30d · expiring <90d · vacant units. Each card deep-links into the filtered resource list. |
| 2 | [`MallStats`](../app/Filament/Admin/Widgets/MallStats.php) | 4 KPI stats | Occupancy % (with mini-trend) · Monthly recurring revenue · Collected this month (with MoM delta + collection-rate color) · Outstanding AR (with overdue count) |
| 3 | [`ArAging`](../app/Filament/Admin/Widgets/ArAging.php) | Bar chart | 5 buckets: current · 1-30 · 31-60 · 61-90 · 90+. Clickable from [Reports → AR Aging detail](../app/Filament/Admin/Pages/ArAging.php). |
| 4 | [`TenantMix`](../app/Filament/Admin/Widgets/TenantMix.php) | Doughnut | Active leases per unit category (retail / F&B / wellness / service / kiosk / office / storage) |
| 5 | [`MonthlyRevenueTrend`](../app/Filament/Admin/Widgets/MonthlyRevenueTrend.php) | Line chart | 12-month billed revenue trend |
| 6 | [`ExpiringLeases`](../app/Filament/Admin/Widgets/ExpiringLeases.php) | Table | Next 90 days of expirations |
| 7 | [`OpenMaintenanceRequests`](../app/Filament/Admin/Widgets/OpenMaintenanceRequests.php) | Table | Open MR queue with priority + SLA |
| 8 | [`TopTenants`](../app/Filament/Admin/Widgets/TopTenants.php) | Table | Highest-paying tenants this period |
| 9 | [`RecentPayments`](../app/Filament/Admin/Widgets/RecentPayments.php) | Table | Last N captured payments |
| 10 | [`EnergyConsumptionTrend`](../app/Filament/Admin/Widgets/EnergyConsumptionTrend.php) | Bar chart | 12-month consumption by meter type (electric / water / gas), stacked |

### 1.2 Owner dashboard — 1 widget
- `PortfolioStats` — Properties count + sqm · Portfolio occupancy · Portfolio MRR · Outstanding AR across all owned assets.

### 1.3 Portal (tenant) dashboard — 2 widgets
- `AccountBalance` — Outstanding balance + next due invoice
- `OpenMaintenance` — Tenant's open requests with status

### 1.4 Dedicated analytics pages (not on dashboard but reachable from it)
- **Reports** ([/admin/reports](../app/Filament/Admin/Pages/Reports.php)) — Monthly close PDF + KPI cards + clickable AR buckets + revenue-by-type table
- **AR Aging detail** ([/admin/ar-aging](../app/Filament/Admin/Pages/ArAging.php)) — Per-bucket invoice listing with days-overdue
- **Occupancy Map** ([/admin/occupancy-map](../app/Filament/Admin/Pages/OccupancyMap.php)) — Visual grid of every unit on every floor, colored by status

---

## 2. What PropEzy ships (verified from public sources)

Sourced from PropEzy's listings on [GetApp](https://www.getapp.com/real-estate-property-software/a/propezy-property-manager/), the [CM-Today launch article](https://www.cm-today.com/news/proptech/east-o-holdings-launches-propezy-an-integrated-proptech-platform), and the [App Store PropEzy-PM listing](https://apps.apple.com/ae/app/propezy-pm/id6446902839). The marketing site at propezy.com blocks bot crawlers (HTTP 503), so this list is based on third-party documentation rather than direct screenshots.

### 2.1 Dashboard & analytics capabilities (per PropEzy marketing)

| Capability | Source |
|---|---|
| **Power BI dashboards** | CM-Today launch article: "a standard suite of reports to manage community finances, plus Power BI dashboards" |
| Analytics on occupancy | CM-Today + GetApp ("Occupancy management") |
| Analytics on move-ins / move-outs | CM-Today: "analytics on occupancy, move-ins/outs" |
| Amenity-usage analytics | CM-Today (community module) — irrelevant to mall vertical |
| Reported-issue trends | CM-Today: "reported issue trends" |
| Payment-channel analytics | CM-Today: "payment channels" analytics |
| Customer ticket origin tracking (mobile / call centre / concierge / email) | CM-Today: "receive and manage customer requests from mobile app, call centre, concierge or email on one platform" |
| Tenant screening + lease tracking | GetApp feature checklist |
| Online payments tracking | GetApp + CM-Today |
| Rent collection / rent tracking | GetApp checklist |
| Transaction history | GetApp checklist |
| Expense tracking | GetApp checklist |
| Reporting & statistics | GetApp checklist (generic) |
| Activity tracking | GetApp checklist (audit) |
| Reminders | GetApp checklist |
| Mobile dashboards | PropEzy-PM iOS + Android apps cover the same data |

### 2.2 Role-tailored interfaces (the headline differentiator they advertise)

From their GetApp listing: **"Dedicated interfaces for CXOs, portfolio managers, property managers, leasing managers, and maintenance managers."**

That's five distinct user-role dashboards out of the box. We have one admin dashboard everyone sees, plus a separate owner-portal dashboard.

### 2.3 Platform-level numbers (PropEzy claimed at launch)
- 55,000+ amenity bookings generated
- 25,000+ service requests processed

These are platform-wide volume metrics, not per-tenant dashboard widgets — but they tell you the scale of operational data PropEzy is built to surface.

---

## 3. Side-by-side comparison

### 3.1 Where we match or exceed

| Dashboard signal | PropEzy | Atriom | Verdict |
|---|---|---|---|
| Occupancy KPI | ✓ widget | ✓ widget + visual map + per-asset rollup | **We exceed** (visual grid is novel) |
| AR aging | ✓ analytics | ✓ 5-bucket chart + dedicated drilldown page + clickable buckets | **We exceed** (drilldown is one click away) |
| Revenue trend | ✓ analytics | ✓ 12-month chart + revenue-by-type breakdown in Reports | **Match** |
| Recent payments / activity | ✓ analytics | ✓ widget + payment-method breakdown | **Match** |
| Lease expirations | ✓ "Reminders" | ✓ widget with 30d / 90d split | **Match** |
| Open maintenance queue | ✓ analytics | ✓ widget with priority + SLA-breach badges | **Match** |
| Maintenance issue trends | ✓ analytics | Partial (status counts, not category time-series) | **Slight gap** |
| Payment-channel analytics | ✓ analytics | Partial (method breakdown in Monthly Close report, not on dashboard) | **Slight gap** |
| Action-required inbox (top of dashboard) | Not advertised | ✓ [`ActionRequired`](../app/Filament/Admin/Widgets/ActionRequired.php) — 7 different action types | **We exceed** |
| **VAT / ETA compliance signal** | Not advertised | ✓ ETA status badges on invoice list, VAT summary in Monthly Close PDF | **We exceed (regulatory)** |
| **CAM reconciliation status** | Not advertised | ✓ Pool variance, per-lease allocation breakdown | **We exceed (mall-specific)** |
| **Tenant sales declarations** | Not advertised | ✓ Resource + locked declarations → percentage rent charges | **We exceed (mall-specific)** |
| **Percentage rent contribution** | Not advertised | Built but not surfaced as a dashboard widget | **We exceed (model), gap on visualization** |
| **Arabic-native dashboard + RTL** | Not advertised | ✓ Every widget + chart labels + tooltips fully bilingual | **We exceed (market fit)** |
| **Operator switcher (portfolio rollup across assets)** | Not advertised | ✓ Session-based switcher rebinds every widget per operator | **We exceed (multi-tenant story)** |
| Energy consumption | ✓ Generic | ✓ 12-month stacked bar by meter type | **Match** |

### 3.2 Where PropEzy exceeds us today

| Signal | PropEzy | Atriom | How big a gap |
|---|---|---|---|
| **Role-tailored dashboards** (CXO / portfolio mgr / property mgr / leasing mgr / maintenance mgr) | ✓ Five distinct interfaces | One admin dashboard for all admin roles; separate owner panel | **Strategic gap** — affects positioning vs Eltizam |
| Move-in / move-out analytics | ✓ Tracked as a workflow stage | Not modelled — we have lease lifecycle but no move-in pipeline | **Medium** — more relevant to residential, but useful for new-lease pipeline |
| Customer ticket origin tracking (channel attribution: mobile / call centre / concierge / email) | ✓ Multi-channel origin field + analytics | One generic "tenant" field; channel not tracked | **Medium** — easy add if it matters for the demo |
| Embedded Power BI / dashboard customization per user | ✓ Power BI integration mentioned | None — widgets are server-rendered, no user-customization | **Low** for now; positions as a "BI suite" play |
| Mobile dashboard parity | ✓ PropEzy-PM iOS + Android | Mobile API shipped (auth only) — no mobile dashboards yet | **Large** — but addressable; the mobile-app roadmap is real work |
| Amenity-booking analytics | ✓ "55,000+ bookings" claim | Not modelled (mall vertical doesn't book amenities) | **Irrelevant** for mall vertical |
| Community engagement metrics | ✓ Community module | Not modelled | **Irrelevant** for mall vertical |

### 3.3 Where PropEzy's claims are silent (potential differentiators)

These are dashboard signals **not advertised** by PropEzy in any public material. They map to real mall-operator needs:

- **Anchor tenant performance** — sales density per anchor, top-performer rank, gross-leasable-area ROI
- **Foot traffic / sales density** — sales per sqm per category, sensor-data integration
- **Tenant churn analysis** — non-renewal rate, churn cohort by category, tenure histogram
- **Operator-level rollups** — when one operator runs 5 malls, a portfolio-level dashboard above the individual-asset view
- **Compliance posture** — what % of invoices are ETA-Valid, what % are submitted, what's stuck

---

## 4. The five gaps that move the needle

Ranked by leverage (effort × demo impact for Eltizam-tier buyers):

### Gap 1 — Role-tailored dashboard variants ⭐⭐⭐⭐
**Why it matters**: This is the one PropEzy claim that *sounds* like a defensible product gap to a CXO. They advertise five role-specific interfaces; we have one.

**What to build**: One admin dashboard skeleton, but the widget set surfaced is filtered by role. Spatie Permission already gates resources — we add a `dashboardWidgets()` method on the role-gate trait so:
- `super_admin` sees everything (current)
- `manager` sees ActionRequired + MallStats + AR Aging + MaintenanceRequests + RecentPayments (operational)
- `viewer` sees MallStats + ArAging + RevenueTrend (read-only KPI view)
- `leasing_manager` (new role) — ExpiringLeases + TenantMix + OccupancyMap shortcut + pipeline widget
- `maintenance_manager` (new role) — OpenMaintenanceRequests + SLA dashboard + vendor performance

**Effort**: ~2 days. New roles in `RolesPermissionsSeeder`, role-aware widget registration in `AdminPanelProvider`.

**Demo impact**: High — directly counters the PropEzy positioning claim.

---

### Gap 2 — Leasing pipeline widget ⭐⭐⭐⭐
**Why it matters**: Where are deals in the funnel? Today our `Lease` model has statuses (draft, pending_approval, active, expired, renewed, terminated, cancelled), but the dashboard treats every lease as binary (active/not). Leasing managers care about **the pipeline between draft and active**.

**What to build**: A funnel-style widget showing counts at each stage with EGP value totals. Click into any stage → filtered lease list. Bonus: time-in-stage metric (median days from `draft` → `active`).

**Effort**: ~1 day. Pure read query against existing `leases` table.

**Demo impact**: High — shows lease ops maturity that PropEzy's published checklist doesn't explicitly cover.

---

### Gap 3 — VAT / ETA compliance widget ⭐⭐⭐
**Why it matters**: ETA e-invoicing is *our* moat. The Reports module shows VAT-collected but the dashboard doesn't surface "out of 200 invoices issued this month, X are ETA-Valid, Y are pending, Z rejected". That's the one signal a CFO looks at first in Egypt.

**What to build**: A 4-tile stats widget — Valid · Submitted · Rejected · Not yet submitted. Click any tile → filtered invoice list. Add an "Submit all pending" bulk-action shortcut.

**Effort**: ~half day. Query against the existing `eta_status` column.

**Demo impact**: Very high for Egyptian buyers — this is the single biggest "we get Egypt, they don't" demo moment.

---

### Gap 4 — Anchor / Top-tenant performance with sales density ⭐⭐⭐
**Why it matters**: Today's `TopTenants` widget ranks by rent paid. Mall operators rank by **sales density** (declared sales ÷ leased sqm). With the Tenant Sales Declaration module already shipped, the data is sitting there.

**What to build**: Either enhance `TopTenants` with a "Sales density (EGP/sqm)" column + sortable, OR add a new `AnchorPerformance` widget surfaced only when 1+ tenants are flagged `anchor` category. Show period-over-period change.

**Effort**: ~1 day. Join `tenant_sales_declarations` × `leases` × `units`.

**Demo impact**: High — it's a mall-vertical KPI PropEzy doesn't talk about.

---

### Gap 5 — Channel attribution on maintenance & communications ⭐⭐
**Why it matters**: PropEzy advertises "from mobile app, call centre, concierge or email on one platform." Our `MaintenanceRequest` has a `submitted_by` (tenant/staff) but no **channel** field. Our `Note` model already has `channel` (call/whatsapp/email/meeting/site_visit/other) — that pattern just needs extending to MR.

**What to build**: Add `channel` enum to `maintenance_requests` (portal · whatsapp · phone · email · walk_in · admin). Backfill seed. Add a small "Channel breakdown" pie to the open-maintenance widget. Mirror on the Note model timeline (already there).

**Effort**: ~half day. Migration + 1 widget enhancement.

**Demo impact**: Medium — directly addresses a PropEzy advertised capability.

---

## 5. Anti-recommendations (don't waste cycles)

Avoid building these unless an Eltizam stakeholder asks for them by name. They're PropEzy strengths that **don't fit the mall vertical**:

- **Amenity booking dashboards** — Mall tenants don't book amenities. Community module problem.
- **Move-in / move-out workflow tracking** — Residential concept. Malls have lease commencement (already modelled) and termination (already modelled).
- **Concierge ticket integration** — Hospitality concept. Mall maintenance flows through one operations team.
- **Per-user widget customization (drag-and-drop)** — Filament 4 doesn't ship this out of the box. Would be 2-3 weeks of Livewire work for marginal value.
- **Embedded Power BI** — Tells a "BI suite" story. We're "mall ops, sharper than a BI suite" — different positioning.

---

## 6. Recommended next dashboard sprint

If we ship gaps 1, 2, 3 (role-tailored, leasing pipeline, ETA compliance widget) in one batch — call it ~4 working days — the dashboard parity story flips fully in our favor for the Eltizam pursuit. Gap 4 (sales density on TopTenants) is the natural follow-on; gap 5 (channel attribution) is a polish pass.

**Suggested batch order**:
1. ETA compliance widget (half day, immediate Egypt-buyer wow)
2. Leasing pipeline widget (1 day, leasing-manager wow)
3. Role-tailored dashboard variants (2 days, neutralizes PropEzy positioning claim)
4. → Demo update + screenshots
5. Sales density on TopTenants (1 day, mall-vertical depth)
6. Channel attribution on MR (half day, polish)

Total: ~5 working days for full dashboard parity + clear differentiation in the demo.

---

## Appendix: sources

- [PropEzy listing on GetApp](https://www.getapp.com/real-estate-property-software/a/propezy-property-manager/) — feature checklist
- [CM-Today launch coverage](https://www.cm-today.com/news/proptech/east-o-holdings-launches-propezy-an-integrated-proptech-platform) — product modules + analytics list
- [PropEzy-PM on Apple App Store](https://apps.apple.com/ae/app/propezy-pm/id6446902839) — mobile dashboard scope
- [Zawya press release on EAST-O launch](https://www.zawya.com/en/press-release/companies-news/east-o-holdings-disrupts-real-estate-management-in-mena-launching-an-integrated-proptech-platform-xty4v1gc) — strategic positioning
- [Capterra PropEzy Property Manager listing](https://www.capterra.com/p/10007350/PropEzy-Property-Manager/) — supplementary feature list
- propezy.com main site returned HTTP 503 to direct fetches on 2026-05-25 — review-site coverage substitutes
