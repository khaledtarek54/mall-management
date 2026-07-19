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
2. **Gap sweep** — parallel lenses: competitor parity · correctness/bugs · tie-out & property-isolation · coverage/conformance · FRD conformance. Every finding **adversarially verified**.
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
| 1 | 01 | Properties & Units | ⏳ In review | — | foundation — first module |
| 2 | 02 | Tenants | ⬜ Not started | — | |
| 3 | 04 | Leases | ⬜ Not started | — | |
| 4 | 05 | Billing & Invoices | ⬜ Not started | — | |
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

## Closure records

_One section per module as it closes, most recent first._

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

Module 01's proper scoped close-out is re-run next (arg bug fixed).
