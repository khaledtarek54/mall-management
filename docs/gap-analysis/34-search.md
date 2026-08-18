# Module 34 — Search · gap analysis

> **Round 3, 2026-08-18.** First audit — on the never-gap-analysed list in
> [PROJECT-MAP](../PROJECT-MAP.md). Method: [000-plan.md](000-plan.md).

## 1. Verdict

**The best-covered module in the system**, and the audit mostly consists of recording that. Property
isolation, RBAC, the Arabic fold, the query floor and rebuild idempotency are all already pinned by
tests that were written to fail for the right reason.

One finding: the module's single documented **exception** to blob purity had no mechanism behind it.

## 2. Finding

### 🟠 F-A — renaming a floor stranded every unit blob standing on it *(fixed)*

`Unit::searchTextSources()` quotes its floor's **code**, so an operator can narrow "ground A-1" the
way they say it. CLAUDE.md's invariant is that a blob is *a pure function of the row's OWN
attributes*, and the model documents this as the one relation hop the search policy allows —
prescribing the remedy in its docblock: run `atriom:rebuild-search` after renaming a floor.

**Nobody was ever going to run it.** The floor code is editable through an ordinary `EditAction` on
the property's floors relation manager — a screen that says nothing about search — and `Floor` had
no hook. So a rename silently froze the OLD code into every unit blob on that floor: **the new name
found nothing, the retired one still worked**, and no error connected the two.

That is the same shape as the rest of round 3 — a rule the code knew and nothing enforced. **A
documented manual remedy for a UI-triggerable action is a remedy that does not exist.**

**FIXED** — a `saved` hook on `Floor` pushes the change down to its own units, since the borrower
cannot know a borrowed value changed. Scoped two ways deliberately: only when `code` actually
changed, and only over that floor's units. It follows `RebuildSearchIndex`'s discipline for its
reasons — skip the write when the fold is unchanged, then `saveQuietly()` with timestamps off,
because re-deriving a denormalized column is not something that happened to the unit and a moved
`updated_at` would reorder every "recently changed" list in the system.
([`RenamingAFloorRefoldsItsUnitsTest`](../../tests/Feature/Regression/RenamingAFloorRefoldsItsUnitsTest.php)
— five cases including two negative controls: other floors' units are untouched, and changing a
floor's *name* does not cascade. Mutation-proven: removing the `wasChanged('code')` guard turns two
of them red.)

> **Two traps worth recording**, because both nearly produced a wrong answer.
> `refreshSearchText()` **assigns without saving** — the first version of the hook computed the blob
> and persisted nothing, and only the test caught it. And the first assertion for the retired code
> renamed `G` → `GF`, which still matched: search is substring-based, so `G` is found inside `GF`.
> That was my test being wrong, not the product.

## 3. Verified clean

| Hypothesis | Result |
|---|---|
| Global search crosses properties | **False** — `SearchIsolationTest` covers units, leases, invoices, tenant-name paths, and explicitly *"scopes the whole provider, not just the resources anyone thought to check"*, each paired with a control proving the same operator still finds their own property's records |
| A resource bypasses scoping via `getGlobalSearchEloquentQuery()` | **False** — all five overrides call `parent::` and add only eager loads |
| A role without `.view` can find records through search | **False** — covered, and the suite avoids the URL-blank false pass by pairing refusals with controls |
| Only one side of the query is folded | **False** — `SearchTextBlobTest` proves the Arabic equivalence case («شركة»/«شركه»), punctuation-free document numbers, and phone digits however stored |
| A blob misses values assigned after the first fold | **False** — document numbers (assigned in `creating`) and id-derived references are both covered by explicit cases |
| Rebuild is destructive or non-idempotent | **False** — rebuilds without touching `updated_at`, rewrites nothing on a second pass, and includes soft-deleted rows |
| A relation search path points at a model with no blob | **False** — gated; a typo'd path throws at search time, not boot, so the gate exists precisely because it would otherwise ship green |

## 4. Not assessed

- **MySQL `LIKE` performance at scale.** Every blob search is a leading-wildcard `LIKE`, which cannot
  use a B-tree index. Correct, and fine at demo volume; whether it stays fine at a real portfolio's
  row counts is a measurement nobody has taken. Recorded so the first slow-search report is
  recognised rather than debugged from scratch.
