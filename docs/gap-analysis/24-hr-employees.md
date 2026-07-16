# Module 24 — HR / Employees (payroll, advances)

> **Round 2**, audited 2026-07-16 — first ever gap analysis. Spec:
> [../modules/24-hr-employees.md](../modules/24-hr-employees.md) · methodology: [000-plan.md](000-plan.md).

**Status: 🟡 Yellow.** Payroll math, the over-repayment guard and property isolation are sound. The
gaps are an unguarded **per-line** net, a repayment that can be dated into a closed period, and no
correction path for a mis-keyed repayment.

`pest --filter='Payroll|Employee|Advance'` → **88 passed / 321 assertions**.

---

## 1. Findings

### 🟡 F-89. A repayment can be dated into a closed period → outstanding drops, the GL never records it · **FIXED 2026-07-17**
`app/Services/RecordAdvanceRepaymentService.php:34` · `EmployeeAdvancesRelationManager.php:137`

`repaid_on` is passed straight through — no `minDate`, no `maxDate`, no open-period check (the only
guards are amount-based).

**Scenario:** advance 10,000 granted 2026-05-10. May closes 2026-06-05. On 2026-06-10 the accountant
records a 4,000 repayment dated **2026-05-28** (when the cash actually moved). The service accepts;
the journalizer sets `entry_date = 2026-05-28`; `assertOpenPeriodFor` throws — **inside the queued
`SyncDocumentToLedger` job, which is explicitly best-effort and logs rather than retries**. The
accountant sees "Repayment recorded ✓". Outstanding now reads 6,000; the GL still carries Employee
Advances at 10,000 and never sees the cash. Surfaces only later, to a different role, as an opaque
`ledger_last_sync_failures` count.

> Same family as **[F-93 in module 25](25-treasury-custody.md)**, which is 🔴 because back-dating
> across a close is a custody's *normal* workflow rather than an edge case. **One `App\Support\PostingDate`
> guard closed both.**

### 🟡 F-90b. A payroll line's net can go negative — the guard only checks the header
`app/Services/PayrollService.php:25` (guards the header) · `PayrollLine.php:50` (accessor, no guard)
· `PayrollLinesRelationManager.php:89` (per-field `minValue(0)`, no cross-field check)

**Scenario:** Line A: Sara, gross 50,000 / tax 0 / ins 0. Line B: Ahmed, gross 5,000 / tax 0 / ins
**6,000** (meant 600). Header Σ = gross 55,000, ins 6,000, net **49,000** → `approve()`'s
`net_paid < 0` check passes → GL posts Dr Salaries 55,000 / Cr Social Insurance 6,000 / Cr Cash
49,000. **Ahmed's payslip PDF prints Net −1,000.** The run is now frozen (`runIsEditable()` false) —
the line can never be corrected; the whole run must be cancelled and re-keyed. Insurance liability
overstated by 5,400.

### 🟡 F-91. A mis-keyed repayment is permanently uncorrectable
`EmployeeAdvancesRelationManager.php:119`

Searched all of `app/` for `EmployeeAdvanceRepayment`: only the model, `PropertyIsolation`,
`LedgerRealtimeSync`, the service, the journalizer and `LedgerPoster` reference it. **No edit or
delete action exists anywhere** — the relation manager's `recordActions` act on the *advance*.

**Scenario:** advance 10,000; accountant records **5,000** instead of 500. GL: Dr Cash 5,000 / Cr
Employee Advances 5,000. Outstanding shows 5,000 (real: 9,500); cash overstated 4,500. No fix: can't
edit, can't delete, and a compensating negative is blocked by `abort_unless($amount > 0)`. Only
escape = super_admin soft-deletes the **whole advance**, which cascades and voids the correct grant
entry too.

### 🟢 F-92. The payroll-line employee picker offers terminated staff
`PayrollLinesRelationManager.php:147` — `Employee::query()` with no `->active()`, unlike
`CustodyForm::employeeOptions()` and both grant services. **Stated honestly:** final-settlement dues
are legitimately paid after termination, so an `active()` filter would be *wrong*. The real gap is
that the picker gives **no signal**. Recording the inconsistency with the module's own rule #5, not
asserting the filter should exist.

---

## 2. Verified-correct — don't re-audit

- **`net_paid` cannot drift.** `Payroll::booted():135` re-derives it on **every** write path, and
  `recomputeFromLines()` sets it explicitly on the `saveQuietly` path. `approve()` refuses a
  net-negative header.
- **Over-repayment is genuinely lock-safe.** `RecordAdvanceRepaymentService:24` takes
  `lockForUpdate` on the advance, then recomputes `outstanding()` **inside** the transaction from a
  fresh `SUM`. The form's `maxValue()` is UX; the service is the gate.
- **Every money action is `->authorize()`d, not just `visible()`** — `grant_advance`,
  `record_repayment`, `terminate`, `approve`/`cancel_payroll`, each with a server-side
  `abort_unless` re-check inside `action()`.
- **The payslip PDF does not leak.** The blade renders only `$line`'s own fields; the record resolves
  through `$ownerRecord->lines()`, the owner through the property-scoped resource, and download is
  gated on `payrolls.view` + `abort_unless`.

---

## 3. Test gaps

- **🔴 Payroll is the weakest source in the GL — no payroll test ever runs the sweep.** Every GL
  assertion in `PayrollTest.php` and `PayrollLineTest.php` is `$this->poster->post($p->fresh())`.
  **No test asserts the operator path: `PayrollService::approve()` → `accounting:sync-ledger` →
  entry exists.** The approve test only checks status. Structurally covered by
  `GlRegistryConformanceTest`; behaviourally unproven. → **D-76**
- **No test dates a repayment into a closed period** (F-89) — one test would catch it.
- **No test asserts a per-line net stays ≥ 0** (F-90b) — nothing would catch a negative payslip today.
- **No concurrency test** for `RecordAdvanceRepaymentService`'s lock. It looks correct by inspection,
  but the `lockForUpdate` → separate `SUM()` pattern depends on InnoDB read-view semantics that
  sqlite `:memory:` **cannot exercise at all**.

## 4. Deferred

- **D-76** — prove payroll's GL post through the real sweep, not a direct poster call.
- ~~**D-77**~~ — ✅ **F-89 fixed 2026-07-17** via `App\Support\PostingDate`, together with
  [D-80](25-treasury-custody.md). Guard: `PostingDateGuardTest`.
- **D-78** — F-90b cross-field net guard per payroll line.
- **D-79** — F-91 a correction path for repayments (edit/void), mirroring credit-note/void patterns
  elsewhere.
