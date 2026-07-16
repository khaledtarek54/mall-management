# Module 25 — Treasury / Custody (عهدة)

> **Round 2**, audited 2026-07-16 — first ever gap analysis. Spec:
> [../modules/25-treasury-custody.md](../modules/25-treasury-custody.md) · methodology: [000-plan.md](000-plan.md).

**Status: 🔴 Red.** The lock-safe over-settle guard and property scoping are correct — but a
custody's **defining workflow** (receipts arriving after the fact) routinely posts into a closed
period and silently diverges the GL from outstanding, and **no settlement can ever be corrected**.

`pest --filter='Custody|Treasury'` → green. The module has **~5 test files** despite posting money
to the ledger via two journalizers.

---

## 1. Findings

### 🔴 F-93. A settlement dated before its period closed silently diverges the GL from outstanding — and this is the *normal* workflow, not a typo
`app/Services/SettleCustodyService.php:37` · `CustodyTransactionsRelationManager.php:89,118`

`transaction_date` is passed straight through. **No `minDate` (not even ≥ `custody_date`), no
`maxDate`, no open-period check.** Verified by reading the service and both DatePickers.

**Scenario:** custody 10,000 granted to Ahmed **2026-05-20** (Dr Custodies / Cr Cash). May closes
2026-06-05. On 2026-06-10 Ahmed hands in his receipts; the accountant records a 5,000 expense dated
**2026-05-28** — the receipt's real date. The service accepts (5,000 ≤ outstanding) and commits.
`CustodyTransactionJournalizer` sets `entry_date = 2026-05-28` → `assertOpenPeriodFor` throws
**inside the queued job, which logs and swallows** (verified: `SyncDocumentToLedger::handle()`
catches `\Throwable` and only `Log::warning`s). The accountant sees "Expense recorded ✓".
**Outstanding reads 5,000; the GL still shows Custodies 10,000 and zero expense.**

This is the exact shape of the MaintenancePenalty bug: **business state moved, the GL didn't, and
the operator was told it succeeded.**

**The open-period variant is worse because it's silent even to the sweep:** a settlement dated
2026-05-15 against a custody granted 2026-06-01 posts Cr Custodies 5,000 into May while the Dr grant
lands in June — **May's trial balance shows Custodies at −5,000, a credit balance on an asset
account.** Nothing anywhere forbids `transaction_date < custody_date`.

**Why 🔴 and not 🟡:** a عهدة exists *because* receipts arrive late. Back-dating a settlement across
a month-end close isn't operator error — **it's the module's reason for existing.**

### 🟡 F-94. One mis-keyed settlement permanently bricks the custody — no correction path for the role that owns the module
`CustodyTransactionsRelationManager.php:72` (headerActions only — **no `recordActions` block at
all**) · `CustodyForm.php:42` · `SettleCustodyService.php:27`

Searched all of `app/` for `CustodyTransaction`: **no edit, no delete, nowhere.**

**Scenario:** custody 10,000. Ahmed spends 500 on cleaning; the accountant records **5,000**. GL: Dr
Cleaning Expense 5,000 / Cr Custodies 5,000. Cleaning overstated 4,500; Custodies understated 4,500;
outstanding reads 5,000 vs the real 9,500 in Ahmed's pocket. The accountant — who **owns**
`custodies.*` — now cannot edit the settlement, delete it, fix the custody amount (`$granted` locked
the instant the first settlement landed), or post a compensating negative (`abort_unless($amount >
0)`). Only `custodies.delete` (super_admin) works, and it **cascades — voiding the correct grant
entry too** and destroying the audit trail.

> Every other money document in Atriom has a correction path: invoice → credit note, journal entry →
> void, payroll → cancel, vendor bill → void. **Custody settlements have none.**

### 🟢 F-95. A custody can be granted with a future `custody_date`
`CustodyForm.php:43` — no `maxDate`. Same family as F-93; posts the grant into a future period. Low
impact (`ensureFiscalYears` opens the year). Recorded for completeness.

---

## 2. Verified-correct — don't re-audit

- **Over-settlement is genuinely lock-safe** — `SettleCustodyService:24` locks the custody row, then
  recomputes `outstanding()` from a fresh `SUM` **inside** the transaction. **Outstanding is derived
  everywhere, never stored** (`Custody::settled()/outstanding():70`); the table uses a `withSum`
  alias for display only. There is no cached balance column for anyone to write.
- **"Locked once settled" is a real server-side guard, not cosmetic** — checked in the framework
  rather than assumed: `CanBeDisabled::disabled()` wires `saved(fn => ! evaluate($condition))` and
  `HasState::isDehydrated():776` evaluates it **at dehydration time**, so `$granted`'s
  `transactions()->exists()` re-queries at save. Amount/date/paid_from genuinely cannot be tampered
  post-settlement, and even the fill→settle→submit race is closed. Same mechanism protects the
  custodian and approved payroll headers.
- **Property isolation holds.** `CreateCustody:19` calls `assertAssetInScope($employee->asset_id)`;
  `employeeOptions()` uses the correct `currentAssetId() → visibleAssetIds()` fallback (not
  `currentAssetId()` alone); `employee_id` is disabled on edit so `asset_id` can't be re-derived. A
  manager of mall A cannot grant or settle against mall B.
- **The child-source cascade is correct** — `Custody::booted():100` stamps settlements with the
  parent's own `deleted_at` and restores on an exact match, keeping the windowed sweep self-healing.

---

## 3. Test gaps

- **Custody's GL post is only partially proven.** The sweep runs in exactly one test
  (`CustodyLedgerTest:138`, the void-on-delete case) and even there the *initial* post came from a
  direct `poster->sync()`. That the sweep **visits** this source is genuinely proven (the void
  assertion would fail otherwise); that a **fresh grant posts through it** is not.
  `CustodyScenarioTest` is direct-sync throughout. → **D-66**
- **No test dates a settlement into a closed period** (F-93) — one test catches the headline bug.
- **No test dates a settlement before its custody's grant date** — the negative-asset variant.
- **No concurrency test** for `SettleCustodyService`'s lock; sqlite `:memory:` cannot exercise the
  InnoDB semantics the pattern depends on.
- **No aging/exposure report exists** for outstanding custodies or staff advances (searched
  `app/Services/Reports/`, `app/Filament/Admin/Pages/`, `app/Services/Reconciliation/` — zero
  references). Not a defect in itself, but it means **F-93's divergence has no reporting surface
  where an accountant would ever notice it.**

## 4. Deferred

- **D-80** — **F-93, the priority for this module.** Validate `transaction_date`: ≥ `custody_date`,
  ≤ today, and in an OPEN period — refuse at the service, not the form. Same fix shape as
  [D-77](24-hr-employees.md); do them together.
- **D-81** — F-94 a correction path for custody settlements (void/reverse, mirroring credit notes).
- **D-82** — F-95 `maxDate` on `custody_date`.
- **D-83** — a custody/advance outstanding-exposure report, so divergence has somewhere to surface.
