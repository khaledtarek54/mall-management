# Business Model — Tenants (Module 02)

> The **retailer** — the operator's customer. This module holds who the tenant is (identity for tax
> and legal), controls their access to the self-service portal and mobile app, and keeps one
> retailer's money private from another mall's staff.
> Technical spec: [modules/02-tenants.md](../modules/02-tenants.md).

---

## 1. What a tenant is

A **Tenant** is a **lessee company** — the shop brand that leases space. It carries:
- **Identity:** legal name, **tax_id** (Egyptian VAT registration — required for e-invoicing),
  commercial register, national ID.
- **Type:** `company` or `individual` (drives how it's represented to the Tax Authority).
- **Contact + status.**

A tenant is **shared, not owned by one mall** — a chain like "Coffee Co" can hold leases in several
of the operator's malls under one tenant record. That sharing is deliberate (one legal entity, one
tax identity) but it's exactly why the money views have to be careful about *which mall's* AR a given
operator is allowed to see (see §4).

---

## 2. Status is a gate — active, inactive, blacklisted

| Status | Meaning | Portal + mobile access |
|--------|---------|------------------------|
| **active** | Normal | ✅ Yes |
| **inactive** | Dormant | ❌ No |
| **blacklisted** | Legal/payment dispute | ❌ No |

**This gate is now enforced on every request (fixed 2026-07-19).** Previously a company could be
blacklisted mid-session and keep full access — the status was only checked at login. Now:

- **Portal:** a blacklisted company's users can't sign in *or* stay in.
- **Mobile API:** every authenticated request re-checks the company's status; blacklisting a tenant
  cuts its app off on the very next call (and revokes its token).

**Scenario:** Coffee Co is in a payment dispute. The operator sets it to **blacklisted**. Coffee Co's
staff app is on the invoices screen — their next tap (refresh, pay, submit a request) returns
"account blocked" and they're logged out. No lingering access.

---

## 3. Multiple users per tenant — admin vs. staff

A tenant company can have several **portal/app logins** (`TenantUser`): a manager and a few staff.
Only an **admin** user can *write* (pay an invoice, submit a maintenance request); staff are
**read-only** (they can view invoices and statements but not act). This lets a brand give its people
different access without sharing one password.

---

## 4. One retailer, many malls — keeping AR private

Because a tenant is shared, an operator restricted to **Mall A** who opens a shared tenant must not
see that tenant's **Mall B** invoices, payments, or overdue status. The close-out fixed three places
that leaked this (2026-07-19):

- the tenant **statement PDF**,
- the **delinquency** badge/filter,
- the **outstanding balance**.

Each now scopes to the malls the operator can see on the **admin** side, while the tenant's **own**
portal/app statement stays whole-company (the tenant is entitled to see all their own malls).

**Scenario:** "Acme" leases in Mall A and Mall B and is 30 days overdue in Mall B. A Mall-A-only
leasing agent opens Acme's statement → it shows **only Mall A** invoices, and the delinquency badge
reflects Mall A only. Acme's own manager, in the tenant portal, sees the **full** picture across both
malls.

---

## 5. Tax identity — getting ETA right

`tax_id` is the tenant's Egyptian VAT number and goes on every e-invoice to the Tax Authority (ETA),
which **rejects a badly-formatted number**. The system:
- validates the format (`123-456-789`, dashes optional) on **both** the form and the bulk importer
  (import is the main way an existing mall's roster is loaded — a malformed number there is a
  go-live risk), and
- **normalises it to bare digits on save** (`123456789`), so ETA always receives digits only
  regardless of how it was typed (a close-out fix — dashes used to reach ETA).

---

## 6. What it deliberately does **not** do yet (open decisions)

- **Tenant merchandising / trade classification.** Tenant-mix analysis today keys off the *unit's*
  space-category, not the tenant's own retail category (e.g. "women's fashion"). *Decision: add a
  tenant trade classification for mix/exclusivity analysis?*
- **Duplicate prevention / merge.** `tax_id` is indexed but not unique, and there's no merge tool. A
  legal entity entered twice splits its AR and gets two ETA identities. *Decision: build a merge
  tool (re-point leases/invoices/payments) before enforcing uniqueness?*

---

## 7. How it connects

- **[Leases (04)](04-leases.md)** — a tenant holds one or more leases (possibly across malls).
- **[Tenant Portal (03)](../modules/03-tenant-portal-users.md)** — the `TenantUser` logins in §3.
- **[ETA e-invoicing (16)](../modules/16-eta-einvoicing.md)** — consumes the tax_id in §5.
- **[Billing (05)](../modules/05-billing-invoices.md) / [Payments (06)](../modules/06-payments.md)**
  — the AR that §4 scopes.
