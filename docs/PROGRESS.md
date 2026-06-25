# Project Progress & Validation Workbook

> **How we work (read this first):**
> 1. We go **one feature at a time**, in order.
> 2. For each feature you get: **What it does (business)** + **How to test it** (steps + what to expect).
> 3. You test it on your local, then mark **Validated** and add any **notes/comments**.
> 4. I fix your comments → the feature is **Done** → we move to the next.
>
> **Status key:** Built (code) · Tested (automated suite, currently **518 green**) · **Validated** = you signed off in the app.
> **Logins** (`/admin` unless noted, password `password`): operator `admin@mall.test` · manager `manager@mall.test` · **Jawad owner** `owner@atriom.test` · tenant `tenant1@haya.test` (`/portal`).
> Design detail → [FUNCTIONAL-REQUIREMENTS.md](FUNCTIONAL-REQUIREMENTS.md).

---

## Part 1 — Business model

### 1. Departments (org backbone) · ✅ Built · ✅ Validated
**What it does:** the operator's organization is modeled as five fixed departments — **HR, Marketing, Accounting, Leasing, Operations**. They're the backbone everything else hangs off (covers original request #10).
**How to test:** `/admin` → sidebar **HR → Departments**.
- See the 5 departments listed.
- Open one → you can set its head / active flag; there is **no "New department"** button, name/code are locked, and departments **cannot be deleted** (the set is fixed).
**Your notes:** _____________________

### 2. Department access = roles (register a user → they get access) · ✅ Built · ✅ Validated
**What it does:** access is by **role**, not the department record. Each department has a **same-named** role (leasing / operations / accounting / marketing / hr), **strictly scoped** to its own pages; **registering a user into a department grants that role**, so they see only that department's pages (covers "users registered to departments"). Cross-cutting roles: super_admin, manager, viewer, owner.
**How to test:** `/admin` → Departments → **Accounting** → **Members** tab → attach a user. Then log in as that user.
- They see **only** Accounting's resources (Invoices / Payments / Credit Notes / CAM), nothing else.
- Detach them → access is removed.
**Your notes:** _____________________

### 3. Sidebar grouped by department · ✅ Built · ✅ Validated
**What it does:** the admin sidebar is organized by department so each contains its pages.
**How to test:** `/admin` as `admin@mall.test` → look at the sidebar groups:
- **Leasing** (Properties/Units/Tenants/Leases/Sales/Occupancy) · **Operations** (Maintenance/Vendors/Meters/Owner Requests) · **Accounting** (Invoices/Payments/Credit Notes/CAM/Reports) · **Marketing** · **HR** (Users/Roles/Departments) · **Settings**.
- *(Hard-refresh if you still see the old groups.)*
**Your notes:** _____________________

### 4. Owner model — no owner portal; owners are admin users · ✅ Built · ✅ Validated
**What it does:** there is **no separate owner portal**. Jawad owners log into the **admin app** with a role, scoped to the properties they **own** (covers req #9 framing).
**How to test:** log in as **`owner@atriom.test`**.
- Lands in `/admin` (the old `/owner` site is gone).
- Property switcher shows **only their owned property** (Atriom Walk), not every mall.
- **Read-only oversight of everything** (all departments/modules) — but only for their owned property — + **Owner Requests**; no edit/create/delete.
**Your notes:** _____________________

### 5. Maintenance → departments (assign / redirect / reject) · ✅ Built · ✅ Validated
**What it does:** a maintenance work-order is assigned to a department; the operator can redirect a mis-routed one (with the **full** dept list shown), reject it, and do all the work (covers req #5).
**How to test:** `/admin` → **Operations → Maintenance** → open a request.
- **Assignment** section has a **Department** select; the list has a Department column + filter.
- Row action **"Redirect to department"** shows all departments and reassigns.
- Setting status to **Cancelled** = reject; you can move it acknowledge → in-progress → resolve → close.
**Your notes:** _____________________

### 6. Closed requests can't be modified · ✅ Built · ☐ Validated
**What it does:** once a request is **Closed** or **Cancelled** it's locked (covers req #1).
**How to test:** `/admin` → Maintenance → open a **Closed/Cancelled** request.
- No **Edit / Redirect / Assign** actions are offered on it.
**Your notes:** _____________________

### 7. Scheduled work window (from → to) · ✅ Built · ☐ Validated
**What it does:** a request carries a from→to date/time for when the work is performed (distinct from the SLA deadline) (covers req #6).
**How to test:** `/admin` → Maintenance → edit a request → Assignment section.
- **Scheduled from** / **Scheduled to** date-time fields are present.
**Your notes:** _____________________

### 8. Overdue work-orders alert the owner · ✅ Built · ☐ Validated
**What it does:** when a work-order passes its SLA target while still open, the property **owner (Jawad)** gets a bell alert too, not just staff (covers req #4, part 1).
**How to test:** the daily scan fires it; to force it, run `php artisan maintenance:scan-sla-breaches` after setting a request's Target Resolution to the past.
- The owner of that property gets a bell notification.
**Your notes:** _____________________

### 9. Owner requests (Jawad → Eltizam / Jawad → Jawad) · ✅ Built · ☐ Validated
**What it does:** a Jawad owner raises a request to the operator team or to another owner; the operator responds; both get notified; closed ones are immutable (covers req #2, #3).
**How to test:**
- As **owner** (`owner@atriom.test`) → **Operations → Owner Requests → New** → recipient **Eltizam (operator)** → submit. (Also try **Another owner**.)
- As **operator** (`admin@mall.test`) → **Owner Requests** inbox → **Respond** (status + notes).
- Back as the owner → it shows the response; the owner sees only their own requests and has no Respond action.
**Your notes:** _____________________

### 10. Departments contact each other (messaging) · ✅ Built · ☐ Validated
**What it does:** one department can send a message to another; that department's members get a bell notification (covers req #11).
**How to test:** `/admin` → **HR → Departments** → on any department row use the **Message** action → write a message.
- The target department's members get a bell notification (the sender doesn't).
**Your notes:** _____________________

### 11. Marketing — 5% levy + auto budget + spend & receipts · ✅ Built · ☐ Validated
**What it does:** a **5%** (configurable) marketing levy on rent funds a per-property marketing budget; marketing spend (offers/promotions/events/printed work) draws it down with a receipt to accounting; the budget is auto-maintained (covers req #13, #14, #15).
**How to test:** `/admin` → **Marketing → Marketing Budgets**.
- HW **2025** and **2026** budgets show **Accrued** (~EGP 169k / 248k = 5% of billed rent) + **Balance**.
- Open one → **Marketing Spend** tab → add a spend (category, amount, receipt #) → **Balance drops**.
- *(Math: run Monthly Billing for a new month → Accrued rises by 5% of that rent; tenant invoice totals are unchanged — it's an internal allocation.)*
**Your notes:** _____________________

### 12. Tenant commercial register field · ✅ Built · ☐ Validated
**What it does:** the tenant record captures the **commercial register (segel togary)** alongside national ID, tax card, company name, responsible person + phone, email (covers req #8).
**How to test:** `/admin` → **Leasing → Tenants** → edit a tenant.
- **Commercial Register** field present in the Tenant Information section.
**Your notes:** _____________________

---

## Part 1 — still to build

### 13. Maintenance late fees · ⛔ Blocked (needs your decision) · req #4 (part 2)
**What it will do:** when a maintenance work-order is late, charge a **late fee** and alert the owner (the alert half is done in #8; the *fee* is not built).
**Needs from you (before I can build):**
- **O-3:** what triggers a fee — passing the **scheduled work-window end**, or the **SLA deadline**?
- **O-4:** **who is charged** (tenant? responsible party?) and **how much** (flat / % / per-day)?

### 14. Master unit / multi-unit lease · 🔴 Not started · req #7
**What it will do:** today a lease covers exactly **one** unit. This lets **one lease span several units** with one designated **master unit** (e.g. a shop that took an adjacent kiosk), while keeping all existing single-unit leases valid.
**Plan:** add a `lease_unit` link (with an `is_master` flag), surface it in the lease form + occupancy. It's the only **schema-touching** item, so I'll build + validate it on its own.

---

## Part 2 — after Part 1 is signed off

> Defined once Part 1 is validated. Candidates: production hardening (email/SMTP, queue worker, deploy) · mobile/tenant app · payments go-live (Paymob) · accounting-routing workflow (req #12, pending your accounting team) · deeper reporting.

---

## Deferred by decision (not part of sign-off)
- **Tenant-users** (multiple logins per tenant, only tenant-admin submits) — your call; current single tenant login already acts as the admin (req #9 detail).
- **Dept requests/payments routed via Accounting** — pending your accounting team's workflow (req #12).

## Open question (any time)
- **Notifications:** owner-requests and dept-messages are **bell-only** today. Want them to **also email**? (email also needs SMTP configured.)
