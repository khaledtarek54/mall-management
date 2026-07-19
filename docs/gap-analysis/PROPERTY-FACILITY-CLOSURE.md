# Property + Facility — module close-out ledger

> **What this is.** The living record of the module-by-module close-out sweep of Atriom's
> **property + facility** modules — the moat. Each module is taken to a documented **CLOSED**
> state and **never reopened**. Started 2026-07-19.
>
> Companion reads: [competitors/README.md](competitors/README.md) (the competitive benchmark this
> sweep closes against), [000-progress.md](000-progress.md) (Round 1/2 internal audit),
> [GENERIC-MODULES-CLOSED.md](GENERIC-MODULES-CLOSED.md) (the generic-ERP layer, already closed —
> out of scope here), [998-deferred-backlog.md](998-deferred-backlog.md) (parked items).

## The loop (per module)

1. **Baseline** — module doc + its `competitors/` deep-dive + Round-1/2 findings + code/tests.
2. **Gap sweep** — parallel lenses: **business-logic / domain-rules** (the deepest — are the encoded rules *correct* for an Egyptian mall, not just bug-free) · **operator + tenant UX / workflow** (configure-once-vs-choose-every-time, footguns, missing columns/filters, generated docs that need manual fixup — added at the owner's request 2026-07-19) · competitor parity · correctness/bugs · tie-out & property-isolation · coverage/conformance · FRD conformance. Every finding **adversarially verified**.
3. **Triage** — CLOSE-NOW / DEFER (with trigger) / OUT-OF-SCOPE (with reason).
4. **Close** — build the CLOSE-NOW items; `pest --parallel` green; conformance green; docs updated; committed.
5. **Sign-off** — mark CLOSED below with date + decisions. Never reopen.

## What "CLOSED" means

Not "feature-complete like Yardi." A defensible line, same as the generic-modules bar:

- **No verified open correctness gap** — nothing on the books is wrong, no money document lacks a
  correction path, no operator input can silently diverge business state from the GL, no
  property-isolation leak.
- **No open competitive-parity gap that is in-scope + worth building** — every gap vs the
  specialists is either closed, or **explicitly parked with a trigger**, or ruled out-of-scope with
  a reason. A deferred item is a decision on record, not debt a later audit rediscovers.
- **Coverage** — the module's rules are exercised by tests + guarded by a conformance gate where one
  is warranted.

## Verification rules (inherited from Round 2)

- An absence claim is a **hypothesis**, never a finding — search the whole repo for the *capability*,
  not the spelling; say where you looked.
- A finding needs a **failure scenario**: concrete inputs/state → wrong output. Prove by exploiting;
  prove the fix by reverting and watching the test fail.
- Money paths are driven through the **real service + `accounting:sync-ledger`**, with **reachable
  inputs** (a fixture that sets a column no form/service writes proves nothing).

## Scope + status

Ordered dependency-first. Generic-ERP modules (21–25, 28) are out of scope (already CLOSED).

| Order | # | Module | Status | Closed | Notes |
|---|---|---|---|---|---|
| 1 | 01 | Properties & Units | ✅ CLOSED | 2026-07-19 | 6 CLOSE_NOW fixed (2 HIGH), 1 parity DEFER; see closure record |
| 2 | 02 | Tenants | ✅ CLOSED | 2026-07-19 | 7 CLOSE_NOW fixed (3 HIGH: portal+API blacklist bypass, statement AR leak), 3 parity DEFER; see closure record |
| 3 | 04 | Leases | ✅ CLOSED | 2026-07-19 | 2 CLOSE_NOW fixed (dead rent-escalation — critical business-logic — + terminal-lease immutability); 8 DEFER incl. 4 domain-decision calls for the operator; see closure record |
| 4 | 05 | Billing & Invoices | ✅ CLOSED | 2026-07-19 | 5 CLOSE_NOW fixed (marketing-levy doc/behaviour reconciled by operator decision, parking GL account, legacy AR trap removed, run-summary count, late-fee floor test); 8 DEFER; VAT-on-levy = accountant question |
| 5 | 06 | Payments | ⬜ Not started | — | |
| 6 | 07 | Credit Notes | ⬜ Not started | — | |
| 7 | 08 | CAM reconciliation | 🟡 Partial | — | slices 1–2 shipped + adversarially reviewed this session; slice 3 (basis/gross-up/exclusions) DEFERRED |
| 8 | 09 | Tenant Sales & % Rent | ⬜ Not started | — | |
| 9 | 10 | Utility Meters | ⬜ Not started | — | |
| 10 | 11/26 | Maintenance (CM + PPM) | ⬜ Not started | — | Round-2 fixed F-77/78/96 |
| 11 | 12 | Vendors & Contracts | 🟡 Partial | — | COI gate shipped this session; full sweep pending |
| 12 | 29 | Procurement | ⬜ Not started | — | Round-2 fixed F-100..105 |
| 13 | 30 | Areas | ⬜ Not started | — | register shipped; routing to follow |
| 14 | 31 | Violations | ⬜ Not started | — | fine recorded-not-billed |
| 15 | 03 | Tenant Portal | ⬜ Not started | — | |
| 16 | 15 | Owner Requests & model | ⬜ Not started | — | |
| 17 | 32 | Owner Statements | 🟡 Partial | — | shipped this session; mgmt fee + multi-owner DEFERRED |
| 18 | 33 | Post-dated Cheques | 🟡 Partial | — | register-only shipped this session; NR accrual deferred |
| 19 | 16 | ETA e-invoicing | ⬜ Not started | — | ⚠️ mock/disabled — go-live risk |
| 20 | 17 | Reports | ⬜ Not started | — | |
| — | 13 | Marketing | ⬜ folded in | — | cross-cutting |
| — | 18/19/20 | RBAC / Notifications / Mobile API | ⬜ folded in | — | cross-cutting |

## Deferred parity backlog (from close-out sweeps)

Real competitor-parity gaps, verified but **DEFERRED** — each formally re-triaged (build vs.
park-with-trigger) when its owning module's close-out comes up.

| Gap | Kind | Owning module | Trigger to build |
|---|---|---|---|
| Field-technician mobile app (offline checklists + photos); `/api/v1` is tenant-only | parity/high | 20/26 | operator commits to in-house field crews on the app |
| PPM is calendar-only — no meter/runtime/condition-based triggers | parity/med | 26 | a mall with metered plant (chillers/generators) onboards |
| Vendor performance scorecard / SLA-compliance analytics | parity/med | 12 | operator wants vendor-renewal decisions data-driven |
| 3-way-match tolerance / variance hold (overbill above receipt clears silently) | parity/med | 29 | procurement volume makes silent overbilling a real loss |
| Asset criticality ranking (drives PM priority / SLA tier / spare stocking) | parity/med | 26 | PM/spares backlog needs prioritisation |
| Reorder-driven auto-purchase (low-stock alert never drafts a PR) | parity/med | 22/29 | store-keeping becomes a bottleneck |
| Fit-out permit grant/reject/conditions workflow (capture-only today) | parity/med | 15 | operator formalises tenant fit-out approvals |
| Internal labor / job-cost + photo-evidence completion gate on work orders | parity/med | 26 | operator wants true maintenance cost per asset |
| Multi-quote / RFQ bid comparison for capex procurement | parity/low | 29 | capex procurement volume warrants it |
| Vendor dispatch / provider portal (accept/quote/evidence loop) | parity/low | 12 | external-vendor dispatch becomes core |
| Penalty Filament actions (charge/waive) lack a dispatch/dual-gate test | coverage/med | 26 | fixed when module 26 closes |
| No conformance gate for `ApprovalPolicy` single-interpretation invariant | coverage/low | 28 | generic-ERP already closed; revisit only if approvals grow |
| Occupancy measured by unit count, not leasable area (no GLA / physical-vs-economic occupancy) | parity/high | 01 | a mall with a large vacant anchor + many small kiosks (where unit-count occupancy misleads), or the operator/owner asks for economic occupancy. `area_sqm` data already exists |
| No tenant-level merchandising / trade classification — tenant-mix keyed on the coarse unit space-category, not the tenant's retail category | parity/med | 02 | operator wants tenant-mix / category-exclusivity analysis (a leasing-strategy feature) |
| `tax_id` indexed but not unique + no tenant merge/dedupe — duplicates split AR and give one legal entity two ETA identities | parity/med | 02 | duplicate tenant records observed in practice; needs a merge tool (re-point leases/invoices/payments) before a uniqueness constraint |
| **Holdover** — a past-`expiry_date` lease stays `active`, keeps the unit occupied, but billing silently STOPS → rent-free holdover. **ALERT shipped (MVP):** ActionRequired dashboard card + Leases "Holdover" filter, so it's never silent. | **business_logic · billing DEFERRED** | 04 | **operator rule still needed for auto-billing:** auto-expire? month-to-month at a premium? — decide once frequency is known. The silent-leak risk is closed. |
| **Move-out proration** — mid-month termination bills the full final month (no refund) or drops it entirely; only the FIRST month prorates, and only via the single-lease action | **business_logic/high · DOMAIN CALL** | 04 | **operator rule needed:** prorate the final month + auto-credit the overpaid days? |
| **Stepped-rent schedule** — anchor deals need non-uniform per-period step amounts; today faked as manual charges (flat escalation % can't express steps) | **business_logic/high · DOMAIN CALL** | 04 | **operator rule needed:** model a first-class lease-level step schedule? (`LeaseRentChangeService`/`RentEscalationService` mutate only the latest base-rent charge — needs the effective-dated `LeaseCamTerm` pattern). *(Rent-free / fit-out grace itself: **BUILT** 2026-07-19 — `fit_out_months`.)* |
| **Per-unit rent on a multi-unit lease** — one `base_rent` spans all units | **business_logic/med · DOMAIN CALL** | 04 | **operator rule needed:** do multi-unit leases price per-unit, or is a single blended rent correct? |
| **Lease-level billing frequency** (quarterly / semi-annual / annual in advance) with a truthful multi-month invoice period — today `seedStandardCharges` hardcodes monthly; the gap is unreachable (no charge-frequency UI) | **business_logic/med · DOMAIN CALL** | 04/05 | a real anchor/kiosk lease contracted quarterly/annual in advance. Must **subsume** `Charge.frequency` + re-verify CAM annual-recovery + %-overage probes against multi-month windows. |
| **`leases.billing_day` is dead** — fillable, date-mistyped, copied on renewal, read by nowhere; global `monthly_billing_day` drives cadence. Not a bug (no writer) | **correctness/low · wire-or-drop** | 04/05 | a real per-lease billing-day requirement (→ implement + retype as day-of-month), OR any new write path starts populating it (→ becomes a live mis-billing risk; drop it). |
| **Tenant PO / customer reference stamped on every invoice** — genuinely absent; notes workaround exists | **ux/parity/med** | 04/05 | onboard a corporate/government/anchor tenant whose AP requires a PO. Build as lease-persisted PO → snapshot onto invoice → PDF line, with an "ActionRequired: PO missing" surface (not a hard block). |
| **Auto-prorate the first partial month in the *scheduled* run** — batch never prorates (`prorate` defaults false) while the manual toggle defaults true; common flow (manual first invoice → batch skips) is already correct | **business_logic/low · policy** | 05 | mid-month commencements common enough that hands-off billing must prorate, or multi-mall rollout where per-lease manual first invoices don't scale. Needs its own regression tests + doc update. |

## Closure records

_One section per module as it closes, most recent first._

### Lease → Invoice UX sweep (follow-on) — 2026-07-19

First run of the new **UX / workflow lens** (added at the owner's request), targeted at the lease→invoice seam: *"what should be configurable on a lease so invoices generate right without manual fixups?"* 3 factual readers → 3 lens-proposers (ux / business-model / bug) → 19 candidates → adversarial verify + triage. **Outcome: the configurability candidates all DEFERRED** (each gated on an open operator/business decision, not an engineering choice); the two build-now items were both **confirmed money-path bugs** — fixed here.

- **[HIGH · bug] Invoices were born overdue on any off-the-1st run.** `generateInvoiceForLease` set `due_date = period_start + terms`, ignoring the real run date. A mid-month "Generate Invoice", a `monthly_billing_day > 1` scheduled run, or a back-fill produced a bill already past due → that night's `ScanOverdueInvoices` dunned the owner and `LateFeeService` added a 2% (min EGP 50) fee to a **same-day** bill. Fixed: `due_date = max(issue_date, today) + terms`. `issue_date` deliberately unchanged (it is the GL `entry_date` + the invoice-number `YYYYMM` — moving it is a separate accrual/numbering decision). Test: `InvoiceDueDateNotBornOverdueTest`.
- **[HIGH · bug] Single-lease "Generate Invoice" took no lock → double-bill race.** The bulk run holds `Cache::lock('billing:run:Y-m')`; the per-lease action didn't, and idempotency is a check-then-create with no DB unique key — so a double-click / second admin / manual-vs-scheduled race could each pass the probe and mint a duplicate. Fixed: `generateForLease` now contends on the same period lock (returns `skipped/run_in_progress` under contention). The DB dedup index is the harder belt-and-suspenders (MySQL has no filtered unique index; a plain `unique(lease_id, period_start)` would break the legitimate %-overage + annual-CAM-recovery coexistence) → deferred. Test: `SingleLeaseBillingLockTest`.

**BUILT since (from the config candidates):** ✅ **Fit-out / rent-free grace period** — `leases.fit_out_months` (operator chose full grace: rent + service + CAM + levy all suppressed for that many whole months from commencement; C1.5 resolved). Billing gate on the model (`periodInFitOut`/`firstBillableMonth`), shared with the "unbilled leases" card so a lease in grace is neither billed nor nagged; distinct `fit_out` skip reason for a clear UI message. Tests: `FitOutGracePeriodTest`.

**Still DEFER (lease-config, each gated on a business decision — see backlog):** lease-level billing frequency (quarterly/annual-in-advance) · stepped-rent schedule · auto-prorate the first partial month in the *scheduled* run · per-lease billing-day (`leases.billing_day` is dead — wire-or-drop) · tenant-PO-on-invoice · per-lease default invoice notes/legal text · localized invoice line-item descriptions (fix at render time). **Already handled (checked):** per-invoice notes + payment-instructions footer, "unbilled leases" ActionRequired card, security-deposit posting via `DepositTransaction`.

### Module 05 — Billing & Invoices — CLOSED 2026-07-19

Business-logic-led sweep of the money core. 5 CLOSE_NOW fixed; 8 DEFER; 4 refuted. Operator decisions taken: the 5% marketing levy **IS billed to the tenant** (fix the docs, not the code); VAT-on-levy flagged for the accountant.

- **[HIGH · business_logic] Marketing levy: code bills it, sign-off doc said it's internal/not billed.** The code (deliberate + tested) bills the 5% levy as a tenant line; `BUSINESS-RULES.md` (the accountant sign-off doc) + module-05 doc said the opposite — an accountant would have certified the reverse of what ships. **Operator confirmed billing is correct** → reconciled `BUSINESS-RULES.md` (rules + open-question 5) + module-05 doc §5/§9 to state the levy is billed + the budget accrues from the billed line. **VAT 0% vs 14% left as an explicit accountant question** at sign-off (no code change).
- **[MED · correctness] Parking revenue → misc income in the GL.** No `parking` role in the journalizer → parking lines lumped into misc_income. Added `parking_revenue` (41109001) + mapping; test drives real billing + sweep and asserts it lands in its own account, not misc.
- **[LOW · correctness] Legacy `Invoice::recalculateBalance()`** set balance directly, ignoring applied credits — a dead-but-endorsed trap on the AR single-source-of-truth. Removed (+ its 2 anti-pattern tests).
- **[LOW · correctness] Monthly-run summary counted a no-charge lease as "created"** (masking a silently-unbilled lease) → now counted "skipped", matching the single-lease path.
- **[LOW · coverage] Late-fee minimum-floor** (`max(50, balance×2%)`) had no test — added the small-balance case (floor is operative).

**DEFER:** move-out/trailing proration + auto-proration in the scheduled run (shared with mod-04), flexible billing cadence, escalating dunning, "voided invoice suppresses re-billing", + coverage gaps. **Accountant question:** marketing-levy VAT (0% vs 14%).

### Module 04 — Leases — CLOSED 2026-07-19

First sweep with the new **business-logic / domain-rules lens** (added at the user's request), which led the findings. 2 CLOSE_NOW fixed + regression-guarded (`Module04LeaseIntegrityTest`); 8 DEFER (4 of them domain-decision calls flagged for the operator); 0 refuted.

- **[CRITICAL · business_logic] Automated rent escalation was DEAD in production.** The daily sweep selects `WHERE next_escalation_date IS NOT NULL`, but no operator-reachable creation path ever seeded it (wizard omitted it, standard form has no field, renewal set it null) — so escalation never fired for a single real lease, and the tests only passed because fixtures hand-injected the column ("green over dead code"). Fixed with a single converged `Lease::creating` hook that arms `next_escalation_date = commencement + 1yr` whenever escalation is configured (`fixed_percent`/`cpi`, rate > 0); renewal now carries it forward. Tests rewritten to drive the REAL creation path. *This is exactly the class of bug the business-logic lens exists to catch — the feature "existed" but the rule never ran.*
- **[MED · business_logic] Terminal leases were editable** — a terminated/expired/cancelled/renewed lease could be re-opened + have its rent/status mutated via the standard Edit form (violates the "terminal records immutable" invariant). `Lease::updating` now blocks commercial/state changes on a terminal lease (notes/metadata + soft-delete still allowed); `EditLease` halts cleanly with a notice.

**DEFER — 4 domain-decision calls flagged for the operator** (business-logic gaps that need the operator's rule, not an engineering choice — see parity backlog): holdover / auto-expiry billing · mid-month move-out proration · rent-free / fit-out / stepped-rent schedules · per-unit rent on multi-unit leases. **DEFER — parity (4):** lease contract/document generation + e-signature · CPI/index-linked escalation · flexible billing cadence / anchor day · IFRS-16 straight-line recognition.

### Module 02 — Tenants — CLOSED 2026-07-19

Scoped sweep → 9 CLOSE_NOW (deduped to 7 distinct), 3 DEFER, 2 refuted. All CLOSE_NOW fixed + regression-guarded (`Module02TenantIntegrityTest`):

- **[HIGH · correctness] Blacklisted company kept PORTAL access** — the portal gate moved to `TenantUser` at the multi-user migration, but `TenantUser::canAccessPanel()` only checked the panel id, never the owning company's status (so `Tenant::canAccessPanel()`'s status check was dead). Now gates on `$this->tenant?->status === 'active'`.
- **[HIGH · correctness] Blacklisted company kept mobile-API access** — status was checked only at login; a mid-session blacklist left every token valid. New `EnsureTenantActive` middleware on the `auth:tenant-api` group re-checks per request + revokes the token.
- **[HIGH · isolation] Tenant Statement PDF + delinquency badge/filter + outstanding leaked cross-property AR** — a shared tenant's mall-B invoices/payments were shown to a mall-A-only operator. `TenantStatementPdfService::build()`, `Tenant::isDelinquent()/outstandingBalance()`, and the table column/filter now take an optional `visibleAssetIds()` scope (admin passes it; the tenant's own portal/API statement stays whole-company).
- **[MED · frd] Importer bypassed the tax_id format** the form enforces (the ETA go-live risk). Added the same regex to the importer.
- **[MED · coverage] tax_id un-normalised** — dashes reached ETA. `Tenant::setTaxIdAttribute` now stores bare digits; ETA builder tests updated to assert digits-only.
- **[LOW · correctness] Importer `type=foreign`** — unstorable enum value → silent row failure on MySQL. Restricted to `in:individual,company`.
- **[LOW · frd] FRD marked TEN-3 (tenant multi-user) deferred** though shipped. Corrected.

**DEFER (3):** tenant merchandising classification; tax_id uniqueness + merge/dedupe (×2) — see parity backlog. **Refuted (2).**

### Module 01 — Properties & Units — CLOSED 2026-07-19

Scoped sweep: 4 lenses → adversarial verify. 10 raw findings → 8 CLOSE_NOW (deduped to 6 distinct), 1 DEFER, 2 refuted. All CLOSE_NOW fixed + regression-guarded (`Module01OccupancyIntegrityTest`, 6 tests):

- **[HIGH · isolation] UnitImporter cross-property write** — resolved the target asset by CSV `asset_code` with `Asset::withoutGlobalScopes()` and no clamp, so a restricted user could create/overwrite another mall's units via import (a path that bypasses the form's `assertAssetInScope`). Fixed: `resolveVisibleAsset()` clamps to `visibleAssetIds()`; out-of-scope rows fail the `asset_code` rule; status column restricted to `vacant`/`maintenance`.
- **[HIGH · correctness] Unit double-booking** — the guard checked only `leases.unit_id` (master), so a unit held as an *additional* unit in a multi-unit lease could be re-leased. Fixed: `Unit::isActivelyLeased()` consults the `lease_unit` pivot; used in both the lease-form rule and `LeaseCreationService`.
- **[MED · correctness] Lease delete/restore strands occupancy** — `LeaseObserver` had only created/updated hooks, so soft-delete/restore/force-delete never re-projected unit status → phantom-occupied units, inflated owner occupancy. Fixed: `deleting` (captures unit ids into a **static** store — the observer instance isn't shared across events) + `deleted` + `restored` hooks recompute.
- **[LOW · correctness] Unit status self-heal** — a lease-less unit set to `occupied`/`reserved` via the form never reconciled. Fixed: Unit create/edit pages recompute on save (maintenance preserved).
- **[LOW · isolation] Units table property filter** — enumerated every mall's name + the ALL pseudo-asset. Fixed: `selectableAssetOptions()`.
- **[LOW · frd] FRD marked UNIT-1 (multi-unit lease) unbuilt** though shipped + heavily tested. Corrected in 3 places.

**DEFER:** occupancy-by-area (GLA) — see the parity backlog. **Refuted (2):** claims that grepped-missing but were implemented elsewhere.

### Sweep 0 — cross-module bugs caught while standing up the process (2026-07-19)

The first gap-sweep ran **unscoped** (a workflow arg bug), so it isn't a module-01 close-out — but
it surfaced three confirmed defects in this session's own work, all now fixed + regression-guarded:

- **[HIGH · mod 32] Owner-statement Revise → double payout** — revise rebuilt statements at
  version+1 with `paid_to_date = 0`, resetting the overpayment cap so the owner could be paid twice.
  Fix: `revise()` refuses while any non-cancelled disbursement exists (`OwnerStatementRun::hasActiveDisbursements()`, used in both the service guard and the action's `visible()`). Test: `OwnerStatementReviseDoublePayoutTest`.
- **[HIGH · mod 33] PDC invoice picker cross-property leak** — a cheque pinned to Mall A could link
  Mall B's invoice and settle it on clear. Fix: form picker scoped to the cheque's `asset_id` +
  `visibleAssetIds()`; model `saving` hook refuses a cross-property link (the real gate). Test: `PdcCrossPropertyInvoiceTest`.
- **[LOW · mod 08] CAM admin-fee doc formula** — said `pct × allocated`; engine uses `pct × capped_cost`. Doc corrected.
- **[HIGH · mod 33] PDC edit page 500** — the invoice picker plucked a non-existent `invoices.reference` column (invoices use `number`); latent until this session's demo seeded cheques made the edit page reachable. Fixed (`number` + balance label); guarded by `PdcEditPageRendersTest` (renders the form with a record — proven to fail on the old code).

Module 01's proper scoped close-out is re-run next (arg bug fixed).
