# Work-order management (PPM + CM) & SLA / penalties — Atriom vs the FM specialists

> Benchmarks the mall/retail-specialist FM tools: **Facilio** (purpose-built mall/REIT FM — the closest
> analog), **ServiceChannel** (retail multi-site vendor dispatch + SLA compliance), **IBM Maximo**
> (enterprise PPM/EAM), and **MaintainX / Limble** (modern mobile CMMS). Atriom side grounded in
> [`docs/modules/26`](../../modules/26-facility.md) (`FacilityWorkOrderService`,
> `ServicePlan`/`FacilityWorkOrder`, `Equipment`, `SlaResolver`, `sla_policies`,
> `SlaPenalty`, `WorkOrderPartService`) and [`docs/modules/11`](../../modules/11-tenant-requests.md)
> (`TenantRequest`, area→supervisor routing). Competitor cells marked *(verify)* are version-/edition-sensitive.

Legend: ✅ full · 🟡 partial · ❌ absent · ⏭️ N/A or deferred

## 1. Capability matrix

| Capability | Atriom | Facilio | ServiceChannel | IBM Maximo | MaintainX / Limble | Gap note |
|---|---|---|---|---|---|---|
| PPM scheduling (calendar/frequency) | ✅ | ✅ | ✅ | ✅ | ✅ | `service_plans` frequency `days\|weeks\|months\|years × N`; daily `facility:generate-preventive` raises WOs + advances `next_due` (idempotent, lock-safe). Parity on the routine case. |
| Meter- / condition-based PM triggers | ❌ | ✅ *(verify — IoT/BMS-driven)* | 🟡 *(verify)* | ✅ | ✅ *(verify — meter-based PM)* | Plans are **calendar-only**. Meter readings exist (utilities module) but don't drive PM generation. No usage/condition trigger. |
| Checklist / job-plan template on a WO | 🟡 | ✅ | ✅ *(verify)* | ✅ (job plans + labor/tools) | ✅ (procedures) | `plan.checklist` (json) copies into `facility_work_order_items`. A pass/fail item list — **not** a job plan with labor estimates, crafts, tools, safety steps. |
| Pass/fail inspection + hard completion gate | ✅ | ✅ | ✅ *(verify)* | ✅ | ✅ | `result` enum (`pass\|fail\|pending`); **WO cannot close while any item is `pending`** (FR-PPM-07), enforced in the service under a parent-row lock. A `fail` closes the visit (→ becomes CM). Genuinely strong. |
| Corrective maintenance, internal vs external | ✅ | ✅ | ✅ | ✅ | ✅ | `work_order_type=cm`, `execution_type` internal/external as a real XOR (in-house tech **xor** vendor). `CM-` refs, required `description`. |
| WO lifecycle / enforced state machine | ✅ | ✅ | ✅ | ✅ (deep) | 🟡 *(verify — lighter statuses)* | `FacilityWorkOrderService::TRANSITIONS` (`open→in_progress→done\|cancelled`); illegal hops throw, terminal orders frozen (checklist + edit). |
| Equipment register + sub-code hierarchy | ✅ | ✅ | ✅ *(verify)* | ✅ (deep asset/location tree) | ✅ (asset + sub-assets) | `equipment` per-property-unique `code`, `parent_id` sub-code tree (acyclic + same-property guards), optional `fixed_asset_id` twin. No location/system hierarchy beyond area/unit. |
| Per-priority SLA targets | 🟡 | ✅ | ✅ | ✅ (response **and** resolution) | 🟡 *(verify)* | 4 tiers, but **resolution-only** — no separate *response*-time SLA. Clock starts on **acceptance** (FR-CM-07), not creation, so an un-accepted CM never breaches. |
| Per-property SLA overrides | ✅ | ✅ *(verify)* | ✅ *(verify — per site/trade)* | ✅ *(verify)* | 🟡 *(verify)* | `sla_policies` (asset × priority), fallback chain **property → settings → config** via `SlaResolver`; a row is an override, deletion returns to default. A real differentiator vs most CMMS. |
| SLA breach detection + alert | ✅ | ✅ | ✅ (compliance tracking) | ✅ (escalations) | 🟡 (overdue alerts) | Hourly `facility:scan-sla-breaches`, idempotent (`sla_breach_notified_at`), row-locked, bell alert. Stamps even when nobody's assigned. |
| **Vendor SLA penalty auto-computed + charged to AP/bill** | ✅ | 🟡 *(verify)* | 🟡 *(verify — invoice deductions)* | 🟡 *(verify — contract terms)* | ❌ | **Atriom's standout.** `SlaPenalty`: 3 bases (flat / per-day accrual / %-of-job-value), one re-assessed row per WO, frozen on closure, waivable; **charged onto the vendor bill as an AP offset** (`penalty_applied_amount` in `VendorBill::recompute()`) and GL-posted Dr AP / Cr expense. External jobs only. |
| Vendor performance scorecard / compliance analytics | ❌ | ✅ *(verify)* | ✅ (Provider Performance — flagship) | 🟡 *(verify — KPIs)* | 🟡 *(verify)* | No provider scorecard, rating trend, or SLA-compliance-% dashboard. Atriom has the penalty + breach flag + activity log, but no aggregated vendor analytics. |
| Follow-up chains (linked, no-reopen) | ✅ | 🟡 *(verify)* | 🟡 *(verify)* | ✅ (native follow-up WOs) | 🟡 *(verify)* | `parent_work_order_id`; `asFollowUp()` is **deliberately allowed on a terminal order** so the original's SLA/closure record survives. Chains to any depth. A well-modelled strength. |
| Evidence-before-completion (photo gate) | 🟡 | ✅ | ✅ (check-in + photos) | ✅ *(verify)* | ✅ (photo-required configurable) | On the **tenant request** side, resolving needs a photo **or** linked WO (FR-USR-06). On the **WO** side the photo gate is **deferred** (open question E.4) — a WO gates on its checklist, not evidence. |
| Auto-assignment / area→supervisor routing | 🟡 | ✅ *(verify)* | ✅ (trade/location dispatch rules) | ✅ *(verify — assignment mgr)* | 🟡 *(verify)* | Exists for **tenant requests** (module 11): `area_id` inherited from unit; single-supervisor zone auto-assigns, multi-supervisor notifies. Internal **WOs** are assigned manually. |
| Field-technician mobile app (offline, checklist, photo) | ❌ | ✅ | ✅ (provider app, GPS check-in) | ✅ (Maximo Mobile) | ✅ (mobile-first — flagship) | The `/api/v1` mobile API is **tenant-request intake**, not a technician app. Push to supervisors is declared but a no-op (no device tokens). No field app to run checklists / attach photos / work offline. |
| Parts/inventory draw on WO + approval | ✅ | ✅ | 🟡 *(verify)* | ✅ (storeroom/reservations) | ✅ | `facility_work_order_parts`: internal draw **requested→approved** (approver by value via `ApprovalPolicy`, frozen on the row, self-approval refused), external recorded; stock movement + property-dimensioned GL. |
| Vendor dispatch / provider marketplace | ❌ | ✅ (vendor portal) | ✅ (Fixxbook network — flagship) | 🟡 *(verify)* | ❌ | Atriom assigns a **known** `vendor_id`; no RFP-to-vendor, no vendor self-service portal to accept/quote/update a WO. ServiceChannel's core. |
| Job cost: labor + parts | 🟡 | ✅ | ✅ (proposals/invoicing) | ✅ (crafts/timesheets/costing) | ✅ (time + parts cost) | Parts cost (`partsCost()`) + external `job_value` (vendor quote) are tracked and GL-posted; **no internal labor hours / craft / timesheet** on a WO. |
| IoT / BMS condition monitoring | ❌ | ✅ (its core pitch) | 🟡 *(verify — refrigerant/energy)* | ✅ (Monitor/Predict) | 🟡 *(verify)* | No sensor/BMS ingestion, no condition-based or predictive maintenance. ⏭️ Deliberately out of scope for now. |

