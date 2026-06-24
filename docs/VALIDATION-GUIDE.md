# Validation Guide — Business-Model Sign-off

> Walk through each shipped feature in the running app and confirm it matches the business intent. Tick each box; if anything behaves differently from the **Business rule**, flag it.
> Built against [FUNCTIONAL-REQUIREMENTS.md](FUNCTIONAL-REQUIREMENTS.md); status mirrors that doc's §3.
> Local app: `http://mall-management.test` (Herd) — all migrations + seeds already applied.

## Logins

| Role | URL | Email | Password |
|---|---|---|---|
| Eltizam operator (admin) | `/admin` | `admin@mall.test` | `password` |
| Jawad owner (RBAC user in admin) | `/admin` | `owner@atriom.test` | `password` |
| Tenant | `/portal` | `tenant1@haya.test` | `password` |

Toggle **عربي** in the top bar to spot-check Arabic / RTL labels. (A few marketing/owner-request enum labels are English-only for now — noted where relevant.)

The admin **sidebar is grouped by department** — **Leasing** (Properties/Units/Tenants/Leases/Sales/Occupancy) · **Operations** (Maintenance/Vendors/Meters/Owner Requests) · **Accounting** (Invoices/Payments/Credit Notes/CAM/Reports) · **Marketing** (Budgets) · **HR** (Users/Roles/Departments) · **Settings**. What each user actually sees depends on their role.

---

## 0. Owner access — no owner portal (model correction 2026-06-25)
**Business rule:** there is **no separate owner portal**; Jawad owners are users with roles/permissions in the **admin app**, scoped to the properties they own.
**Where:** log in at `/admin` as `owner@atriom.test`.
- [ ] The owner lands in the **admin app** (the `/owner` site is gone / 404s).
- [ ] The property switcher shows only their **owned** property (Atriom Walk), not every mall.
- [ ] The owner has read-only oversight (Properties / Leases / Invoices / Maintenance / Reports) per their permissions, plus **Owner Requests** (create + track) — and no edit/delete/create on the oversight modules.

---

## 1. Departments — ERP backbone · req #10
**Business rule:** the operator org is modeled as departments — HR, Marketing, Accounting, Leasing, Operations — data-driven (add more without code).
**Where:** `/admin` → **Operations → Departments**.
- [ ] The 5 core departments are present.
- [ ] Create a new department (e.g. "Security") → saves and lists.
- [ ] Edit / deactivate works; labels translate under عربي.

## 2. Department membership grants access · DEPT-4/6
**Business rule:** departments are a **fixed set**; staff are **registered** to a department, which grants that department's **role** → they then see that department's pages (and only those). Access is RBAC, not the Department model.
**Where:** `/admin` → Departments → open one → **Members** tab.
- [ ] Attach a user to **Accounting** → they gain the `accounting` role.
- [ ] Log in as that user → they see only Accounting's resources (Invoices / Payments / Credit Notes / CAM), nothing else.
- [ ] Detach → the role is removed.
- [ ] You **cannot create** a new department (fixed set — no Create button).

## 3. Maintenance → departments (assign / redirect / reject) · req #5
**Business rule:** a work-order is assigned to a department; the operator can redirect a mis-routed one to another department (with the **full** list visible), reject it, and do all the work.
**Where:** `/admin` → **Maintenance** → open a request.
- [ ] **Assignment** section has a **Department** select.
- [ ] List has a **Department** column + filter.
- [ ] Row action **"Redirect to department"** shows the full department list and reassigns.
- [ ] Status → **Cancelled** acts as reject; operator can move acknowledge → in-progress → resolve → close.

## 4. Closed-request immutability · req #1
**Business rule:** once **Closed** or **Cancelled**, a request can't be modified.
**Where:** `/admin` → Maintenance → a request in Closed/Cancelled status.
- [ ] No **Edit**, **Redirect**, or **Assign** actions are offered on it.

## 5. Marketing — 5% levy + auto budget + spend/receipts · req #13/14/15
**Business rule:** a 5% (configurable) marketing levy on rent funds a per-property marketing budget; marketing spend (offers / promotions / events / printed work) draws it down with a receipt to accounting; the budget is auto-maintained.
**Where:** `/admin` → **Marketing → Marketing Budgets**.
- [ ] HW **2025** and **2026** budgets show **Accrued** (~EGP 169k / ~248k = 5% of billed rent) and **Balance**.
- [ ] Open a budget → **Marketing Spend** tab → add a spend (category, amount, receipt #) → the **Balance drops** by that amount.
- [ ] The 5% rate lives in Settings and is captured per-charge (changing it won't rewrite history).
- [ ] *(Math check)* Run **Monthly Billing** for a new month → the budget **Accrued** rises by 5% of that month's billed rent, and tenant **invoice totals are unchanged** (internal allocation, not a tenant line item).

## 6. Owner requests — Jawad ↔ Eltizam / Jawad ↔ Jawad · req #2/3
**Business rule:** a Jawad owner raises a request to the Eltizam operator team or to another owner; the operator responds; both sides are notified; closed requests are immutable.
**Where:** the **admin app** — owners and operators are both RBAC users there (no separate portal).
- [ ] As the **owner** (`owner@atriom.test`) → **Owner Requests → New** → recipient **Eltizam (operator)** → submit. (Also try **Another owner**.) The owner sees only their own requests.
- [ ] As an **operator** (`admin@mall.test`) → **Owner Requests** inbox → the request appears → **Respond** (set status + notes). Owners have no Respond action.
- [ ] Back as the **owner** → the request shows the updated status + notes; bell notifications fire both ways.

## 7. Tenant registration fields · req #8
**Business rule:** tenant captures national ID, tax card, **commercial register (segel togary)**, company name, responsible person + phone, email.
**Where:** `/admin` → **Tenants** → edit a tenant.
- [ ] **Commercial Register** field present (Tenant Information), alongside National ID / Tax ID / company + contact-person fields.

## 8. Scheduled work window · req #6
**Business rule:** a request has a from→to date/time window (when the work is performed), distinct from the SLA deadline.
**Where:** `/admin` → Maintenance → edit a request → Assignment section.
- [ ] **Scheduled from** / **Scheduled to** date-time fields present.

---

## Not yet built / deferred — do NOT expect these yet

| # | Item | State |
|---|---|---|
| 4 | Late/overdue → notify **Jawad owners** + maintenance **late fees** | Overdue detection exists but alerts *staff* only; late fees not built (pending your O-3/O-4 decisions) |
| 11 | Department-to-department **messaging** action | Infra ready; no dedicated "message a department" action yet |
| 7 | **Master unit** / multi-unit lease | Not built; a lease is still one unit |
| 9 | **Tenant-users** (only tenant-admin submits) | Deferred by your decision (single tenant login already acts as the admin); would rewrite mobile auth |
| 12 | Dept requests/payments **via Accounting** | Deferred pending your accounting team |

When §§1–8 are ticked, you're validated to proceed. The table above is the remaining scope — see [FUNCTIONAL-REQUIREMENTS.md §3](FUNCTIONAL-REQUIREMENTS.md) for the build plan.
