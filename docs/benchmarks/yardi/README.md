# Yardi Voyager Commercial — the leasing & money-flow benchmark

> **Why this folder exists.** Atriom's leasing module and the money flow that hangs off it were
> built from the operator's requirements, not from a reference implementation. The owner's concern
> (2026-08-08) is the one worth having: *if the lease → charge → invoice → GL chain is modelled on
> the wrong business, every module downstream of it inherits the error.* So before another line is
> written on top of it, this folder writes down how the market leader actually does it, in enough
> detail to be checked against — and then says where Atriom is wrong, where it is merely thin, and
> where it is deliberately and correctly different.
>
> **Benchmark:** **Yardi Voyager Commercial** (+ the Commercial Suite around it — Deal Manager,
> Forecast Manager, Smart Lease). It is the software a mall operator would actually be measured
> against, and its lease-administration + recoveries engine is the most complete in the market.

---

## The files

| # | File | What it answers |
|---|---|---|
| 01 | [Lease administration](01-yardi-lease-administration.md) | How Yardi models a lease: entities, the lease record, charge schedules, escalations, amendments, options & critical dates, the deal pipeline |
| 02 | [Money flow](02-yardi-money-flow.md) | Charge → AR → cash → GL. Posting, receipts, open credits, deposits, write-offs, post month, books, straight-line rent, month-end |
| 03 | [Recoveries & percentage rent](03-yardi-recoveries-percentage-rent.md) | Expense pools, gross-up, caps, base years, admin fees, estimate→reconcile→re-estimate; sales, breakpoints, overage, settle-up |
| 04 | [Scenarios](04-scenarios.md) | 15 end-to-end scenarios with real numbers — what Yardi does, what Atriom does today, what breaks |
| 05 | [User stories](05-user-stories.md) | The backlog, as user stories with acceptance criteria, by role |
| 06 | [Gap analysis](06-atriom-gap-analysis.md) | Row-by-row Atriom vs Yardi, with a **keep / extend / rebuild** verdict and severity on each |
| 07 | [Phase plan](07-phase-plan.md) | **The recommendation** — what to do in this cycle, in what order, and what to leave alone |
| 08 | [UI/UX](08-yardi-ui-ux.md) | What to copy from Yardi's screens (the information architecture) and what not to (the look — 77% of its usability reviews are negative), as 13 concrete Filament stories |
| 09 | [Space, floors & parking](09-yardi-space-and-parking.md) | How Voyager separates **spaces** (lettable, in GLA) from **rentable items** (parking, storage — billable, NOT in GLA), and why floor stays an attribute rather than becoming an entity. Written before the code, because the first cycle never researched parking |

If you read one file, read [07-phase-plan.md](07-phase-plan.md). If you read two, read
[06-atriom-gap-analysis.md](06-atriom-gap-analysis.md) first. For the screens, go to
[08-yardi-ui-ux.md](08-yardi-ui-ux.md) — its headline is that **Atriom should reach Yardi's
completeness on Filament's better-looking surface, not import Voyager's interface**, which Yardi's
own users rate poorly and which Voyager 8 is itself moving away from.

---

## Sourcing & confidence

Yardi does not publish its user guides openly, so this folder is built from three layers, and
every claim carries its layer:

| Layer | Marking | What it means |
|---|---|---|
| **Verified this session** from Yardi's own material or public partner documentation | *(cited)* | Named in §Sources of the file that uses it |
| **Product knowledge**, stable across versions | unmarked | Concepts that have been in Voyager Commercial for a decade+ (charge codes, post month, recovery pools) |
| **Version-, edition- or configuration-sensitive** | ***(verify)*** | Believed true, but confirm against a live Voyager 8/9 tenant before designing to it |

This is the same convention as [`docs/gap-analysis/competitors/`](../../gap-analysis/competitors/README.md).
**The Atriom side of every comparison is grounded in the code**, with file references — it is not
from memory, and it corrects two rows that the July 2026 competitor analysis got wrong (CAM caps
and automated escalation both exist now).

---

## The headline, in one page

**Yardi's leasing engine is built on one idea Atriom does not have: the lease is a *schedule*, not
a *state*.**