## 2. Architecture read

**For a mall operator, the corrective-maintenance spine is soundly designed and, on the money side, ahead
of the specialists.** The decision to put CM in the *work-order* module (26) rather than the tenant-request
module (11) is correct: a fault on a common-area chiller has no tenant and no unit, and module 11's
`tenant_id`/`unit_id` are NOT NULL. CM inherits the state machine, the pass/fail checklist, the FR-PPM-07
completion gate, and the equipment link already built for PPM — one discriminator (`work_order_type`), not a
second engine. The state machine is enforced in the service (illegal hops throw, terminal orders freeze their
checklist under a row lock), which is the same discipline Maximo applies and stricter than MaintainX/Limble's
looser status handling. The **completion gate** — a WO cannot close while any item is `pending`, a `fail`
becomes corrective work rather than blocking — is a genuinely good encoding of "an inspection's job is to
find faults," and matches what a serious CMMS does.

**Two things Atriom does that the FM specialists mostly don't.** First, **per-property SLA overrides** with a
clean `property → settings → config` fallback chain: an operator records only the malls that genuinely differ
instead of restating four numbers per property, and deletion returns a property to the default. Second — and
this is the real moat — the **vendor SLA penalty charged straight onto the AP bill**. Facilio, ServiceChannel
and Maximo all *track* SLA compliance and surface it in scorecards, but auto-computing a liquidated-damages
figure on three configurable bases, re-assessing it per scan, freezing it on closure, and **posting it Dr
Accounts Payable / Cr the expense the bill charged** (with the AP tie-out proven balanced) is accounting most
FM tools push back to a separate finance system. Because Atriom carries its own double-entry GL, the penalty
is a first-class money event, not a report line. Combined with the property-scoping, the عهدة/custody
workflow, and ETA e-invoicing elsewhere in the system, this is the Egyptian-mall fit that a generic import of
Facilio or ServiceChannel would not give.

