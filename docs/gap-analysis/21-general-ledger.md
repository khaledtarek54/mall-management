# Module 21 — General Ledger & Accounting Core

> **Round 2**, audited 2026-07-16 — this module's **first ever** gap analysis (round 1 stopped at
> module 20, before the GL existed). Spec: [../modules/21-general-ledger.md](../modules/21-general-ledger.md).
> Methodology + why absence claims don't count: [000-plan.md](000-plan.md).

**Status: 🟡 Yellow.** The engine is genuinely well built — double-entry enforcement, lock-safe
idempotency, and the registry/dispatch conformance all hold up under attack. The defects are in
*reconciliation completeness*, not in the posting engine.

`vendor/bin/pest tests/Feature/Accounting --parallel` → **green**.

---

## 1. Findings

### 🔴 F-79. `matches()` ignored `entry_date` — a date-only edit stranded the entry in the wrong period · **FIXED**
`app/Services/Accounting/LedgerPoster.php:234`

`matches()` compared entry `asset_id` + the sorted `(account|debit|credit)` line signature.
**`entry_date` appeared nowhere in the entire dispatch surface** — verified: 0 occurrences across
`LedgerPoster.php`, `SyncDocumentToLedger.php`, `SyncLedgerCommand.php`. A payload differing only
in its date matched stale, `sync()` no-op'd, and the entry kept its old date **and**
`accounting_period_id`.

**Failure scenario.** MarketingSpend 50,000 dated 2026-06-10 posts into June. The accountant fixes
a typo to 2026-07-10 (`MarketingSpendsRelationManager` exposes a plain DatePicker — deliberately
unlocked). The sweep runs → `matches()` → true → no-op. **June's P&L overstates marketing expense
by 50k and July understates by 50k, permanently.**

**Why nothing caught it:** no control account moves, so the AR/AP tie-out is blind; and
`wouldChange()` reuses `matches()`, so the close gate *and* `billing:reconcile --deep`'s
`gl_in_sync` were blind **by construction**. It hit exactly the two sources the module doc calls
"intentionally not locked" — `MarketingSpend.spent_on` and `FixedAsset.acquisition_date` — whose
stated safety argument ("edits fully reconcile via the GL cascade") was the false one.

**Fix:** `matches()` now compares a normalised `entry_date` (journalizers emit Carbon *or* string).
Guard: `tests/Feature/Regression/GapAnalysisRound2FixesTest.php`, verified to fail without it.

> This is the **sibling of the SlaPenalty bug** (`4f01a93`) that survived the registry fix:
> not a missing *source*, but a source **field the reconciler never compared**.

### ✅ F-80 — FIXED (2026-07-19). Year-end close now posts per property
`app/Services/Accounting/YearEndCloseService.php::postClosing()` · `LedgerReportService::profitLossBalancesByAsset()`

**Was:** `postClosing()` called `profitLossBalances(null, …)` and posted ONE entry with `asset_id = null`;
`aggregate()` filters `whereIn('je.asset_id', $assetIds)`, which excludes NULL, so a per-property balance
sheet showed retained earnings 0.00 forever while consolidated was correct.

**Fix:** the close now groups the year's P&L by the entry `asset_id` (`profitLossBalancesByAsset()`) and
posts **one balanced closing entry per property** (plus a consolidated bucket for any P&L posted without an
asset_id), each dimensioned to its own `asset_id` and rolling its own net into its own retained earnings.
The buckets sum to the consolidated net, so the consolidated balance sheet is unchanged; a per-property
balance sheet now rolls FY profit into that property's retained earnings. `close()` returns a collection of
entries; `reopen()` voids them all (reopening each distinct year-end period once). Locked in by
`YearEndCloseTest` ("closes per property: each property's net rolls into ITS OWN retained earnings"): FY2026
A profit 600 / B profit 300 → A's balance sheet RE 600, B's 300, consolidated 900, all balanced.
Prerequisite for the owner-statements module, which is the first to post per-property owner equity.

