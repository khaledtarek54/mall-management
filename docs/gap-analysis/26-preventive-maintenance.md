# Module 26 — Facility Maintenance (PPM + CM)

> **Round 2**, audited 2026-07-16 — first ever gap analysis, on the newest and largest module (the
> Eltizam FRD expansion, under active development). Spec:
> [../modules/26-preventive-maintenance.md](../modules/26-preventive-maintenance.md) ·
> methodology: [000-plan.md](000-plan.md).

**Status: 🔴 Red → 🟡 after fixes.** The core state machine, XOR, SLA clock and parts handling are
genuinely solid. **Both money paths around the SLA penalty had a real defect, and both were
untested.** Two are fixed below; one remains.

`pest --parallel --filter='WorkOrder|Maintenance|Sla|Penalty|Equipment|Part'` → 501/502 (the one
failure was missing `admin.procurement.*` keys from untracked WIP, unrelated).

---

## 1. Findings

### 🔴 F-77. A penalty could be charged to **another property's** vendor bill · **FIXED**
`FacilityWorkOrdersTable.php:315` · `app/Services/ApplySlaPenaltyService.php:100`

The picker ran `VendorBill::query()->where('vendor_id', …)` with **no asset scoping**, and
`assertBillEligible()` checked vendor + postable + balance, **never `asset_id`**. There are no global
scopes on any model, so nothing else scoped it.

**Scenario:** vendor CoolAir serves malls AAA + BBB. A CM at AAA breaches → penalty 5,000, `final`.
The operator clicks *Charge to a bill*; the dropdown lists **BBB's** bill (number + balance — a
cross-property **read leak** to a user scoped to AAA only). Picking it passed every guard → BBB's
balance 20,000→15,000, and `SlaPenaltyJournalizer:64` dimensions the entry to
`$bill->asset_id` = **BBB**. **Mall BBB absorbs a penalty earned at AAA; AAA never sees the
recovery.**

A vendor commonly serves several malls, so `vendor_id` and `asset_id` are simply not the same
question — which is exactly how it was missed. This is the precise sibling of the bug
`WorkOrderPartService::assertWarehouseServesOrder()` was written to fix (*"a job on mall AAA consumed
BBB's stock… posting the cost to BBB's GL dimension"*) — **the penalty path never got the equivalent
guard.**

**Fix:** `assertBillEligible()` now requires `bill.asset_id === penalty.asset_id`; the picker is
scoped to the work order's own property (UX — the service is the gate). Guard:
`GapAnalysisRound2FixesTest`, verified to fail without it.

### 🔴 F-78. Waiving an **applied** penalty left the bill's balance permanently wrong · **FIXED**
`app/Services/AssessSlaPenaltyService.php:105`

`waive()` guarded only `isWaived()` — never `isApplied()` — and **never called `recompute()`**. The
action is visible for any non-waived penalty (and `visible()` isn't a dispatch gate anyway).
`SlaPenalty` has no observer and no `booted()` hook, and the only `VendorBill::recompute()`
callers are `VendorBillPayment` saved/deleted, `VendorBillService` and `ApplySlaPenaltyService` — so
nothing repaired it.

**Scenario:** bill 10,000; penalty 1,000 applied → balance 9,000, GL Dr AP 1,000. The vendor
disputes → the operator waives. Penalty → `waived`, but `vendor_bill_id` stays and the bill is never
recomputed → **balance stuck at 9,000**. Meanwhile `LedgerRealtimeSync` fires on `saved` → the
journalizer returns `null` (it posts only an APPLIED penalty) → `LedgerPoster::sync()` correctly
**voids** the entry → GL AP back to 10,000. **Bills say 9,000, GL says 10,000: the AP tie-out breaks
by exactly the penalty and the vendor is underpaid.** If the penalty had settled the bill, it stays
`status='paid'` while 1,000 is owed.

`detach()` does this correctly (locks, clears the FK, recomputes) — **`waive()` is the path that
forgot.**

**Fix:** `waive()` is now lock-safe, releases `vendor_bill_id`/`applied_at`, and recomputes the bill.
Guard: `GapAnalysisRound2FixesTest`, verified to fail without it.

### ✅ F-96. `--dry-run` writes — **FIXED 2026-07-18**
`app/Console/Commands/ScanWorkOrderSlaBreachesCommand.php:53`

`assessPenalties($overdue)` runs **before** the dry-run check. So
`php artisan facility:scan-sla-breaches --dry-run` — documented *"Print what would be alerted
**without writing**"* — creates/updates real `sla_penalties` rows. Impact is bounded (the
hourly run would create them anyway), but an operator previewing impact on a fresh install gets live
financial records.

**Fix:** `--dry-run` now previews (both what would be alerted and that penalties would run) and
returns **before** both writes — `assessPenalties()` and the alert loop. A real (non-dry) run still
assesses on every pass, so accrual is unchanged. Guard: `WorkOrderSlaDryRunTest`.

---

## 2. Verified-correct — don't re-audit

- **The FR-PPM-07 checklist gate is enforced in `FacilityWorkOrderService::transition()` under
  the parent-row lock, not the UI.** (`open→done` skipping `in_progress` is legal **by design** —
  the doc is explicit.)
- **The `execution_type` XOR throws from both directions** in `FacilityWorkOrder::booted()`, with
  the service nulling the unused side.
- **The SLA clock stamps once on acceptance**, and `hoursOverSla()` stops at `completed_at`.
- **`FacilityWorkOrderPart::booted():243` derives `value` on every write path** (`recordExternal`
  omits it deliberately — checked before assuming a bug).
- **`StockMovementService` re-checks on-hand under `lockForUpdate`**, so approval cannot drive stock
  negative.
- **`SlaPenalty` is now in `JOURNALIZERS` *and* `SOURCE_DATE_COLUMNS`** — the `4f01a93` fix
  held.

---

## 3. Test gaps

- **🔴 The penalty's entire GL suite proves arithmetic, not production dispatch.**
  `SlaPenaltyChargeScenarioTest:210,232` call `LedgerPoster::post()` **directly**, and `:248,256`
  call the journalizer's `payload()` directly. It never drives `accounting:sync-ledger`. **This is
  precisely the anti-pattern that let the original dispatch bug ship green** — and note that
  **parts got the fix** (`WorkOrderPartLedgerDispatchTest:70` drives the real sweep) **while the
  penalty's own test never did.** The AP tie-out at `:226` is proven only under manual posting —
  which is exactly why it could not see F-78. *(`SlaPenaltyLedgerDispatchTest` now covers the real
  path.)*
- **No test for waive-after-apply** — `SlaPenaltyChargeScenarioTest:167` covers apply-after-waive,
  the safe direction only. (Now covered.)
- **No test that a penalty must be charged to its own property's bill** (F-77). (Now covered.)

## 4. Deferred

- ~~**D-84**~~ — ✅ **F-96 fixed 2026-07-18**: `--dry-run` returns before `assessPenalties()` and the
  alert loop (previewing both, writing neither). Guard: `WorkOrderSlaDryRunTest`.
- **D-85** — harden `SlaPenaltyChargeScenarioTest` to drive the real sweep, or fold its assertions
  into `SlaPenaltyLedgerDispatchTest`.
