# Functional Requirements — Department-Oriented ERP (Requests, Marketing, Operations)

> Date: 2026-06-24
> Owner: Khaled (gpt@getpayin.com)
> Status: 🟡 Draft v2 — primary requirements captured, ERP/department framing, open items flagged
> Scope: ERP org model · access & roles · departments · request workflows · tenant registration & users · units & leases · marketing budget · accounting hub
> Reconciled against: `app/Models`, `database/migrations`, `app/Filament`, `app/Notifications`, `app/Settings` as of 2026-06-24

## 0. How to read this

This document turns a set of primary business requirements into traceable functional requirements (FRs) for **Atriom** (the platform). The goal is an **extensible, maintainable, ERP-style** design organized around **departments**. Every FR is tagged against the *current* codebase:

| Tag | Meaning |
|---|---|
| 🟢 `[EXISTS]` | Already implemented — reuse as-is. |
| 🟡 `[EXTEND]` | Implemented but needs a change/addition. |
| 🔵 `[NEW]` | No code today — net-new module/field/table. |
| 🔧 `[design]` | Architecture/design constraint, not an implementation status. |
| ⏸️ `[DEFERRED]` | Out of current scope; pending external input. |

Every FR has a stable ID (`MNT-3`, `MKT-2`, …). Prefixes: **ACC** access/roles/org · **DEPT** departments · **REQ** shared request rules · **MNT** maintenance work-orders · **OWN** owner requests · **TEN** tenant/company & tenant-users · **UNIT** units & leases · **MKT** marketing · **ACCT** accounting · **NOT** notifications. The original 15 business requests map to these IDs in [§14 Traceability](#14-traceability-original-requests--frs).

---

## 1. ERP framing & organization model

Atriom is treated as a **department-oriented ERP**: the operator's organization is modeled as **departments**, each owning its own workflows, staff, and data, with two cross-cutting fabrics tying them together:

- **Requests** — structured work/asks that flow *within* and *between* departments (and from owner/tenant).
- **Notifications** — the messaging fabric between departments, owner, and tenants.
- **Accounting is the financial hub** — any department action with money (spend, payment, receipt) routes through Accounting.

```
        ┌─────────────────────── Atriom ERP (per Asset / mall) ───────────────────────┐
        │                                                                              │
 Owner  │   ┌──────────┐  ┌───────────┐  ┌────────────┐  ┌─────────┐  ┌─────────────┐  │
(Jawad)─┼──►│ Leasing  │  │ Operations│  │ Marketing  │  │   HR    │  │ Accounting  │  │
monitors│   │(leases,  │  │(maintenance│ │(offers,    │  │(staff)  │  │ ◄─ financial│  │
        │   │ units)   │  │ /CAFM)    │  │ promos,    │  │         │  │    hub      │  │
        │   └────┬─────┘  └─────┬─────┘  │ events,    │  └────┬────┘  └──────▲──────┘  │
        │        │              │        │ printed)   │       │              │         │
        │        └──────────────┴────requests + notifications─┴──────────────┘         │
        │                                   │  receipts / payments ────────────────────┘
 Tenant ────► requests (tenant-admin only) ─┘
```

Departments map cleanly onto what exists today plus two net-new modules:

| Department | Maps to | Status |
|---|---|---|
| **Leasing** | Lease/Unit lifecycle, occupancy | 🟢 mostly exists |
| **Operations** (Facilities/CAFM) | Maintenance requests, vendors, meters | 🟢 mostly exists |
| **Accounting** | Invoices, payments, credit notes, CAM, ETA | 🟢 mostly exists (as billing) |
| **Marketing** | Offers, promotions, events, printed work, 5% budget | 🔵 **net-new** |
| **HR** | Staff records & internal workflows | 🔵 **net-new** |

🔧 **ACC/DEPT-design:** A `Department` is a first-class, **data-driven** entity (not a hard-coded enum) so new departments are added without a migration. The existing `manager / leasing_manager / maintenance_manager` RBAC roles map into departments; departments are the *org* axis, RBAC roles are the *permission* axis — kept separate.

---

## 2. Glossary & actor mapping

The platform is **multi-property**: an `Asset` is one mall; everything hangs off it. Three audiences already have separate Filament panels (`/admin`, `/owner`, `/portal`).

| Business term | Meaning | Maps to existing code |
|---|---|---|
| **Atriom** | The software platform itself (our product). | The whole codebase. |
| **Eltizam** | The **operator** — runs all mall operations, performs the work; holds operator/admin access. | Admin Console (`/admin`); staff via `asset_user` pivot; `super_admin`/`manager`/etc. roles. |
| **Jawad** | An **owner** customer (e.g. Haya Walk) — oversight only: reads/monitors; may raise owner requests. | Owner Portal (`/owner`); `owner` role; `asset_owner` pivot (`ownership_percentage`). |
| **Tenant** | A company (or individual) leasing unit(s). | `Tenant` model + Tenant Portal (`/portal`). |
| **Tenant admin** | The *one* tenant-side user permitted to submit requests. | 🔵 No tenant-user/role concept today — **net-new** ([§7](#7-tenants-company--users-ten)). |
| **Department** | Operator org unit: HR, Marketing, Accounting, Leasing, Operations. | 🔵 No `Department` model — **net-new** ([§5](#5-departments-dept)). |
| **Commercial Register** (segel togary) | Egyptian company registration no. | 🔵 No column — **net-new** field. |

> **Brand note:** "PropEzy" in `docs/PROPEZY-*` and `gap-analysis/` is a **competitor** (Eltizam's own SaaS), not our product — those docs are competitive research, not stale.

**Code anchors:** [User.php](../app/Models/User.php#L53-L62) · [asset_owner](../database/migrations/2026_05_23_170119_create_asset_owner_table.php) · [asset_user](../database/migrations/2026_05_25_192822_create_asset_user_table.php) · [RolesPermissionsSeeder](../database/seeders/RolesPermissionsSeeder.php)

---

## 3. Reconciliation summary (exists vs. build)

| Requirement area | Status | Anchor / note |
|---|---|---|
| Maintenance requests (lifecycle, priority, category, channel, assignment) | 🟢 `[EXISTS]` | [MaintenanceRequest.php](../app/Models/MaintenanceRequest.php) |
| "Late/overdue" detection + SLA-breach notification | 🟡 `[EXTEND]` | extend recipients to owner |
| Closed-request immutability | 🟡 `[EXTEND]` | policy/state guard on terminal statuses |
| Tenant-admin-only request submission | 🔵 `[NEW]` | needs tenant-users + roles |
| Owner → operator / owner → owner requests | 🔵 `[NEW]` | new Owner Request type ([§6B](#6b-owner-request-own)) |
| Departments + inter-dept comms (ERP) | 🔵 `[NEW]` | new `Department` model + notifications |
| Tenant registration fields | 🟡 `[EXTEND]` | add commercial register |
| Master unit / multi-unit lease | 🟡 `[EXTEND]` | lease is 1:1 with unit today |
| Marketing module + 5% fee + budget | 🔵 `[NEW]` | model on CAM pool/allocation pattern |
| Accounting as financial hub | 🟡 `[EXTEND]` | billing exists; add dept routing |
| RBAC, audit, notifications, settings | 🟢 `[EXISTS]` | spatie/permission, activitylog, notifications, settings |

---

## 4. Access, roles & org (ACC)

- **ACC-1** 🟢 `[EXISTS]` — Multi-user, multi-role RBAC; permissions data-driven and additive (spatie/permission, 6 seeded roles + UI-created custom roles, 81 permissions).
- **ACC-2** 🟡 `[EXTEND]` — Operator staff belong to **departments** (org axis) on top of their RBAC roles (permission axis). Model department membership like the `asset_user` staff pivot. See [DEPT-4](#5-departments-dept).
- **ACC-3** 🟢 `[EXISTS]` — The operator super-user (`super_admin`) can act on any request: assign, redirect, reject, resolve, close, comment.
- **ACC-4** 🔵 `[NEW]` — When redirecting a request, the **full department list is shown to the operator** (not hidden behind an "all departments" abstraction). *Depends on the net-new Department model.*
- **ACC-5** 🔵 `[NEW]` — **Owner** (`owner` role) is read/monitor by default, **plus** the right to raise **owner requests** ([§6B](#6b-owner-request-own)).
- **ACC-6** 🔵 `[NEW]` — **Tenant admin** is the only tenant-side user permitted to submit requests; other tenant users are read-only. *Requires tenant-users + roles ([TEN-3](#7-tenants-company--users-ten)).*

---

## 5. Departments (DEPT)

- **DEPT-1** 🔵 `[NEW]` — Seed departments **HR, Marketing, Accounting, Leasing, Operations** as a **data-driven, extensible** `Department` model (no hard-coded enum). A department belongs to an `Asset` or is global — see [O-9](#open-items).
- **DEPT-2** 🟡 `[EXTEND]` — Departments **contact each other via notifications** (reuse Laravel notifications + the in-app `notifications` table — infra already present).
- **DEPT-3** ⏸️ `[DEFERRED]` — Department **requests/payments route through Accounting** for approval/processing. Exact workflow to be defined with the accounting team. See [§10 ACCT](#10-accounting-hub-acct).
- **DEPT-4** 🔧 `[design]` — Department membership is separate from spatie RBAC roles (which stay global). Keep "who is in which department at which property" flexible, mirroring the existing `asset_user` pattern.
- **DEPT-5** 🔧 `[design]` — Each department is a self-contained ERP module (own resources, dashboard widgets, permissions) so departments can be added/toggled like the existing `ModulesSettings` feature flags.

---

## 6. Requests

A "request" is the central ERP workflow object, with **three concrete types** over one shared base: maintenance (MNT), owner (OWN), and the generic department request (used for DEPT-3). 🔧 *Design: shared contract for status, immutability, routing, audit so new request types are cheap (REQ-6).*

### 6.0 Shared request rules (REQ)

- **REQ-1** 🟡 `[EXTEND]` — Every request carries a **scheduled work window**: `from` date/time → `to` date/time (when the work is performed). *Maintenance has timestamps but no explicit requested window — add `scheduled_from` / `scheduled_to`.* — *covers original request #6.*
- **REQ-2** 🟢 `[EXISTS]` — Status lifecycle defined for maintenance: `submitted → acknowledged → in_progress → awaiting_tenant → resolved → closed`, plus `cancelled`. Other request types reuse a comparable lifecycle. See [`STATUSES`](../app/Models/MaintenanceRequest.php#L19-L27). *(Cross-cutting — no single originating request.)*
- **REQ-3** 🟡 `[EXTEND]` — **Closed requests are immutable.** At a terminal status (`closed`, `cancelled`), no field edits, comments, or reassignment. *Enforce via model policy + status guard; activity log already records the close.* — *covers original request #1.*
- **REQ-4** 🟢 `[EXISTS]` — Full **audit trail** on status/assignment changes via spatie/activitylog. See [`getActivitylogOptions()`](../app/Models/MaintenanceRequest.php#L43-L50). *(Cross-cutting.)*
- **REQ-5** 🔧 `[design]` — Routing modeled generically as **(sender, recipient, type, channel)**. Maintenance already has a `channel` enum (`portal, whatsapp, phone, email, walk_in, admin`); note `portal` is the **column default**, not an explicit marker (see [add_channel migration](../database/migrations/2026_05_25_145447_add_channel_to_maintenance_requests.php#L12-L13)). *(Cross-cutting.)*
- **REQ-6** 🔧 `[design]` — Implement maintenance/owner/department requests over a shared `Request` contract rather than copy-paste models. *(Cross-cutting.)*

### 6A. Maintenance work-order (MNT)

- **MNT-1** 🔵 `[NEW]` — Submission is restricted to the **tenant admin** (tenant side) and to operator staff (`channel = admin`). *Today any tenant-portal login can submit (`PortalMaintenanceSubmittedNotification`); gating to a tenant-admin role requires tenant-users ([TEN-3](#7-tenants-company--users-ten)).* — *covers original request #9 (clarified: "admin" = tenant admin).*
- **MNT-2** 🟡 `[EXTEND]` — A work-order is **assigned to a department** (and optionally a staff user or vendor). *Today assignment targets `User` (`assigned_to`) or `Vendor` (`assigned_to_vendor_id`); add a `department_id` dimension.* See [MaintenanceRequest.php](../app/Models/MaintenanceRequest.php#L98-L106). — *covers #5.*
- **MNT-3** 🔵 `[NEW]` — Operator can **redirect a misrouted request to another department** and **reject** it (with reason). *Reassignment exists; the department redirect + the shown department list ([ACC-4](#4-access-roles--org-acc)) depend on the net-new Department model.* — *covers #5.*
- **MNT-4** 🟢 `[EXISTS]` — Operator (`super_admin`) can perform all work on any request: acknowledge → progress → resolve → close, with comments. — *covers #5.*
- **MNT-5** 🟡 `[EXTEND]` — A work-order that is **late/overdue** notifies **owner (Jawad) users** as oversight. *"Overdue" already = `isOverdue()` (open AND past `target_resolution_at`); the daily `maintenance:scan-sla-breaches` job fires `MaintenanceSlaBreachedNotification` once (idempotent via `sla_breach_notified_at`). Extend recipients to owner users.* See [isOverdue()](../app/Models/MaintenanceRequest.php#L118-L123) · [sla migration](../database/migrations/2026_05_31_213931_add_sla_breach_notified_at_to_maintenance_requests.php). — *covers #4.*
- **MNT-6** 🔵 `[NEW]` — **Late fees** on a work-order also notify owner users. *No maintenance late-fee concept exists; see [O-3](#open-items)/[O-4](#open-items) (trigger + who is charged).* — *covers #4.*

### 6B. Owner request (OWN)

- **OWN-1** 🔵 `[NEW]` — **Owner (Jawad) users** can raise a request to **Eltizam** (owner→operator) or to **other owner users** (owner→owner). — *covers #2, #3.*
- **OWN-2** 🔵 `[NEW]` — Owner requests use the shared lifecycle + immutability rules (REQ-2/3) and are delivered/tracked via notifications. *(Derived from #2/#3.)*

---

## 7. Tenants: company & users (TEN)

- **TEN-1** 🟡 `[EXTEND]` — A tenant captures the full registration set. Current coverage from [tenants migration](../database/migrations/2024_01_01_000003_create_tenants_table.php):

  | Required field (business) | Existing column | Status |
  |---|---|---|
  | National ID | `national_id` | 🟢 |
  | Tax Card (bta2et darba) | `tax_id` (`// VAT registration / national ID`) | 🟡 clarify vs. dedicated tax-card no. ([O-5](#open-items)) |
  | Commercial Register (segel togary) | — | 🔵 **add `commercial_register`** |
  | Company name (esm el sherka) | `name` / `legal_name` | 🟢 |
  | Responsible person (esm el mas2ol) | `contact_person` | 🟢 |
  | Responsible person phone | `contact_person_phone` | 🟢 |
  | Email | `email` | 🟢 |

  — *covers original request #8.*
- **TEN-2** 🟢 `[EXISTS]` — Tenant `type` distinguishes `individual` vs `company`; company vs responsible-person fields already separated. *(Derived from #8.)*
- **TEN-3** ⏸️ `[DEFERRED]` — **Tenant users with roles.** Today a `Tenant` is a *single* login (password on the tenant record — [auth-columns migration](../database/migrations/2026_05_12_125617_add_auth_columns_to_tenants_table.php)). **Decision 2026-06-24: deferred** — the single tenant login already acts as the tenant admin and sole submitter, so #9's intent is met; full multi-user-per-tenant (`tenant_admin`/`tenant_staff`) would require re-architecting the portal + mobile (Sanctum) auth and is a separate, deliberate migration. — *covers #9 (tenant-admin gating).*

---

## 8. Units & leases (UNIT)

- **UNIT-1** 🟡 `[EXTEND]` — A **master unit** represents/handles all units under the **same lease**. *Today a `Lease` references exactly one `unit_id` ([leases migration](../database/migrations/2024_01_01_000004_create_leases_table.php#L14)) — 1 lease : 1 unit. One lease spanning multiple units (one designated master) is a schema change: a `lease_unit` pivot with `is_master`, or a self-referential `parent_unit_id` on units.* See [O-6](#open-items). — *covers original request #7.*
- **UNIT-2** 🟢 `[EXISTS]` — Units on **different leases** stay separate; each unit belongs to an `Asset` with its own status (`vacant/reserved/occupied/maintenance`). See [units migration](../database/migrations/2024_01_01_000002_create_units_table.php). — *covers #7.*
- **UNIT-3** 🔧 `[design]` — Prefer the `lease_unit` pivot (additive, keeps existing 1:1 leases valid) over reworking the units tree, unless physical sub-unit hierarchy is also needed. *(Design choice for #7 — see [O-6](#open-items).)*

---

## 9. Marketing (MKT)

🔵 **Net-new department/module.** No marketing/budget/promotion code exists. Strong reuse pattern: the existing **CAM expense-pool + allocation** system (period-scoped accrual, per-lease pro-rata allocation, each allocation linked to a billable `Charge`).

- **MKT-1** 🔵 `[NEW]` — Marketing manages **offers, promotions, events, and printed work** for tenants (catalog of marketing activities/spend). — *covers original request #13.*
- **MKT-2** 🔵 `[NEW]` — **Marketing fee = 5% of base rent**, market-validated as a "Marketing Fund / Promotional Levy" (industry norm ~1–5%; 5% is top of range). Stored as a **versioned, effective-dated, configurable rate** in spatie/settings (`BillingSettings` or new `MarketingSettings`) so changes don't alter historical leases. Realized per lease as a `Charge` of new `type = marketing` (today would fall under `other`). See [charges migration](../database/migrations/2024_01_01_000005_create_charges_table.php#L15-L22) · [BillingSettings](../app/Settings/BillingSettings.php). — *covers #14.*
- **MKT-3** 🔵 `[NEW]` — A **marketing budget** shown inside the Marketing department, modeled on `cam_expense_pools` (per-asset, per-period; income side = accrued 5% fees vs. CAM's expense side). See [cam_expense_pools](../database/migrations/2026_05_23_164627_create_cam_expense_pools_table.php). — *covers #15.*
- **MKT-4** 🔵 `[NEW]` — Marketing **spend issues a receipt to Accounting** (links a marketing spend to an accounting record, mirroring `cam_allocations.billed_charge_id`). — *covers #15.*
- **MKT-5** 🔵 `[NEW]` — The marketing budget is **auto-maintained system-wide**: **+** accrued 5% fees as leases bill, **−** marketing spend, always in sync (event-driven, like CAM true-ups). 🔧 *A single-action service recomputes the budget on charge/spend events so the displayed balance is derived, never hand-edited.* — *covers #15.*

**Pattern anchor:** [cam_allocations](../database/migrations/2026_05_23_164628_create_cam_allocations_table.php) (`pro_rata_share_pct`, `allocated_amount`, `billed_charge_id`).

---

## 10. Accounting hub (ACCT)

- **ACCT-1** 🟢 `[EXISTS]` — Core accounting exists as billing: `Invoice`, `InvoiceItem`, `Payment`, `CreditNote`, `Charge`, CAM allocations, ETA e-invoicing.
- **ACCT-2** ⏸️ `[DEFERRED]` — All **department requests/payments route through Accounting** for approval before money moves (the DEPT-3 workflow). Exact approval chain pending the accounting-team discussion. — *covers original request #12.*
- **ACCT-3** 🔵 `[NEW]` — Accounting **receives marketing receipts** (MKT-4) and reflects them against the marketing budget. — *covers #15.*

---

## 11. Notifications & cross-cutting (NOT)

- **NOT-1** 🟢 `[EXISTS]` — Channel-agnostic notification infra: Laravel notifications + `notifications` table + `DeviceToken` push. 9 notification classes exist (e.g. `MaintenanceSlaBreachedNotification`, `MaintenanceStatusChangedNotification`). See [app/Notifications](../app/Notifications/). *(Cross-cutting.)*
- **NOT-2** 🟡 `[EXTEND]` — Wire new events onto this infra: owner late/late-fee alerts (MNT-5/6), inter-department messages (DEPT-2), owner requests (OWN), marketing receipts (ACCT-3). Confirm channels for v1 ([O-7](#open-items)). *(Cross-cutting — supports #4, #11, #15.)*
- **NOT-3** 🟢 `[EXISTS]` — Audit trail via spatie/activitylog across maintenance, users, etc. *(Cross-cutting.)*

---

## 12. Design principles (extensibility & maintainability)

1. **Reuse existing patterns first** — CAM pool → marketing budget; `asset_user` → department membership; existing notification classes → new alerts. No parallel mechanisms.
2. **Departments as modules** — each department is a self-contained module (resources, widgets, permissions), toggleable like the existing `ModulesSettings` feature flags.
3. **Data-driven, not hard-coded** — departments, roles, marketing rate live in data/settings; addable without migrations (spatie/permission, spatie/settings).
4. **Versioned configuration** — money-affecting config (marketing %) is effective-dated; historical records keep the in-force rate.
5. **Single-action services + thin controllers** — business logic (budget recompute, fee accrual, request routing) in dedicated single-action classes, per house style.
6. **Shared request contract** — maintenance/owner/department requests share lifecycle/immutability/audit/routing.
7. **Derived, never hand-edited balances** — the marketing budget is always computed from charges + spend events.

---

## Open items

Non-blocking — design can proceed while these are confirmed.

| # | Question | Why it matters |
|---|---|---|
| **O-1** | ✅ Decided 2026-06-24: tenant-users **deferred** — current 1-login-per-tenant suffices (acts as tenant admin / sole submitter). | Avoids a breaking mobile-auth change; see TEN-3. |
| **O-2** | Final **owner-request** status list + who performs each transition. | OWN lifecycle. |
| **O-3** | What triggers a maintenance **"late fee"** (vs. just "overdue")? | "Overdue" solved; a *fee* is net-new (MNT-6). |
| **O-4** | **Who is charged** a maintenance late fee, and how is the amount set? | Billing + accounting routing. |
| **O-5** | Is `tax_id` the **tax card** number, or a separate field from VAT? | TEN-1 field design. |
| **O-6** | Master unit: **`lease_unit` pivot** (recommended) vs. `parent_unit_id` tree? | UNIT-1 schema choice. |
| **O-7** | Notification channels for v1 — **in-app only**, or also email/SMS/push? | NOT-2 scope. |
| **O-8** | Tenant-user roles beyond `tenant_admin`/`tenant_staff`? | TEN-3 scope. |
| **O-9** | Departments **per-property** or **global** across all malls? | DEPT-1 modeling. |
| **O-10** | Does **HR** need real scope (staff records, leave, etc.) or is it a placeholder department for now? | HR module sizing. |

## Deferred

- **DEPT-3 / ACCT-2** — Accounting routing of department requests/payments. Pending the accounting-team discussion noted in the business requirements.

---

## 13. Notes on ID coverage

All concrete (non-cross-cutting) FRs trace to an original request in §14. FRs marked *(Cross-cutting)* or `🔧 [design]` — REQ-2, REQ-4, REQ-5, REQ-6, TEN-2, UNIT-3, OWN-2, DEPT-4, DEPT-5, NOT-1, NOT-3 — are derived/structural and have no single originating request by design.

---

## 14. Traceability (original requests → FRs)

| # | Original business request | FR IDs |
|---|---|---|
| 1 | Closed requests can't be modified | REQ-3 |
| 2 | Send request to Eltizam by Jawad users | OWN-1 |
| 3 | Send request to Jawad users by Jawad users | OWN-1 |
| 4 | Maintenance late / late fees notify Jawad users | MNT-5, MNT-6, NOT-2 |
| 5 | Maintenance assigned to departments; admin redirect/reject; departments visible; admin does all | MNT-2, MNT-3, MNT-4, ACC-4 |
| 6 | Request has from–to date with time | REQ-1 |
| 7 | Master unit = same-lease units; different units = different leases | UNIT-1, UNIT-2, UNIT-3 |
| 8 | National ID, tax card, commercial register, company name, responsible person + phone, email | TEN-1, TEN-2 |
| 9 | Multi-role/user; only (tenant) admin submits requests | MNT-1, ACC-6, TEN-3 |
| 10 | Departments: HR, Marketing, Accounting, Leasing (+ Operations) | DEPT-1 |
| 11 | Departments contact each other via notifications | DEPT-2, NOT-2 |
| 12 | Department requests/payments pass through accounting | DEPT-3, ACCT-2 (deferred) |
| 13 | Marketing = offers, promotions, events, printed work | MKT-1 |
| 14 | Marketing 5%, changeable later | MKT-2 |
| 15 | Marketing budget shown; receipt to accounting; auto-handled & updated | MKT-3, MKT-4, MKT-5, ACCT-3 |