**Where it is genuinely thin is the field and the vendor-facing edge.** There is **no technician mobile app** —
the `/api/v1` surface is tenant-request intake, and push to supervisors is declared but inert (no device
tokens). An engineer cannot run a checklist, attach a photo, or work offline on a phone, which is table stakes
for MaintainX/Limble and Facilio and the single biggest day-to-day usability gap. There is **no vendor
dispatch/portal**: Atriom assigns a *known* vendor row, whereas ServiceChannel's entire value is sourcing,
dispatching, and letting the provider accept/quote/update the job. And there is **no vendor performance
scorecard** — Atriom has the penalty and the breach flag but no compliance-% trend, so an operator can punish a
late vendor but can't easily *rank* vendors over time.

**The PPM side is competent but calendar-bound.** Frequency scheduling, the equipment sub-code tree, and the
parts-draw-with-approval ladder are all solid and GL-integrated. But PM is **calendar-only** — no meter- or
condition-based triggers, no IoT/BMS ingestion, no predictive maintenance — and a "job plan" here is a pass/fail
checklist, not a labor/tools/crafts plan. For a mall running escalators, chillers and generators, meter- and
runtime-based PPM (Maximo, Facilio, and even MaintainX) is a real and reasonable future ask, not a nicety.

## 3. Top 5 gaps (ranked for a mall operator)

1. **No field-technician mobile app (offline checklists + photos)** — *why it matters:* engineers close WOs and
   mark pass/fail from the field; today that's a desktop Filament screen, and the mobile API is tenant-only.
   Every specialist here is mobile-first for techs. This is the gap a Facilio/MaintainX customer feels on day
   one. *Effort: L.*
2. **No vendor performance scorecard / SLA-compliance analytics** — *why:* a mall operator manages a bench of
   contractors and needs "who's chronically late, whom do we renew." Atriom can charge one late job but can't
   rank vendors. ServiceChannel and Facilio lead here. *Effort: M* (the penalty + breach + activity data already
   exist; this is aggregation + a dashboard).
3. **No vendor dispatch / provider portal** — *why:* external CM is assigned to a *known* vendor with no way for
   that vendor to accept, quote, update status, or upload completion evidence themselves. Malls with outsourced
   HVAC/cleaning/lifts live in this loop; ServiceChannel is built on it. *Effort: L.*
4. **PPM is calendar-only — no meter/condition/runtime triggers** — *why:* escalators and chillers should be
   serviced on running hours or condition, not just a date. No sensor/BMS ingestion means no predictive or
   usage-based maintenance. *Effort: M–L* (meter-based PM off existing meter readings is M; true IoT/BMS is L).
5. **No internal labor / job-cost on a WO, and no photo-evidence gate on WO completion** — *why:* parts and
   external quotes are costed and posted, but in-house labor hours aren't captured, so internal maintenance cost
   is understated; and a WO closes on its checklist without requiring completion evidence (deferred as open
   question E.4). Both are things an FM manager expects to see. *Effort: M.*

## 4. Net verdict

**At-par-to-ahead on the corrective-maintenance and SLA/penalty core (per-property SLA + vendor-penalty-to-AP
genuinely exceed the specialists), behind on the field/vendor edge — no technician mobile app, no vendor
dispatch/scorecard, and calendar-only PPM.**

*Uncertainty flags: whether Facilio/ServiceChannel/Maximo auto-post a liquidated-damages penalty to AP vs merely
track SLA compliance (verify), the exact per-site SLA and meter-based-PM boundaries in ServiceChannel and
MaintainX/Limble (verify), and Maximo's follow-up-WO and assignment-manager specifics by version (verify).
Atriom side verified against modules 11 & 26 and the cited services.*
