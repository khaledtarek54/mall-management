# Atriom — Business Model & Scenarios

> **Plain-language guides to how each module actually works — the *business* logic, not the code.**
> Written to be read by an operator/owner, not just a developer: what the module is for, how the
> money and state flow, and **every scenario walked through with worked numbers**.
>
> Each file pairs with its technical spec in [`docs/modules/NN-*.md`](../modules/) (business rules,
> extension points, gotchas) and its competitive/close-out analysis in
> [`docs/gap-analysis/`](../gap-analysis/PROPERTY-FACILITY-CLOSURE.md). These are produced as each
> module is taken through the property+facility close-out, so they reflect the *current, fixed*
> behaviour — including what the module deliberately does **not** do yet (the deferred decisions).

## How to read a scenario

Every doc has a **Scenarios** section. Each scenario states the setup (the lease/tenant/numbers),
the action, and the exact result — the same shape as this percentage-rent example:

> Base rent 50,000/mo, rate 8%. Tenant declares 800,000 in monthly sales →
> `sales × rate = 64,000`; since that beats the 50,000 base, the tenant is billed the **14,000
> overage** → total 64,000 for the month (the greater of base rent or % of sales).

## Index

| # | Module | Business-model doc | Technical spec |
|---|--------|--------------------|----------------|
| 01 | Properties & Units | [01-properties-units.md](01-properties-units.md) | [modules/01](../modules/01-properties-units.md) |
| 02 | Tenants | [02-tenants.md](02-tenants.md) | [modules/02](../modules/02-tenants.md) |
| 04 | Leases | [04-leases.md](04-leases.md) | [modules/04](../modules/04-leases.md) |
| 05 | Billing & Invoices | [05-billing-invoices.md](05-billing-invoices.md) | [modules/05](../modules/05-billing-invoices.md) |
| 06 | Payments | [06-payments.md](06-payments.md) | [modules/06](../modules/06-payments.md) |
| 07 | Credit Notes | [07-credit-notes.md](07-credit-notes.md) | [modules/07](../modules/07-credit-notes.md) |
| 08 | CAM Reconciliation | [08-cam.md](08-cam.md) | [modules/08](../modules/08-cam.md) |

_More added as each module closes (order in [PROPERTY-FACILITY-CLOSURE.md](../gap-analysis/PROPERTY-FACILITY-CLOSURE.md))._
