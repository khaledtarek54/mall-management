# Module reference — how each part of the system works

> **The canonical per-module documentation.** One file per module, each with the same shape:
> *purpose and business context · business rules · data model · services · screens ·
> **extension points (how to change safely)** · gotchas*.
>
> **Read the module's doc before changing its logic.** Changing the logic means updating the doc
> **in the same commit** — docs are part of "done" ([CLAUDE.md](../../CLAUDE.md)).

**Where the other things are:** [`../gap-analysis/`](../gap-analysis/README.md) says what is
*missing* per module; this says how what exists actually *works*. For the picture-first version,
read the visual handbook in the panel at `/admin/handbook`.

---

## The money spine

Read these in order — each one assumes the one before it.

| # | Module | What it owns |
|---|---|---|
| 01 | [Properties & Units](01-properties-units.md) | The mall, its floors, its lettable space. `asset_id` — the dimension every other module is isolated by |
| 02 | [Tenants](02-tenants.md) | The retailer as a counterparty: identity, tax registration, documents, blacklist |
| 04 | [Leases](04-leases.md) | The contract — term, premises, the dated **charge schedule** that everything bills from, escalation, options, amendments, holdover, move-out |
| 05 | [Billing & Invoices](05-billing-invoices.md) | The monthly run, proration, tax on the line, the invoice as the receivable |
| 06 | [Payments & Allocation](06-payments.md) | How money arrives and how it is split across invoices; the over-allocation guards |
| 07 | [Credit Notes](07-credit-notes.md) | Correcting a raised document by **un-applying** it, never by stacking a second one |
| 21 | [General Ledger](21-general-ledger.md) | Double-entry books under all of it. The single journalizer registry, the close gate, the tie-out |

## Recoveries and variable rent

| # | Module | What it owns |
|---|---|---|
| 08 | [CAM Reconciliation](08-cam.md) | Expense pools, gross-up, caps, admin fee, the estimate → reconcile → true-up cycle |
| 09 | [Tenant Sales & Percentage Rent](09-tenant-sales-percentage-rent.md) | Declarations, breakpoints, tiers, monthly vs annual cumulative overage |
| 10 | [Utility Meters & Readings](10-utility-meters.md) | Consumption → dated tariff → recharge invoice |
| 13 | [Marketing Levy, Budgets & Spend](13-marketing.md) | The 5%-of-base-rent levy, the spend register and its budget |
| 31 | [Violations](31-violations.md) | Recording a breach, and billing the fine as its own VAT-exempt invoice |
| 33 | [Post-dated Cheques](33-post-dated-cheques.md) | The Egyptian instrument: lodging a series, maturity, clear/bounce |
| 35 | [Rentable Items](35-rentable-items.md) | Parking, storage, signage — billable, and deliberately **not** in GLA |

## The counterparties

| # | Module | What it owns |
|---|---|---|
| 03 | [Tenant Portal Users](03-tenant-portal-users.md) | `/portal` — multi-user tenant logins; only `is_admin` may write |
| 11 | [Tenant Requests](11-tenant-requests.md) | The tenant-facing board: maintenance, complaints, permits, access, billing queries |
| 12 | [Vendors & Contracts](12-vendors.md) | Contracts, commitments, change orders, compliance documents, the dispatch guard, withholding tax |
| 15 | [Owner Requests & the Owner Model](15-owner-requests-and-model.md) | The owner↔operator channel, and ownership tenure |
| 32 | [Owner Statements & Disbursements](32-owner-statements.md) | The deliverable of the operator-for-owner relationship |
| 37 | [Unit Owners](37-unit-owners.md) | The buyer who trades from his own shop and holds **no lease** — billed for صيانة against the ownership |
| 27 | [Announcements](27-announcements.md) | Mall news out to tenants |
| 36 | [Marketing Posts](36-marketing-posts.md) | The shopper feed — the one unauthenticated public surface |

## Facility and operations

| # | Module | What it owns |
|---|---|---|
| 26 | [Facility](26-facility.md) | Work orders (planned and corrective), service plans, SLA and the penalty that reaches the vendor's bill |
| 30 | [Areas](30-areas.md) | Facility zones and supervisor routing |
| 22 | [Inventory & Stock](22-inventory.md) | Perpetual stock with weighted-average costing; every movement posts |
| 23 | [Fixed Assets & Depreciation](23-fixed-assets.md) | The register, the depreciation run, and the maintenance-register twin |
| 29 | [Procurement](29-procurement.md) | Purchase requests, the approval tier, receipt, and GRNI clearing against the bill |
| 28 | [Approvals](28-approvals.md) | The value → approver ladder, shared by every module that spends |
| 25 | [Treasury / Custody](25-treasury-custody.md) | العُهدة — cash advanced to a person and settled back |
| 24 | [HR / Employees](24-hr-employees.md) | Employees, payroll runs, advances and repayments |

## Cross-cutting

| # | Module | What it owns |
|---|---|---|
| 14 | [Departments](14-departments.md) | The org model requests and spend are routed through |
| 16 | [ETA E-Invoicing](16-eta-einvoicing.md) | The Egyptian Tax Authority pipeline. **Ships in mock** — see [operations/GO-LIVE.md](../operations/GO-LIVE.md) |
| 17 | [Reports](17-reports.md) | Rent roll, expirations, aging, occupancy cost, sales analytics, the financial statements, CSV |
| 18 | [RBAC & Multi-Property Scoping](18-rbac-scoping.md) | Roles, permissions, and how a property-restricted user is confined |
| 19 | [Notifications & Scheduled Scans](19-notifications-scans.md) | Every scheduled sweep, and who gets told what, on which channel |
| 20 | [Mobile API](20-mobile-api.md) | `/api/v1` — Sanctum against `Tenant`. Endpoint reference in [api/MOBILE-API.md](../api/MOBILE-API.md) |
| 34 | [Search](34-search.md) | The folded `search_text` blob, the pickers, and why both sides must be folded |

---

## Conventions every module doc follows

- **Business rules** are numbered and stated as rules, not as descriptions of code.
- **Extension points** say how to change the module *safely* — read this before editing.
- **Gotchas** record what has already gone wrong here. They are the most valuable section and the
  one most likely to save a day.
- Generated blocks are marked `<!-- GENERATED:… -->` and are rewritten by
  `php artisan atriom:dump-registries`. **Never hand-edit one** — `GeneratedDocsConformanceTest`
  fails the build when a block drifts from the registry it derives from.
