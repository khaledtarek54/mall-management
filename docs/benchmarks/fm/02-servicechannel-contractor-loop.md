# ServiceChannel — the contractor-loop yardstick

> **What this file is for.** Maximo tells you what a job *is* and what it *costs*. ServiceChannel
> tells you how an operator who does not employ the tradespeople actually gets work done and keeps
> control of the money — which is Atriom's business exactly: Eltizam runs malls it does not own,
> executes through a contractor network, and the work is largely raised by retail tenants.
>
> The single idea to take from this file is in §3: **the money decision happens BEFORE the work,
> not after the invoice.**

> ### ✅ THE PARENTHETICALS ABOUT ATRIOM ARE OLDER THAN THE CODE — re-verified 2026-09-01
>
> **This file is a description of ServiceChannel, and that half is unchanged.** What went stale is
> the handful of bracketed asides measuring Atriom against it, every one of which was written before
> the facility close-out of **2026-08-20** and the vendor portal of **2026-08-28**. Two of them are
> now simply false: §3 calls the money control *"the one Atriom has no equivalent of"* and *"the
> before control … is missing"*, and §7 says the scorecard *"lacks the spend spine"*. A reader who
> stops at either sentence would set out to rebuild a control this system has had for a fortnight —
> which is the exact failure [the gap analysis's one rule](../../gap-analysis/README.md#the-one-rule-this-document-is-written-under)
> is written against: *a gap row is a claim about code.*
>
> Each of those asides now carries a **CLOSED** note naming what closed it. The original sentences
> stay, because they are the case that was made at the time and the reasoning is what a future
> change has to respect. Where this system deliberately **deviates** from the benchmark — a bill over
> an approved proposal is shown and never blocked, the vendor picker groups rather than filters, and
> the trade→provider assignment is portfolio-wide rather than per mall — the deviation is stated
> where a reader meets it rather than left to be re-derived. **One genuine gap survives and is
> marked as such in §4:** nothing records *verified arrival* on site, and that has never been
> declined. The scenario walk that exercises all of this is [03-scenarios.md](03-scenarios.md).

---

## 1. The shape of the platform

Three parties, one object:

```
        Operator / landlord                    Contractor ("provider")
        raises, approves, pays                 accepts, quotes, executes, invoices
                       \                      /
                        \                    /
                          the WORK ORDER
                                 |
                            Retail tenant
                            raises, tracks, confirms
```

**Every party sees the same work order, and each sees a different face of it.** That is the
structural difference from a CMMS where the contractor is a column: here the provider has a login,
a queue, and obligations that the system measures. The operator's leverage is that the provider's
next dispatch depends on their performance on this one.

> **CLOSED 2026-08-28 — the contractor stopped being a column here too.** `/vendor` is a panel and a
> login of its own ([`VendorPanelProvider`](../../../app/Providers/Filament/VendorPanelProvider.php),
> authenticating a `VendorContact` against its own password-reset table, because one person can be
> both a retailer's staff member and a contractor's contact and a shared reset table lets one request
> consume the other's token) where a contractor signs in and sees a queue of exactly the jobs
> dispatched to their company. The one
> rule is [`App\Support\Filament\VendorScope`](../../../app/Support/Filament/VendorScope.php): a
> contractor may only ever see or touch a job dispatched to them, enforced in three layers of which
> only the third is a gate — the panel query narrows to `vendor_id`, the UI shows what it returns,
> and every action re-checks server-side and **404s**, because a 403 confirms the job exists. With
> nobody signed in the scope matches **nothing** (`whereRaw('1 = 0')`), never everything: a scope
> that widens when the guard is empty is how a portal leaks its whole table to an unauthenticated
> request. Property isolation deliberately does **not** apply — a contractor is scoped to their
> dispatches, which may span malls, and a property scope would hide half their work the day they are
> used at a second mall. See [modules/12b](../../modules/12b-VENDOR-PORTAL-DESIGN.md).

**The location is the spine of the data model**, not the asset. A retail/mall FM platform is
organised around *this store, this mall, this trade* — because that is how spend is budgeted,
compared and argued about. Asset-level tracking exists and is secondary. *(This is the mirror image
of Maximo, and the reason both files are here.)*

---

## 2. Trades, categories and the taxonomy that routes the work

Work is classified on two axes before anything else happens:

| Axis | Example | What it drives |
|---|---|---|
| **Trade** | HVAC · plumbing · electrical · doors · refrigeration · janitorial | Which providers are eligible; which SLA applies; how spend is reported |
| **Problem code / category** | "no cooling" · "leak" · "lights out" | The description a non-technical caller can actually pick, and the routing |

**A tenant picks a problem, not a trade.** The shop manager reporting "it is hot in here" cannot be
asked whether that is HVAC or electrical, and a system that asks gets the wrong answer half the
time. The problem code maps to a trade; the operator can override.

**The trade → provider assignment is per location.** The HVAC contractor at one mall is not the
HVAC contractor at another, and the assignment carries the terms: rates, SLA, NTE.
*(cited — provider assignment by location/trade is core to the platform's setup.)*

> **CLOSED 2026-08-20 for the taxonomy, with one stated simplification for the assignment.**
> [`App\Models\Trade`](../../../app/Models/Trade.php) is a row, and it is the spine of the facility
> model: it classifies work orders, service plans **and** equipment, `vendor->trades()` says who may
> be dispatched to it, and its `standard_hourly_rate` is the craft rate the work-order cost object
> prices in-house labour at. The two axes are separate here exactly as they are in the benchmark —
> a tenant picks a **problem**, and `RaiseCorrectiveWorkOrderService::tradeForRequest()` resolves
> the trade from `tenant_request_subcategories.trade_id`, a foreign key since EG-14 rather than a
> name match, yielding **null** for a noise complaint because that is not maintenance at all.
> Eligibility is a suggestion and compliance is the gate: `Vendor::assignableOptions()` *groups* the
> picker (*"Does this trade"* / *"Other vendors"*) and never filters, a stated deviation from
> ServiceChannel, because Filament validates a `Select` with `Rule::in` and refusing an
> unusual-but-legitimate pick is the worse failure; `Vendor::isDispatchable()` is the only hard block.
>
> **The assignment is portfolio-wide, not per mall — a stated simplification, not an oversight.**
> The `trade_vendor` pivot carries no `asset_id` and `trades.default_nte` is one global ceiling, so
> *"the HVAC contractor at one mall is not the HVAC contractor at another"* is not currently
> representable. That is the right shape for one operator running its malls off a single contractor
> bench. If a second mall ever contracts a different firm the fix is a nullable `asset_id` on the
> pivot, with the picker's grouping asking the job's own property first — the same
> lease → property → portfolio tier `PropertySettings` already uses, not a rebuild.

---

## 3. NTE and the proposal loop — **the money control**

This is the section that matters most, and the one Atriom has no equivalent of.

> **CLOSED 2026-08-20 — the whole of §3 except the rate sheet, which is §3.3's own note.** The
> before-the-money control shipped as
> [`Concerns\FacilityWorkOrder\ControlsSpendAgainstNte`](../../../app/Models/Concerns/FacilityWorkOrder/ControlsSpendAgainstNte.php)
> plus [`WorkOrderProposal`](../../../app/Models/WorkOrderProposal.php) and
> [`WorkOrderProposalService`](../../../app/Services/WorkOrderProposalService.php), over migration
> `2026_08_20_500000_not_to_exceed_and_proposals`, and the contractor has submitted their own quote
> from the vendor portal since 2026-08-28. The sentence above was true when it was written and has
> not been true since; the notes under each sub-section say what took its place, and the one thing
> this system deliberately does **not** do is hold the invoice.

### 3.1 Not-to-exceed

Every work order carries an **NTE amount** — the maximum the provider may spend without coming
back for approval. It is set from the trade/location default and can be overridden per job.

- Work **at or under NTE**: the provider proceeds and invoices; the invoice is checked against the
  NTE automatically.
- Work **expected to exceed NTE**: the provider must submit a **proposal** *before* doing the work.

**The whole point is that the decision is made before the money is spent.** An operator who only
sees the number on the invoice is negotiating after the work is done, which is not negotiating.
Atriom's three-way match (`PurchaseRequest::billingVariance()`) is the *after* control and is
correct; NTE is the *before* control and is missing.

> **CLOSED 2026-08-20 — `facility_work_orders.nte_amount` is the ceiling.** It is defaulted from
> `trades.default_nte` **when a job is raised and never afterwards**, which is the judgement worth
> keeping: changing a trade's default must not silently re-authorise every job already open in it.
> An explicit amount on the form always wins, and a trade with no default leaves the job with no NTE
> at all — honest, where a 0 would read as *"may spend nothing"*. `overNteBy()` answers how far
> actual cost has passed the ceiling and `scopeOverNte()` is its query twin, so the badge on the
> work-order register, the filter beside it and any future report cannot drift from one another.
> The ceiling is per trade rather than per trade-and-location, for the reason recorded under §2.

### 3.2 The proposal

A proposal is a structured quote: labour hours × rate, materials, subcontract, tax — not a number
in an email. The operator can approve, reject, or approve a revised figure; approval **raises the
NTE** on the work order and creates the commitment.

**Proposals are compared, not just accepted** where more than one provider is asked. This is the
retail-FM version of what the gap analysis calls O7 (bid comparison) and shows it is not only a
capex-procurement idea — it belongs on any job above a threshold.

> **CLOSED 2026-08-20, and the contractor files it themselves since 2026-08-28.** A
> [`WorkOrderProposal`](../../../app/Models/WorkOrderProposal.php) is the structured quote this
> section describes — labour, materials, subcontract and tax as separate buckets, refused outright
> if they sum to nothing, because a quote for zero would approve to an NTE of zero and read as *"may
> spend nothing"*. **A proposal IS the estimate**: its three buckets are the cost object's three
> buckets, so approving one makes planned-versus-actual mean *"did the contractor deliver what they
> quoted?"* rather than producing a second set of numbers about the same work. Approval **raises the
> NTE and never lowers it** — approving a cheaper revision must not quietly tighten what the
> contractor was already permitted for other work on the same job. **Who may approve depends on the
> amount, off the same ladder a purchase order uses** —
> `ApprovalPolicy::canApprove($user, ApprovalRule::MODULE_PURCHASE_REQUEST, $proposal->total_amount)`
> in [`WorkOrderProposalsRelationManager`](../../../app/Filament/Admin/RelationManagers/WorkOrderProposalsRelationManager.php) —
> so a coordinator cannot authorise work they could not have raised a purchase order for. A refusal
> requires a reason, because *"rejected"* with nothing written produces a phone call instead of a
> revised quote.
>
> **Comparison is real, and it turns on one distinction the first version missed.** A *full* quote
> is a revised whole price and therefore withdraws every other pending quote on the job — competing
> prices for the same work cannot both stand, which is §3.2's *"compared, not just accepted"*. A
> *supplementary* quote is extra work found after the wall came off, so it **adds** to the ceiling
> and the estimate and does **not** withdraw its siblings: two supplements for two different pieces
> of extra work are not alternatives to each other. Treating every quote as a replacement made an
> 8,000 supplement overwrite a 38,000 agreement and the job read as 38,000 overspent; that was found
> on the live database and fixed the same day (`b9477b44`).
>
> **Who submitted it is recorded as two columns, not one column with a type.**
> `submitted_by_user_id` means our staff keyed it on the contractor's behalf and
> `submitted_by_vendor_contact_id` means they sent it themselves from `/vendor` — two different
> questions, so deliberately not a morph. Note that the capex half of O7 is still open: a
> `PurchaseRequest` carries one vendor and no tender, so the remaining build is a quotes relation
> reusing this shape rather than the primitive itself.

### 3.3 The invoice

The invoice arrives against the work order and is checked against **the NTE, the proposal, and the
contracted rates** before it can be paid. A line at a rate not in the contract, or a total above an
approved proposal, is held rather than paid. *(cited — invoice validation against NTE and rate
sheets is a documented platform capability.)*

**The three checks are different and all are needed:**

| Check | Catches |
|---|---|
| Against NTE | Work that grew without permission |
| Against the proposal | An approved job billed for more than approved |
| Against the rate sheet | Correct hours at a rate nobody agreed to |

> **Two of the three land, the third has nothing to check against — and the "held rather than paid"
> half is a stated deviation.** *Against the NTE* is `overNteBy()`, surfaced as a red badge and a
> filter on the work-order register. *Against the proposal* is subsumed rather than separate:
> approval raises the ceiling **to** the approved figure, so a bill above an approved proposal is
> arithmetically a bill above the NTE and the same badge catches it. *Against the rate sheet* has no
> data to compare with — `vendor_contracts` carries a value, a scope and an SLA penalty rate but no
> labour rate, and the `trade_vendor` pivot is a bare eligibility link, while `trades.standard_hourly_rate`
> is the **internal** craft rate the labour channel of the cost object prices our own engineers at,
> not a contracted rate the supplier agreed to. It is deliberately not built on spec: Egyptian
> facility retainers are commonly lump-sum, which `vendor_contracts.value` already models, so the
> question is whether this operator contracts on rates at all before a rate-sheet engine is
> justified.
>
> **A bill over an approved proposal is SHOWN, never blocked.** That is a stated deviation from
> ServiceChannel, which holds the invoice, and it is the settled position for the same reason
> `PurchaseRequest::billingVariance()` does not block a three-way match: a job legitimately grows
> for something nobody could have proposed for, and **holding a supplier's invoice inside a system
> the supplier cannot see turns a commercial conversation into a payment failure**. The control is
> that a proposal should have come first; the enforcement is that the breach is visible and
> attributable, on the register where the operator decides. Do not convert it to a hard block
> without reopening this decision.

---

## 4. Dispatch and the provider's obligations

1. **Issue** — the work order is dispatched to a provider with a trade, a priority, an NTE and an
   SLA clock.
2. **Accept** — the provider acknowledges. Not accepting is itself a measured failure; the clock
   does not wait for goodwill.
3. **Check in on site** — via mobile/IVR, with location verification. This is what turns "we
   attended" into evidence.
4. **Work** — notes, photographs, parts used.
5. **Check out** — with a resolution and, where required, the tenant's confirmation.
6. **Invoice** — §3.3.

**Check-in/check-out is the mechanism that makes the SLA real.** Response time measured from a
column somebody edits is measured from when the contractor says they arrived. Measured from a
check-in with a location and a timestamp, it is measured from when they arrived. *(verify — the
verification method and its strictness are configuration-sensitive.)*

> **Steps 1, 2, 4 and 6 are closed; steps 3 and 5 are the one real gap left in this file, and it is
> not a declined one.** *Issue* carries all four of the things this step names: the priority and the
> SLA clock predate the close-out, and the trade and the NTE are what 2026-08-20 added. *Accept* is
> the substance of the vendor portal (2026-08-28):
> [`AcceptWorkOrderService`](../../../app/Services/AcceptWorkOrderService.php) stamps
> `acknowledged_at` under a row lock and refuses on a terminal job, which turns the response clock
> from *when a coordinator updated a column* into *when the contractor agreed* — exactly the
> distinction this paragraph is about. *Work* is the contractor's own notes and evidence, and their
> comments are always public, because `is_internal` is the operator's tool for writing what the
> contractor must not read. *Invoice* stays with the operator: a contractor's bill is an accounting
> document with a tax code and a GL consequence, so self-invoicing is deliberately excluded.
>
> **Nothing records verified arrival.** There is no `arrived_at` anywhere in the model and no on-site
> state — `FacilityWorkOrder::STATUSES` is `open · in_progress · done · cancelled` — so the response
> SLA is measured to acceptance and the resolution SLA to completion, with the site visit itself
> unevidenced. §8 declines the **IVR mechanism** and explicitly keeps the control (*"a mobile
> check-in with a timestamp is the same control"*), so this has never been declined; it is simply
> not built. Now the `/vendor` panel exists it is one more verb of the same shape as accept —
> idempotent, lock-safe, callable from the admin panel too — and it should stay **evidence, not a
> transition**: nothing should gate on it, and it must not drift into a check-out that lets the
> contractor close the job (§6's note, and [modules/12b](../../modules/12b-VENDOR-PORTAL-DESIGN.md)).

**Recall / repeat-visit tracking**: a second work order at the same location, same trade, same
problem within a window is flagged as a repeat. This is one of the highest-value cheap signals in
retail FM — it identifies the fault that was never actually fixed and the provider who keeps
returning to bill twice.

> **CLOSED 2026-08-20.**
> [`Concerns\FacilityWorkOrder\RecordsFailuresAndRepeats`](../../../app/Models/Concerns/FacilityWorkOrder/RecordsFailuresAndRepeats.php)
> matches the same machine — or the same shop where no machine is named — and the same trade inside
> a 30-day window, and **refuses to match on nothing**: without that guard every common-area job
> would repeat every other job in its trade. A planned follow-up is excluded, because a job raised
> deliberately as the second half of the first is not a failure to fix it, and a preventive job is
> never a repeat of anything since it happened because a schedule said so. It surfaces as a badge on
> the register and as `repeat_visits` on the vendor scorecard, which is this paragraph's actual
> point: the provider who keeps coming back to bill twice.

---

## 5. Compliance — the dispatch gate

Providers carry **compliance documents** — insurance certificates, licences, tax registration,
safety accreditation — each with an expiry. A provider out of compliance is **not dispatchable**.

*(Atriom already implements exactly this: `VendorDocument` + `Vendor::isDispatchable()`, and the
gap analysis correctly records it as at parity. It is noted here because it is the one place a
hard block is right — there is a genuine dispatch decision to gate — and because §9 of the Maximo
file and this section together define what "the contractor may not start" means.)*

---

## 6. The retailer's own view

The tenant is a first-class user, not a form:

- Raise a request with a problem code, a photograph and a location within their own store
- **See status without telephoning** — the single largest driver of call volume in mall operations
- Confirm completion, or reject it and reopen
- See their own history

**A tenant confirming completion is a control, not a courtesy.** It is what stops a job being
closed by the person who was paid to do it.

> **CLOSED, and it is the reasoning that later settled the contractor portal's scope.**
> `TenantRequestService::confirmResolution()` writes `confirmed_at` and
> `confirmed_by_tenant_user_id` — *which* portal user accepted, not merely that somebody did — and
> refuses on a request that is not resolved; rejecting reopens the job on the same clock. The same
> sentence is why **marking a job done is deliberately not one of the contractor's four verbs**
> (accept · update · evidence · quote): a contractor saying *"finished"* is a **claim**, the
> operator's completion is a **decision**, and `facility.complete` runs a checklist gate, an
> evidence gate and the cost object before it will accept one. Evidence from the portal **appends
> and never replaces**, for the same reason — the completion gate reads that collection, so a
> replace would erase what an earlier decision rested on.

---

## 7. Spend analytics — what the model is for

Because every job carries a location, a trade, an NTE and an invoice, the platform answers:

| Question | Why an operator asks it |
|---|---|
| Spend by location, trade, provider, period | Budget, and the owner's questions |
| Spend per m² | The only fair comparison between a 90 m² unit and a 900 m² anchor |
| Invoices held, and why | Where money is stuck and who must decide |
| Repeat visits by provider and problem | The fault that was never fixed |
| Proposal turnaround and approval rate | Whether the operator's own approvals are the bottleneck |
| Provider scorecard — SLA %, first-time-fix, cost vs peers | The next dispatch decision |

*(Atriom has `VendorScorecardService` and a screen for it; what it lacks is the spend spine —
because spend per location/trade needs the cost object from the Maximo file plus the trade
taxonomy from §2 here.)*

> **The spine shipped on 2026-08-20, and what remains is a smaller and different claim.** Both
> halves this parenthetical was waiting on are in: `FacilityWorkOrder::recomputeCosts()` gives every
> job an `act_total_cost` (labour hours × the craft rate frozen at entry, approved part draws, and
> vendor bills plus expenses carrying `facility_work_order_id`), and `trade_id` is on the job beside
> the property and the unit. So spend **is** attributable by location, trade, vendor and period: the
> work-order register carries a `Sum` summariser over `act_total_cost` with a trade filter beside it,
> and the equipment register answers *"what has this chiller cost us?"* with
> `withSum('workOrders', 'act_total_cost')`. Note the cost object is deliberately **not** a GL
> source — the money is already in the ledger through inventory, AP and payroll, so a journalizer
> would post every maintenance cost twice *and balanced* — which means a report may not add
> work-order labour to the wage bill; it **explains** part of it.
>
> **What the scorecard is still missing is three metrics, not the spine underneath them.**
> [`VendorScorecardService`](../../../app/Services/Reports/VendorScorecardService.php) returns
> `work_orders · completed · open · avg_response_hours · avg_resolution_hours · sla_breaches ·
> repeat_visits · penalties_applied · penalty_total · expired_documents · dispatchable` — so of this
> table it answers the scorecard's SLA half and *"repeat visits by provider"*, and does not answer
> **cost vs peers** (now a sum over the cost object), **first-time-fix** (derivable from the repeat
> count it already computes) or **proposal turnaround and approval rate** (`WorkOrderProposal`
> carries `submitted_at` and `decided_at`). All three are columns on the existing screen rather than
> a new page. **Spend per m² exists nowhere** and is the one genuinely new build in this table: it
> needs a unit-area join and an editorial decision about which denominator is fair, so it is
> recorded here rather than assumed. *"Invoices held, and why"* has no counterpart by design and
> never will: nothing is held (§3.3), so the question this system answers instead is *which jobs
> outran their ceiling*, which is the `over_nte` badge and filter on the register.

---

## 8. What NOT to copy

Written explicitly, because a benchmark read uncritically is how a system grows features nobody in
this market wants:

- **The provider marketplace.** ServiceChannel monetises a network of contractors that operators
  can shop across. Eltizam has its own vendor list, and building a marketplace for one operator is
  building an empty room.
- **Invoice financing / payments rails.** A US-market product wrapped around ACH. Egypt's AP runs
  through the operator's bank and its own cheque practice, which Atriom already models (module 33).
- **Refrigerant tracking as a first-class module.** It exists because of US EPA reporting
  obligations. Egypt's equivalent obligations are not the same and should not be assumed.
- **IVR check-in.** The mechanism is a 2005 answer to a phone-only workforce. The property that
  matters is *verified arrival*; a mobile check-in with a timestamp is the same control.

---

## Sources

- ServiceChannel Support Centre and product documentation: Work Order lifecycle, Proposals, NTE,
  Invoice management, Compliance Manager, Provider Search & Scorecards, Analytics.
- ServiceChannel public API documentation (work order, proposal and invoice objects) for the
  object model in §3.
- Corrigo (JLL) and Facilio product documentation, consulted for corroboration on the dispatch and
  tenant-facing loop; cited in the gap analysis where either leads.
- Concepts marked ***(verify)*** are configuration-sensitive.
