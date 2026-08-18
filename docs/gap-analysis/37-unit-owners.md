# Module 37 — Unit Owners (مُلّاك الوحدات) · gap analysis

> **Round 3, 2026-08-18.** Audited across the whole round rather than in one sitting, because its
> defects surfaced from *other* modules' audits — which is itself the finding. Method:
> [000-plan.md](000-plan.md).

## 1. Verdict

**The module with the most defects in round 3, and none of them were inside it.**

Module 37 introduced a second kind of occupier: a party who buys a shop, trades from it, pays a
monthly صيانة, and holds **no lease**. Its own internals are sound — the register, the tenure rules,
the assessment arithmetic, the resale certificate and the GL treatment all held under audit. What
failed, repeatedly, was **everything built before it that had been entitled to assume a lease**.

Five separate defects, found from four different directions:

| # | Defect | Found while auditing | Fixed in |
|---|---|---|---|
| 1 | **Owners were never billed at all** — `BillUnitOwnershipsService` had no command, job, schedule or button; only the demo seeder and tests called it | the UI/UX reachability sweep | `eaab8027` |
| 2 | **A unit could be resold with no way to record it** — `TransferUnitOwnershipService` had only tests | same sweep | `eaab8027` |
| 3 | **An owner-occupier could be fined and never billed** — the violation fine resolved an active *lease* and refused without one | module 31 | `9b83333d` |
| 4 | **An owner's bounced cheque fee was unbillable** — same lease-shaped lookup | module 33 | `7f0c2496` |
| 5 | **An owner in arrears was not "delinquent", and his invoice showed no unit** — report and filter sites still inferring property/unit through the lease | the lease-shaped sweep | `3a967536`, `61bb1dc6` |

Plus one inside the module: **`management_fee_pct` is configured, described as charged, and never
charged** — a legitimate deferral (blocked on an accountant's GL answer) that the *screen* did not
admit to. Fixed by making the helper honest; see [module 32's F-C](32-owner-statements.md).

## 2. The pattern, stated once

`invoices.lease_id` was NOT NULL until this module shipped. [Plan 08 §5.2b](../plans/08-unit-owners.md)
named the lesson at the time:

> *Relaxing a NOT NULL is never only a schema change — it is a change to every inference that column
> licensed.*

Phase 2a acted on it for the four load-bearing sites (isolation, GL dimension, numbering, levy) by
denormalising `invoices.asset_id`. **The lesson was right and the sweep was too narrow.** It covered
the writers and left the readers, the collection screens, and every *other* service that resolved a
debtor's agreement by looking for a lease.

The tell was consistent: none of these five failed loudly. An unbilled owner produces no error, a
refused fine looks like a validation message, a missing delinquency flag looks like a solvent tenant,
and a blank unit column looks like missing data. **A party type the system half-knows about is
invisible in exactly the places it matters.**

## 3. Verified clean (the module's own internals)

| Hypothesis | Result |
|---|---|
| The assessment double-bills on re-run | **False** — one lock per period plus a per-ownership overlap probe inside the transaction |
| A resale lets the buyer inherit the seller's arrears | **False** — the certificate states the outstanding figure and the transfer refuses over it unless explicitly allowed; arrears stay on the seller's own ledger |
| A resale can run backwards, or twice | **False** — tenure inversion refused, terminal tenure refused, the seller's row closed rather than deleted |
| A retailer can be recorded as a unit owner | **False** — refused; the party must be `party_type = unit_owner` |
| An owner's assessment escapes property isolation | **False** — `unit_ownerships.asset_id` is its own column, and the invoice carries one too since phase 2a |
| An owner is invisible to the tenant picker | **False** — `OptionDisplay` scopes on properties a party leases **or owns** in |

## 4. What remains

- **The management fee** (§1) — blocked on two accountant answers, not on code. The screen is now
  honest about it and a characterisation test fails the day it is built.
- **An owner-occupier cannot hold a parking bay** — recorded in [module 35](35-rentable-items.md).
  Unlike the five above this is *not* the same bug: the relationship itself is lease-keyed, so it
  needs a migration and a product decision, not a lookup fix.
