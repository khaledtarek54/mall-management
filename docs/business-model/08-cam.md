# CAM Reconciliation — the business model

> CAM (Common Area Maintenance — صيانة المناطق المشتركة) is how the mall's shared-area running costs
> — cleaning, security, A/C, lighting the corridors — get shared fairly among the tenants who benefit
> from them. Pairs with the technical spec in [modules/08](../modules/08-cam.md) and the close-out in
> [gap-analysis](../gap-analysis/PROPERTY-FACILITY-CLOSURE.md).

## 1. The idea: estimate monthly, reconcile once a year

The operator doesn't know the exact CAM cost until the year is over (the electricity bills, the
cleaning contract, the A/C servicing all land across twelve months). So it works in two steps:

1. **Monthly estimate.** Every tenant pays a monthly **service charge** (a CAM estimate) on their
   regular invoice — billed at 14% VAT, like any service. Across the year these add up to what was
   *estimated and collected*.
2. **Year-end reconciliation.** Once the year closes, the operator totals the **actual** CAM spend
   and compares it to what was collected. The difference — the **true-up** — is shared out to each
   tenant by their **share of the leased area** (a 300 m² shop bears three times a 100 m² shop's
   share).

If the tenant under-paid (actual > estimate), they owe a **top-up**; if they over-paid, they get a
**credit**. Either way the tenant's slice is proportional and auditable.

## 2. Worked example

A mall's actual CAM for 2026 came to **1,000,000**; tenants had paid **900,000** in monthly
estimates. Variance = **+100,000** (under-collected — tenants will owe).

Tenant A leases 100 m² of a 1,000 m² leased total → a **10%** share.
- Their share of actual = 100,000; their share of estimate = 90,000.
- **True-up = +10,000** → billed as a one-off **CAM Recovery** invoice, with 14% VAT (2,800) on top
  → 12,800 due. Settled immediately on its own invoice (not left to drift onto a later month).

If instead Tenant A had *over-paid* (their estimate exceeded their share of actual), the true-up is
**negative** → issued as a **credit note** (with the VAT reversed), which automatically nets against
their open invoices; any leftover stays as a standing credit.

## 3. The negotiated extras (recovery clauses)

Real mall leases negotiate terms on top of the raw pass-through. Atriom supports:

- **Admin fee** — a management fee the landlord charges on top of the recovered cost (default 10%,
  14% VAT), billed to its *own* revenue account. It's *margin the landlord sells*, kept separate from
  the cost pass-through.
- **Cap** — a ceiling on how much CAM cost a tenant bears in a year (a `LeaseCamTerm`: a flat amount,
  or last year's grown by a % — the "controllable expense" ceiling anchors negotiate). The cap trims
  the tenant's true-up + fee; the landlord **absorbs** the excess. The pool's cost pass-through still
  balances to the penny — the cap never changes what the books tie out against.
- **Recovery VAT** — per pool (default **14%**, matching the monthly estimate; set 0% only for a
  genuinely non-taxable pass-through). *(Close-out fix: the recovery used to bill at 0% while the
  monthly estimate was 14% — an inconsistency that under-collected output VAT. The true-up is more
  consideration for the same taxable supply, so it now carries the same VAT.)*

## 4. Correcting a reconciliation

The most common real-world CAM event is **a late vendor invoice** that changes the actual figure
*after* you've already billed some tenants. So:

- Once any allocation is **billed**, the pool figures **freeze** (you can't silently re-cut the pie
  while some slices are already served).
- To correct: **Void (un-bill)** the affected allocations. That reverses their recovery
  invoice / credit note / fee invoice and returns them to *pending* — which unfreezes the pool. Fix
  the actual figure, re-generate, re-bill. *(A recovery invoice the tenant already paid must be
  refunded first.)* *(Close-out fix: the freeze guard told operators to "void the billed allocations
  first," but that action didn't exist — now it does.)*

## 5. The guardrails

- **Only active leases of this mall** share the pool; the shares always sum to 100%.
- **The share basis freezes on the first run** — correcting a unit's area (or removing a tenant)
  mid-reconciliation can't silently re-weight the tenants already billed.
- **No double-billing** — re-running the reconciliation never re-touches an already-billed allocation.
- **The cost pass-through always ties out**: the sum of every tenant's raw cost share equals the
  mall's actual CAM spend, regardless of caps, fees, or VAT (those ride on top or trim the true-up,
  never the pass-through).
- **Who can act**: only staff with the CAM permissions can generate / bill / void. A read-only
  auditor or the owner can *see* the reconciliation but can't run it. *(Close-out fix: those write
  actions were dispatchable by a read-only user via a crafted request — now hard-gated.)*

## 6. What's deferred (and the trigger)

- **Gross-up / alternative bases / per-lease exclusions** (the recovery engine's slice 3). Today the
  share is by *occupied* area, so occupied tenants absorb the whole cost including empty units — the
  opposite of a *gross-up* clause (which caps a tenant's vacancy absorption). Fine for a near-full
  mall, and per-lease **caps already protect tenants**. *Trigger: the first lease that negotiates a
  gross-up %/GLA basis, or the mall running materially below full occupancy for a year.*
- **Estimate derivation** — today the year's *estimated collected* is a typed pool figure split by
  area; deriving each tenant's estimate from what they *actually* paid would make per-tenant true-ups
  exact for tenants on different service-charge rates. *Trigger: multi-rate tenants disputing their
  true-up.*
- **Move-in / move-out proration** — a mid-year mover bears a full-year share today. *Trigger:
  frequent mid-year churn.*
- **A tenant-facing reconciliation statement PDF** (the full pool→share→true-up breakdown). *Trigger:
  a tenant who wants the workings before paying.*
