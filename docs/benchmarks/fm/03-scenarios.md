# Facility scenarios — the standard vs Atriom today

> Same method as [`yardi/04-scenarios.md`](../yardi/04-scenarios.md): a real operational situation
> with real numbers, what the benchmark does, what Atriom does **today** (re-derived from code on
> 2026-08-19, with file references), and what breaks. The verdict and the ranked build order live in
> [the gap analysis](../../gap-analysis/README.md); this file is the evidence.

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

---

## What these eight have in common

| Scenario | Root cause | Standard |
|---|---|---|
| S1, S2 | The work order is not a cost object | Maximo §4 |
| S3 | Trade is not master data | ServiceChannel §2 |
| S4 | No spend control before the work | ServiceChannel §3 |
| S5 | A plan targets one asset, not a route | Maximo §6 |
| S6 | No failure vocabulary, no repeat detection | Maximo §7 · ServiceChannel §4 |
| S7 | The doer closes the job | ServiceChannel §4/§6 |
| S8 | PM compliance is unmeasured | Maximo §6 |

**Six of the eight are one of two ideas** — the cost object, and the trade. That is what makes this
a close-out with a shape rather than a list of features.