In Voyager, a lease carries a set of **date-ranged charge rows** — `RENT 100,000 from 01/01/2026
to 31/12/2026`, `RENT 107,000 from 01/01/2027 to 31/12/2027`, `CAMEST 8,400 from 01/01/2026 to
31/12/2028`. The whole future of the tenancy is written down on day one. Billing is then a pure
read: *"which rows cover this month?"*. Everything else Yardi does well falls out of that one
decision:

- **Forward visibility** — next year's rent is a fact, not a projection.
- **Historical truth** — what the rent *was* in March 2026 is still readable in 2029.
- **Straight-line rent** — you cannot straight-line revenue you cannot see ahead.
- **Rent roll / forecast** — both are just aggregations of the schedule.
- **Escalation** — generates the next row; it never destroys the current one.
- **Amendments** — a mid-term change closes one row and opens another, with a reason.

**Atriom stores the current state and mutates it.** `charges` holds one active row per type;
`LeaseRentChangeService` overwrites `amount`; `RentEscalationService` overwrites it again every
year and stamps a line into `leases.notes`. The system knows what the rent *is* and has no
structured memory of what it *was*, nor any knowledge of what it *will be*.

**But — and this matters for the size of this cycle — the storage already supports the fix.**
`charges` has `start_date` and `end_date`, and `MonthlyBillingService::chargeAppliesToPeriod()`
([`MonthlyBillingService.php:402`](../../../app/Services/MonthlyBillingService.php#L402)) already
filters on them. Nothing *writes* a multi-row schedule, and three services assume a single mutable
row — but the billing read path would already do the right thing if one existed. **This is an
inversion of the write path, not a rewrite of the module.**

### What is genuinely wrong (rebuild the write path)

1. **No rent schedule** — the single structural defect; §1 of the gap analysis.
2. **No lease events / amendments** — a mid-term change is a `notes` string.
3. **No revenue recognition beyond "as billed"** — no straight-line rent, which EAS 49 / IFRS 16
   require of a lessor with escalating rent and rent-free periods. Needs the accountant's ruling.
4. **No options / critical dates** — renewal-option notice windows are money, and nothing tracks them.

### What is thin but sound (extend)

Percentage rent (no tiers, no YTD settle-up, no deductions) · recoveries (pool is hand-keyed, not
sourced from the GL; no gross-up; fixed denominator) · proration (start only, never end) · free
rent (all-or-nothing, can't say "rent-free but service charge payable") · holdover (alerted, not
billed) · move-out (no final account) · no rent roll report · no AR write-off.

### What is right — do not touch it

**The AR core.** `Invoice::recomputeTotals()` as the single source of truth, the durable
`credit_applied_amount`, the lock-and-recheck over-allocation guard, the credit-note
un-apply-the-original reversal, the GL registry with its conformance gate, the closed-period
posting guard. Several of these are *better* than what a mid-market Yardi deployment ships with,
and all of them are load-bearing. **The wrongness is upstream, in lease → charge. It is not
downstream, in charge → invoice → cash → GL.** Rebuilding the money core would destroy a year of
hard-won invariants to fix a problem that does not live there.

### The one deliberate difference to keep

**Yardi tracks AR at the charge level; Atriom tracks it at the invoice level.** In Voyager, each
posted charge is its own open receivable and a receipt is applied charge by charge. Atriom issues
one invoice per lease-month with items, and settles the invoice.

**Keep Atriom's model.** Egypt's ETA e-invoicing makes the *invoice* the legal document; an AR
model that has no invoice would have to invent one to file. The cost of the choice is real —
you cannot age or dispute "the CAM line" independently — and [§8 of the gap
analysis](06-atriom-gap-analysis.md) says how to buy back most of that at the item level without
abandoning invoice-level AR.

---

## Related reading in this repo

- [`docs/modules/04-leases.md`](../../modules/04-leases.md) — what Atriom's lease module does today
- [`docs/money/00-money-model.md`](../../money/00-money-model.md) — the AR invariant this cycle must not break
- [`docs/modules/21-general-ledger.md`](../../modules/21-general-ledger.md) — the GL registry any new money source must join
- [`docs/gap-analysis/competitors/`](../../gap-analysis/competitors/README.md) — the July 2026 capability sweep this folder supersedes for leasing & money flow
- [`docs/accounting/ACCOUNTANT-BRIEFING.md`](../../accounting/ACCOUNTANT-BRIEFING.md) — where the straight-line question goes for a ruling
