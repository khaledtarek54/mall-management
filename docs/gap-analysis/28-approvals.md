# Module 28 — Approvals (the value → approver ladder)

> **Round 2**, audited 2026-07-16 — first ever gap analysis. Spec:
> [../modules/28-approvals.md](../modules/28-approvals.md) · methodology: [000-plan.md](000-plan.md).

**Status: 🟡 Yellow.** Fails closed exactly as documented on gaps, negatives and unknown permissions
— **but fails *open* on an overlap.** That asymmetry is the whole finding.

---

## 1. Findings

### 🟡 F-99. `ApprovalPolicy` picks the *lowest* covering band, not the strictest — the one module whose job is to fail closed, failing open
`app/Support/ApprovalPolicy.php:41`

`->orderBy('min_amount')` + `->first(fn => $rule->covers($amount))` returns the **first match in
ascending-min order**. The *gap* path (`:52`) deliberately sorts by `strictness()`; the **match**
path doesn't.

**Scenario:** the operator edits band 2 from `1000–10000 → tier_2` to `1000–∞ → tier_2`, intending
"everything over 1000 needs a manager", and leaves the existing `10000+ → tier_3` band untouched
(reasonably — it looks redundant, not contradictory). A **50,000** draw now matches the 1000–∞ band
first → resolves to **tier_2** → **a manager approves what policy reserves for tier_3**, and the
resolved tier is frozen onto the part row as the tier that was "supposed" to sign it off.

**Suggested fix:** mirror the gap path — among *covering* bands, take the **strictest**, not the
first. Two lines, and it makes the match path consistent with the gap path that already gets this
right.

> There is no admin UI for `approval_rules` (seeded/DB-only), so today this requires a DB edit —
> which is also why it's 🟡 not 🔴. **The moment the ladder gets a UI (FRD phase 3/4), this becomes
> reachable by an operator making a reasonable-looking edit.** Fix it before shipping that UI.

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

- **No test for overlapping bands** (F-99) — every existing case has a clean, non-overlapping ladder,
  which is exactly why the asymmetry survived.
- The module has **one call site** (`WorkOrderPartsRelationManager.php:71`, inventory draws) despite
  FR-PROC-02 implying procurement scope. Not a defect — but it means the policy is far less
  exercised than its centrality suggests.

## 4. Deferred

- **D-88** — **F-99: take the strictest covering band.** Do this *before* the approval-ladder admin
  UI ships, not after.
- **D-89** — an admin UI for `approval_rules` (ROADMAP §4 phase 3). When it lands, D-88 stops being
  theoretical.
