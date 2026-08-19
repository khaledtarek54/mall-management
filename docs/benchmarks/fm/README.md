# Facility & operations — the work-management benchmark

> **Why this folder exists.** `docs/benchmarks/yardi/` was written because the lease → charge →
> invoice → GL chain had been built from the operator's requirements rather than from a reference
> implementation, and *if the spine is modelled on the wrong business, every module downstream
> inherits the error*. Facility and operations were never given the same treatment. Modules 26, 11,
> 12, 30, 22, 23 and 29 were built from the FRD and from what an operator asked for next, and the
> gap analysis compared them to "the FM specialists" — **in prose, with no document behind it.**
> That makes every facility claim in it something to be believed rather than checked, which is
> exactly the condition the Yardi folder exists to end.
>
> This folder is the yardstick. It says how the market leaders actually work, in enough detail that
> a claim about Atriom can be tested against it.

---

## The two benchmarks, and why it is two

| Layer | Benchmark | Why this one |
|---|---|---|
| **The work-and-asset core** — what a job IS, what it costs, what it was done to, and what that says about the asset | **IBM Maximo** (Manage / MAS 8) | The reference implementation for work management, and the one the whole industry's vocabulary comes from: job plans, work order hierarchy, failure hierarchy, PM routes, meters, storerooms, crafts. Critically, it is the standard for **the work order as a cost object** — planned vs actual, split by labour / material / service / tool — which is the "closed with money" half |
| **The contractor and tenant-facing loop** — dispatch, spend control, compliance, and the retailer's own view | **ServiceChannel** (Fortive) | Atriom's business *is* ServiceChannel's shape: an operator running properties for owners, executing through a contractor network, with retail tenants raising the work. Its proposal → NTE → work order → invoice loop is the strongest AP-side spend control in FM, and its compliance gate is the one Atriom already copied |

**Why not one.** The same reason `docs/benchmarks/yardi/` names Re-Leased and Smart Lease on
document generation: Voyager is the leasing yardstick and is *not* the leader on that row, and
pretending otherwise would make the benchmark lie. Maximo is thin on the landlord↔contractor↔
retailer loop that is most of a mall operator's day; ServiceChannel is thin on asset reliability
and the cost object. Naming both, with the boundary stated, is more honest than forcing one.

**Where a third is named:** **Facilio** for the GCC mall context (tenant app, energy-led O&M) and
**Corrigo (JLL)** for the CRE contractor network, cited where they lead. Neither is a primary.

---

## The files

| # | File | What it answers |
|---|---|---|
| 01 | [Maximo — work & asset](01-maximo-work-and-asset.md) | The asset hierarchy, job plans, the work order as a **cost object**, labour and crafts, failure hierarchy, PM routes and meters, storerooms and issues, safety plans and permits, status lifecycle |
| 02 | [ServiceChannel — the contractor loop](02-servicechannel-contractor-loop.md) | Trade/category taxonomy, dispatch and the provider network, **NTE and the proposal→approval→invoice loop**, check-in/check-out, compliance, the retailer's own portal, spend analytics |
| 03 | [Scenarios](03-scenarios.md) | End-to-end operational scenarios with real numbers — what the standard does, what Atriom does today, what breaks |

> **The Atriom-vs-standard verdict is NOT in this folder.** It lives in
> [docs/gap-analysis/README.md §4](../../gap-analysis/README.md), for the same reason the Yardi
> verdict does: one gap analysis that is current beats several that disagree. This folder is the
> *yardstick*, so a claim there can be checked rather than believed.

---

## Sourcing & confidence

Same convention as the Yardi folder. Neither vendor publishes a complete open manual, so every
claim carries its layer:

| Layer | Marking | What it means |
|---|---|---|
| **Verified** from the vendor's own public documentation | *(cited)* | Named in §Sources of the file that uses it. Maximo's application help and data dictionary are public; ServiceChannel publishes a support centre and API docs |
| **Product knowledge**, stable across versions | unmarked | Concepts that have been in the product for a decade or more (Maximo's work order cost fields, ServiceChannel's NTE) |
| **Version-, edition- or configuration-sensitive** | ***(verify)*** | Believed true, but confirm against a live tenant before designing to it |

**The Atriom side of every comparison is grounded in the code**, with file references — never from
memory, and never carried forward from an older analysis without re-reading the source.

---

## The one thing to read if you read nothing else

Both benchmarks agree on a single structural point that Atriom does not currently implement, and
everything else in the facility gap is downstream of it:

> **The work order is the cost object.**

In Maximo a work order carries planned and actual cost, split by labour, material, service and
tool, and rolls up its children; those totals roll to the asset and to the location, which is what
makes "what has this chiller cost us", "what is our maintenance cost per m²" and "repair or
replace" answerable. In ServiceChannel the same object carries the NTE, the proposal, the invoice
and the resulting spend per location.

In Atriom the work order carries `job_value` — a single number that exists only to feed the
SLA-penalty percentage basis — and is **not** a GL source. Parts post through `StockMovement`,
external work posts through `VendorBill` or `Expense`, and nothing ties them back to the job. The
money is all correctly in the ledger; what is missing is that **the job cannot say what it cost.**
