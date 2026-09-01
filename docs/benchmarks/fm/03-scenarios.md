# Facility scenarios — the standard vs Atriom today

> Same method as [`yardi/04-scenarios.md`](../yardi/04-scenarios.md): a real operational situation
> with real numbers, what the benchmark does, what Atriom does **today** (re-derived from code on
> 2026-08-19, with file references), and what breaks. The verdict and the ranked build order live in
> [the gap analysis](../../gap-analysis/README.md); this file is the evidence.

> ### ✅ ALL EIGHT ARE CLOSED — re-verified against the code 2026-09-01
>
> **Every "Atriom today" paragraph below describes the code as it stood on 2026-08-19, the day
> before the facility close-out shipped.** The whole of it landed on **2026-08-20** — labour records,
> trades, NTE and proposals, multi-stop routes, repeat detection, the tenant's confirmation and PM
> compliance — and this file was never updated, so it still reads *"there is no NTE anywhere in the
> codebase"* about a system that has had one for eleven days short of a fortnight when this note was
> written.
>
> Each scenario now carries a **CLOSED** note naming what closed it. The original paragraphs stay:
> the situations are still the right acceptance tests, and the reasoning is what a future change has
> to respect. The dangling reference to *"the gap analysis's O6"* in S3 is corrected — the ranked
> list was renumbered; [gap §4](../../gap-analysis/README.md#4-facility-vendors-assets--vs-the-fm-standard)
> carries the per-step evidence.

---

## S1 — The chiller that cost more than anyone knew

**Situation.** Chiller CH-02 at Atriom Walk had six corrective jobs this year: two in-house
(4 hours and 11 hours of the operator's own technicians), three contractor visits billed at
EGP 8,500 / 12,000 / 6,400, and EGP 3,200 of spare parts drawn from the store. The owner asks at
the annual review whether to overhaul it or replace it. Replacement is EGP 480,000.

**Maximo.** `acttotalcost` on each work order, rolled up to the asset:
labour EGP 4,500 (15 h × the craft rate) · material EGP 3,200 · services EGP 26,900 →
**EGP 34,600 this year**, against a replacement of EGP 480,000 and an asset that has now been down
four times. The repair-or-replace conversation has a number on both sides.

**Atriom today.** The parts are in `stock_movements` and posted to the GL
([`InventoryMovementJournalizer`](../../../app/Services/Accounting/Journalizers/InventoryMovementJournalizer.php)).
The contractor visits are in `vendor_bills` and posted. The in-house hours are **nowhere** — there
is no labour record on a work order at all. And nothing links any of it back to CH-02:
`facility_work_orders` has no cost column beyond `job_value`, which exists only to feed the
SLA-penalty percentage basis, and `FacilityWorkOrder` is **not** in `LedgerPoster::JOURNALIZERS`.

**What breaks.** The question is unanswerable. Every number exists in the ledger and none of them
can be attributed to the machine. The operator answers from memory, which is how a mall keeps a
chiller five years too long or replaces one three years too early.

> **CLOSED 2026-08-20.** `FacilityWorkOrder::recomputeCosts()` is Maximo's `acttotalcost`, written
> like `Invoice::recomputeTotals()`: one method, three channels that each call it — labour hours ×
> the craft rate (frozen at entry), approved part draws, and vendor bills plus expenses carrying
> `facility_work_order_id`. Net of VAT and of any applied SLA penalty, cancelled documents excluded,
> and a document moved between jobs recomputes the job it **left**. `job_value` is gone; the penalty
> basis now prefers the actual service cost once a bill lands.
>
> **The work order is a COST OBJECT and deliberately not a GL source** — the money is already in the
> ledger through inventory, AP and payroll, so registering a journalizer would post every maintenance
> cost twice *and balanced*. `WorkOrderIsACostObjectNotAGlSourceTest` gates exactly that. For the
> same reason a report may not add work-order labour to the wage bill; it **explains** part of it.

---

## S2 — The in-house team that looks free

**Situation.** The operator is deciding whether to keep two electricians on staff or move to a
contract. In-house handled 180 jobs last year; the contractor quotes EGP 260,000 a year.

**Maximo.** Labour transactions give in-house hours × craft rate per job. 180 jobs × 2.4 h average
× EGP 300/h = **EGP 129,600** of directly-attributable labour, against EGP 260,000 quoted — and the
comparison is like-for-like because contractor hours flow through the same transaction.

**Atriom today.** In-house work is `execution_type = 'internal'` with an
`assigned_to_user_id` and no hours ([`FacilityWorkOrder`](../../../app/Models/FacilityWorkOrder.php)).
Electricians' salaries are in payroll, posted to `salaries_expense`, unallocated to any job.

**What breaks.** In-house work costs **zero** on every report, so outsourcing always looks
expensive and insourcing always looks free. This is the single most consequential distortion in the
module, and it is the gap analysis's O6.

> **CLOSED 2026-08-20.** `facility_work_order_labour` records hours against a job, priced at the
> **trade's** `standard_hourly_rate` and **frozen at entry** — so re-rating a craft next year cannot
> silently restate last year's jobs. In-house and contractor work are now comparable because both
> land in `recomputeCosts()`, which is the whole point of the scenario. (The row was O6 in a ranked
> list that has since been renumbered; the evidence lives in
> [gap §4](../../gap-analysis/README.md#4-facility-vendors-assets--vs-the-fm-standard).)

---

## S3 — The HVAC fault dispatched to the stationery supplier

**Situation.** A tenant reports no cooling. The coordinator raises a corrective work order,
category HVAC, and opens the vendor picker.

**ServiceChannel.** Trade `HVAC` at this location has two eligible providers, both compliant, with
contracted rates and an SLA. The picker offers those two.

**Atriom today.** The picker offers **every vendor**. `vendors` has `type`
(`contractor` · `supplier` · `service_provider` · `consultant` · `other`) and **no trade at all** —
verified against the live schema. The work order's own `category` is a Select populated from
`__('admin.facility.categories')`, a **translation array**, not master data, and it is **not in
`ValueSets`** — so the column is unenforced and the list cannot be extended without a deploy in
both languages.

**What breaks.** Three things, none of them visible as an error:
1. Dispatch eligibility cannot be expressed, so the guard is the coordinator's memory.
2. "Spend by trade" — the first question any owner asks about maintenance — has no dimension to
   group by.
3. `VendorScorecardService` compares a cleaning contractor with an HVAC contractor, because there
   is no trade to compare within.

> **CLOSED 2026-08-20.** `App\Models\Trade` is a row, and it is the spine of the facility model: it
> classifies work orders, service plans **and** equipment, `vendor->trades()` says who may be
> dispatched to it, and its `standard_hourly_rate` is the craft rate S2's labour records read. It
> replaced `category` — the translation array named above — and **`category` must not come back**:
> two columns answering *"what kind of work is this"* are two truths about one question.
>
> **Eligibility is a suggestion; compliance is the gate.** `Vendor::assignableOptions()` *groups* the
> picker (*"Does this trade"* / *"Other vendors"*) and never filters, because Filament validates a
> Select with `Rule::in` and refusing an unusual-but-legitimate pick is the worse failure;
> `Vendor::isDispatchable()` — the COI/document check — stays the only hard block. That is a stated
> deviation from ServiceChannel, which filters.
>
> A tenant still picks a **problem**, not a trade: `RaiseCorrectiveWorkOrderService::tradeForRequest()`
> resolves the trade from `tenant_request_subcategories.trade_id` (a foreign key since EG-14, not a
> name match) and yields **null** for a noise complaint, which is not maintenance at all.

---

## S4 — The EGP 4,000 job that became EGP 46,000

**Situation.** A leak is reported. The contractor attends, decides the riser must be replaced, does
it, and invoices EGP 46,000. The operator expected a EGP 4,000 repair.

**ServiceChannel.** The work order carried an **NTE of EGP 5,000**. The moment the contractor saw
the job exceed it, work stopped and a **proposal** was submitted: labour, materials, subcontract,
tax. The operator approved EGP 38,000 or refused it — *before* the riser came out. An invoice above
an approved proposal is held, not paid.

**Atriom today.** There is no NTE anywhere in the codebase, and no proposal object. The control
that exists is [`PurchaseRequest::billingVariance()`](../../../app/Models/PurchaseRequest.php) —
three-way match — which is real and correct and fires **after** the invoice arrives, on a
purchase-request path a reactive leak never travelled.

**What breaks.** The negotiation happens after the work, which is not a negotiation. Note the
asymmetry worth naming: Atriom's *after* control is arguably stronger than ServiceChannel's, and its
*before* control does not exist.

> **CLOSED 2026-08-20, and the contractor submits their own quote since 2026-08-28.** The *before*
> control is `Concerns\FacilityWorkOrder\ControlsSpendAgainstNte` plus `WorkOrderProposal` — labour,
> materials, subcontract and tax, approved or refused before the riser comes out. The contractor
> files it themselves from the vendor portal, and the proposal records **which** of the two happened:
> `submitted_by_user_id` means our staff keyed it, `submitted_by_vendor_contact_id` means they sent
> it — two questions, not two types for one, so deliberately not a morph.
>
> **A bill over an approved proposal is SHOWN, never blocked** — a stated deviation from
> ServiceChannel, which holds the invoice. Holding a supplier's invoice inside a system the supplier
> cannot see turns a commercial conversation into a payment failure; the variance is surfaced where
> the operator decides.

---

## S5 — The fire-extinguisher round

**Situation.** 42 extinguishers across level 2, inspected quarterly. Three fail.

**Maximo.** A **route** lists the 42 stops. One work order is generated with 42 task rows, or 42
child work orders — the choice is per route. The three failures are recorded against the three
assets, and the other 39 are recorded as inspected.

**Atriom today.** A `ServicePlan` targets **one** `equipment_id`, `unit_id` or `area_id`
(verified against `service_plans`), with a `checklist` JSON. So the operator either creates 42
plans, or one plan whose checklist has 42 lines and whose failures cannot be attributed to
individual extinguishers — `facility_work_order_items` carries a `label` and a `result`, not an
asset.

**What breaks.** Compliance evidence for a fire authority is per device. A checklist line reading
"Extinguisher 2-17 — fail" is a string, so no report can say which devices are overdue, and the
asset history of extinguisher 2-17 is empty.

> **CLOSED 2026-08-20.** `ServicePlanStop` makes a plan a **route**: 42 stops, one work order,
> and `facility_work_order_items.equipment_id` attributes each result to the device it belongs to —
> so "Extinguisher 2-17 — fail" is a row against that asset, not a string in a checklist, and the
> other 39 are recorded as inspected. Planned cost travels with the route
> (`2026_08_20_600000_routes_and_planned_cost`).

---

## S6 — The fault that was never fixed

**Situation.** The same escalator handrail is reported four times in five weeks. Each time a
contractor attends, closes the job, and bills EGP 2,200.

**ServiceChannel.** The second work order at the same location, same trade, same problem inside the
window is flagged as a **repeat visit**. Four visits is a pattern on the provider scorecard and a
conversation about whether the fix was real.

**Maximo.** Problem → cause → remedy on each job. Four identical problem codes with four different
remedies says nobody has found the cause; MTBF on that asset collapses.

**Atriom today.** Neither. There is no repeat detection (verified — no reference in
`FacilityWorkOrderService` or `FacilityWorkOrder`), and no failure vocabulary: the only structured
outcome is the checklist `result`. Four jobs, four invoices, EGP 8,800, and the register shows four
unrelated successes.

> **CLOSED 2026-08-20.** Both halves: `Concerns\FacilityWorkOrder\RecordsFailuresAndRepeats` flags
> the second job at the same location, same trade, inside the window as a repeat, and
> `App\Models\FailureCode` is the problem → cause → remedy vocabulary — an operator-editable
> catalogue, so a failure mode this mall actually sees can be added without a deploy. Four identical
> problem codes with four different remedies now reads as what it is.

---

## S7 — The tenant who was told it was done

**Situation.** A shop reports a blocked drain. The contractor attends, partially clears it, and
marks the job done. The shop floods two days later during trading hours.

**ServiceChannel.** Check-out requires a resolution and, where configured, the **tenant's
confirmation**. The tenant rejects and the job reopens against the same provider, on the same SLA
clock.

**Atriom today.** [`FacilityWorkOrderService::transition()`](../../../app/Services/FacilityWorkOrderService.php)
moves the job to `done` with a checklist gate and (optionally) evidence. The tenant request that
raised it is linked by `tenant_request_id`. **The tenant is not asked to confirm anything** —
verified: there is no confirmation or reopen path on `TenantRequest`.

**What breaks.** The job is closed by the party who is paid for closing it. Every FM platform in
this benchmark treats that as a control failure, and it is a cheap one to fix because the portal
and the linkage already exist.

> **CLOSED 2026-08-20.** `tenant_requests.confirmed_at` — the tenant confirms, or rejects and the
> job reopens on the same clock. It is a **control, not a courtesy**, and the same reasoning later
> settled the vendor portal's scope: a contractor saying "finished" is a *claim*, the operator's
> completion is a *decision*, so **marking a job done is deliberately not one of the contractor's
> four verbs** (accept · update · evidence · quote). `facility.complete` still runs the checklist
> gate, the evidence gate and the cost object.

---

## S8 — The PM that was never done, and nobody could tell

**Situation.** The quarterly generator load-bank test was due in March. It generated, sat open, and
was closed in July with a tick.

**Maximo.** **PM compliance** is a first-class measure: was the work order completed inside the
window the PM defined? A test done four months late is non-compliant even though it is complete.

**Atriom today.** `ServicePlan` carries `next_due_date` and `last_generated_at`, and the scan
generates ([`GeneratePreventiveWorkOrdersService`](../../../app/Services/GeneratePreventiveWorkOrdersService.php)).
The generated work order has `scheduled_for` and `completed_at` — **so the raw material for
compliance is present** and nothing computes or reports it.

**What breaks.** The least expensive gap here: the data exists. Until it is measured, a preventive
programme is a list of intentions.

> **CLOSED 2026-08-20.** `Concerns\FacilityWorkOrder\TracksPmCompliance` answers `on_time` · `late`
> · `overdue` · `due` from the two columns that were already there — the generator copies
> `next_due_date` onto the order, so `scheduled_for` **is** the plan's due date. It is **derived,
> never stored**: `overdue` is a function of today, so a column would need its own sweep and would go
> wrong on a day when nothing happened.
>
> Three judgements worth keeping: completing at 16:00 on the due day is **on time** (compare whole
> days, or every afternoon completion reads late); a cycle still inside its window is **excluded**
> from the rate rather than counted against it; and a plan with nothing settled has **no** rate — 0%
> and 100% are both inventions. Strict, with no tolerance window — a stated deviation from Maximo,
> because one global tolerance is wrong for both a weekly round and an annual overhaul, and strict
> never overstates.

---

## What these eight have in common

| Scenario | Root cause | Standard | Closed by (2026-08-20 unless noted) |
|---|---|---|---|
| S1, S2 | The work order is not a cost object | Maximo §4 | `recomputeCosts()` + `facility_work_order_labour`; cost object, **never** a GL source |
| S3 | Trade is not master data | ServiceChannel §2 | `Trade` — classifies work, plans and equipment; carries the craft rate; `category` retired |
| S4 | No spend control before the work | ServiceChannel §3 | `ControlsSpendAgainstNte` + `WorkOrderProposal`; contractor-submitted since 2026-08-28 |
| S5 | A plan targets one asset, not a route | Maximo §6 | `ServicePlanStop` + `facility_work_order_items.equipment_id` |
| S6 | No failure vocabulary, no repeat detection | Maximo §7 · ServiceChannel §4 | `RecordsFailuresAndRepeats` + `FailureCode` |
| S7 | The doer closes the job | ServiceChannel §4/§6 | `tenant_requests.confirmed_at`; the contractor cannot mark a job done |
| S8 | PM compliance is unmeasured | Maximo §6 | `TracksPmCompliance` — derived, strict, no tolerance window |

**Six of the eight were one of two ideas** — the cost object, and the trade — which is what made this
a close-out with a shape rather than a list of features, and why all eight closed in one pass on
2026-08-20.

**Two deliberate deviations from the benchmark survive, and both are stated rather than implied:** a
bill over an approved proposal is **shown, never blocked** (S4), and the vendor picker **groups
rather than filters** by trade, with COI compliance as the only hard gate (S3). Where the standard
and this system disagree, the disagreement is named.