### 🟡 F-81. `generateNumber()` string-sorts a variable-width counter → posting breaks at 10,001 entries/month
`app/Models/JournalEntry.php:114`

`str_pad(…, 4)` doesn't truncate, so entry 10,000 → `JE-202607-10000`. `orderByDesc('number')`
compares lexicographically: `'JE-202607-9999' > 'JE-202607-10000'`. Max resolves to `…-9999` → next
= 10000 → **already exists** → unique violation on every subsequent post that month. Fails loudly
(no corruption) and 10k/month is far off for one mall — but it's a wall, not a slowdown.

### 🟡 F-82. Close gate is blind to manual drafts
`app/Services/Accounting/PeriodService.php:60`

The gate walks (a) entries with a `source_type` and (b) `SOURCE_DATE_COLUMNS` documents. A manual
draft JE has `source_type = null` and matches neither. Save a draft dated 2026-06-30, close June →
gate passes → `postDraft()` throws forever. The Post action stays visible and throws on click.
Recoverable by reopening June, so bounded.

---

## 2. Verified-correct — don't re-audit

- **Double-entry enforcement is single-sourced.** `assertLinesValid()`
  (`JournalPostingService.php:266`) is the one validator for **both** `post()` (array) and
  `postDraft()` (persisted): ≥2 lines, postable + active, one-sided, non-negative, non-zero,
  `abs(Σd − Σc) < 0.005`. No path reaches `journal_lines` without it.
- **Idempotency is genuinely lock-safe.** `sync()` locks the source row (`withTrashed()`) **and**
  the existing entry, re-reading inside the transaction; the outer transaction wraps
  void-then-repost, so a failed re-post rolls the void back. A `--all` backfill racing the daily
  sweep cannot double-book.
- **Post/void authorization is real** — `EditJournalEntry.php:35,66` use `->authorize()`, which
  `mountAction()` enforces (not `visible()`, which it does not).
- **No float drift.** `decimal(14,2)` throughout; `balanceSheet()` deliberately rounds per account
  then sums, to match `incomeStatement()` exactly.
- **The registry work held.** `SyncLedgerCommand:67`, `LedgerRealtimeSync:65` and
  `glDriftDiscrepancies:305` all iterate `LedgerPoster::sources()`. A new journalizer **cannot**
  repeat SlaPenalty's disappearance.

---

## 3. Test gaps

- **🟡 `CrossModuleGlScenarioTest` looks like dispatch proof and isn't.** It drives six sources
  through real services then a real `--all` sweep — but asserts only `gl_tie_out` passed, AR/AP
  delta 0 and trial-balance balanced. **If all six journalizers returned `null` and posted nothing,
  every assertion still holds** (those modules don't touch AP by design, and fewer balanced entries
  is still balanced). It never calls `run(deep: true)`, so `glDriftDiscrepancies()` — the one check
  that would catch an unposted doc — never runs. **Adding `expect($recon['ok'])->toBeTrue()` with
  `deep: true` would convert all six at once.** → **D-66**.
- Custody/CustodyTransaction/EmployeeAdvance/EmployeeAdvanceRepayment/DepreciationEntry/
  FixedAssetDisposal have post-direction proof only via direct `poster->sync()`. Mitigating: their
  *void* tests run the real sweep, and `syncOne()` calls `sync()` uniformly — so proving enumeration
  for void implies it for post. Weaker than SlaPenalty's hole, not identical.
- **No test asserted `entry_date` reconciliation at all** — which is why F-79 shipped green. A
  regression test must assert `entry_date`/`accounting_period_id` after a date-only edit + sweep;
  asserting amounts alone passes either way.

## 4. Deferred

- **D-66** — harden `CrossModuleGlScenarioTest` with a deep reconcile assertion.
- ~~**D-67** — F-80 per-property year-end close~~ ✅ **DONE (2026-07-19)** — per-asset closing entries
  shipped (see the F-80 ✅ entry above); done as the prerequisite for the owner-statements module.
- **D-68** — F-81 zero-pad width / numeric sort on journal numbering.
- **D-69** — F-82 close gate to include manual drafts dated in the period.
