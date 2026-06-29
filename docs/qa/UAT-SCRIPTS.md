# UAT scripts — business sign-off

> End-to-end scenarios per persona that the **business** runs and signs off
> before go-live. The question isn't "does it work" (the automated suite answers
> that) — it's "**is this the workflow we actually run, and is it right?**"
> Demo logins (password `password`) are in [CLAUDE.md](../../CLAUDE.md).

Each script: follow the steps as that persona, confirm each ✅, sign at the end.

---

## Persona A — Operator (Eltizam manager) · `manager@mall.test`

The day-to-day mall operator running billing, leasing, and tenant requests.

1. **Onboard** — create a tenant, a lease on a vacant unit (rent + service charge), confirm the unit flips to occupied.
2. **Bill** — run the monthly billing; confirm invoices generated with correct **base rent (VAT-exempt) + service charge (14% VAT) + 5% marketing levy on base rent**.
3. **Collect** — record a payment; split it across two invoices; confirm `paid_amount`/`balance` update and the books still tie (`billing:reconcile`).
4. **Credit** — issue a credit note, apply it to a live invoice; confirm the balance drops and the credit can't be applied to a cancelled invoice.
5. **Requests** — triage an incoming tenant request: acknowledge → assign → resolve; re-route a mis-filed one; confirm the tenant is notified at each step.
6. **CAM** — run the annual CAM reconciliation; confirm allocations bill once and the pool is marked reconciled.
7. **Percentage rent** — lock a tenant sales declaration; confirm a percentage-rent charge lands on the next invoice.
8. **Reports** — open AR-Aging + the monthly close report; confirm the figures match the dashboard.
9. **Close** — confirm the dashboard KPIs (occupancy, MRR, collected, outstanding AR, tenant satisfaction) read sensibly.

**Sign-off:** `__________`  Date `____`  Notes: `__________`

---

## Persona B — Owner (Jawad) · admin RBAC, property-scoped · `owner@atriom.test`

The property owner — now an admin user scoped to owned properties (the separate owner portal is retired).

1. **Scope** — confirm you see **only your property's** units, leases, invoices, and requests — nothing from other properties (incl. in "All Properties" views).
2. **Visibility** — review occupancy, AR, and open requests for your property.
3. **Owner request** — raise a request to the operator team; confirm they're notified and you see their response.
4. **Boundaries** — confirm you cannot perform operator-only actions outside your remit (deletes are super-admin only; no cross-property data).

**Sign-off:** `__________`  Date `____`  Notes: `__________`

---

## Persona C — Tenant (web portal) · `tenant1@atriomwalk.test` (admin) / `staff1@atriomwalk.test` (read-only)

The retailer using the web portal.

1. **See money** — view invoices + payment history; download an invoice PDF; pay an invoice via the payment link (Paymob).
2. **Raise a request** — submit a request of a **non-maintenance type** (e.g. a billing query or document request); add a comment; confirm you can't see internal staff notes.
3. **Rate** — after a request is resolved, submit a satisfaction rating.
4. **Sales** (percentage-rent leases) — submit a monthly sales declaration.
5. **Multi-user** — confirm the read-only staff user can view but **not** create/submit anything.

**Sign-off:** `__________`  Date `____`  Notes: `__________`

---

## Persona D — Tenant (mobile app) · `/api/v1`, `tenant1@atriomwalk.test`

The retailer on the phone (validate via the app build or the OpenAPI spec / Postman).

1. **Login** — log in; confirm per-device tokens; the home summary shows money owed + open work + alerts.
2. **Invoices** — list invoices, open one, start a Paymob payment session, confirm it reflects as paid.
3. **Requests** — raise a request of any type with a photo; comment; cancel one not yet started; rate a resolved one.
4. **Notifications** — receive a push + see the in-app inbox; mark read.
5. **Profile** — view leases + balance; update profile; change password.

**Sign-off:** `__________`  Date `____`  Notes: `__________`

---

## Overall UAT result

- [ ] All four personas signed off, no open critical/high issues.
- **Approved for production by:** `__________`  Date `____`
