# Module 28 — Approvals (the value → approver ladder)

> **Round 2**, audited 2026-07-16 — first ever gap analysis. Spec:
> [../modules/28-approvals.md](../modules/28-approvals.md) · methodology: [000-plan.md](000-plan.md).

**Status: 🟢 Green** (F-99 fixed 2026-07-17). Fails closed on gaps, negatives, unknown permissions
— and now on overlaps too. The asymmetry that was the whole finding is closed.

---

## 1. Findings

### 🟡 F-99. `ApprovalPolicy` picks the *lowest* covering band, not the strictest — the one module whose job is to fail closed, failing open · **FIXED 2026-07-17**
`app/Support/ApprovalPolicy.php:41`

`->orderBy('min_amount')` + `->first(fn => $rule->covers($amount))` returns the **first match in
ascending-min order**. The *gap* path (`:52`) deliberately sorts by `strictness()`; the **match**
path doesn't.

**Scenario:** the operator edits band 2 from `1000–10000 → tier_2` to `1000–∞ → tier_2`, intending
"everything over 1000 needs a manager", and leaves the existing `10000+ → tier_3` band untouched
(reasonably — it looks redundant, not contradictory). A **50,000** draw now matches the 1000–∞ band
first → resolves to **tier_2** → **a manager approves what policy reserves for tier_3**, and the
resolved tier is frozen onto the part row as the tier that was "supposed" to sign it off.

**Fix (2026-07-17).** The match path now mirrors the gap path: among *covering* bands, take the
**strictest**. Guard: `tests/Feature/Regression/StockFloorAndStrictestBandTest.php` — the overlap
case fails without it, and the clean seeded ladder plus the gap path are both pinned unchanged.

**Urgency had already risen:** when the audit ran, `ApprovalPolicy` had ONE caller (stock draws).
Procurement (FRD phase 4, shipped the same day) added `PurchaseRequestService` and
`PurchaseRequestsTable` — so this governed purchase approvals before it was fixed. The "fix it
before the ladder gets a UI" note was nearly overtaken by the ladder simply gaining more callers.

> It was rated 🟡 rather than 🔴 because `approval_rules` has no admin UI (seeded/DB-only), so
> reaching it needed a DB edit. That rating had a short shelf life: the ladder gained two more
> callers the same day. **When the admin UI lands (D-89), an operator's reasonable-looking edit
> would have reached it directly** — which is why this was worth closing first.

---

## 2. Verified-correct — don't re-audit

The fail-closed behaviour was attacked from every angle and **held on all of them**:

- **A gap → the strictest tier *configured for the module***, not the last band — the out-of-order
  ladder case genuinely works.
- **Negative amounts** (`-50`) match nothing → fail closed.
- **Zero** lands in band 1.
- **Boundaries are min-inclusive / max-exclusive**, so an amount exactly at an edge lands in the
  upper band.
- **An unrecognised permission → `PHP_INT_MAX` strictness** (i.e. maximally strict).
- **Inverted/negative bands are refused** in `ApprovalRule::booted()`.
- **The sole consumer checks the base `inventory.create` right *and* blocks self-approval**, per the
  docblock's warning.

---

## 3. Test gaps

- ~~No test for overlapping bands~~ — ✅ covered. Every prior case used a clean, non-overlapping
  ladder, which is exactly why the asymmetry survived.
- **Call sites tripled in a day.** At audit time there was one (`WorkOrderPartsRelationManager:71`,
  inventory draws) and the doc noted the policy was "far less exercised than its centrality
  suggests". Procurement then added `PurchaseRequestService` + `PurchaseRequestsTable` (FR-PROC-02).
  Nothing about the policy is per-caller, so this raises exposure rather than coverage — worth
  re-reading this doc's *Verified-correct* list against the procurement path specifically. → **D-90**

## 4. Deferred

- ~~**D-88**~~ — ✅ **F-99 fixed 2026-07-17**, before the admin UI shipped.
- **D-89** — an admin UI for `approval_rules` (ROADMAP §4 phase 3). Note it is no longer the thing
  that makes F-99 reachable; that was closed first, deliberately.
- **D-90** — confirm the procurement caller honours the same two rules the inventory caller does:
  check the base right *as well as* the tier, and block self-approval. Cheap, and the audit only
  ever verified the inventory call site (module 29's own analysis should carry this).
