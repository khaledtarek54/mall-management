# Module 23 — Fixed Assets & Depreciation (الأصول الثابتة والإهلاك)

> **Status: COMPLETE (Phase 2b shipped — disposal write-off).** Fixed-asset register +
> straight-line depreciation service + the monthly `accounting:post-depreciation` run +
> a property-scoped Filament `FixedAssetResource` (cost / monthly / accumulated / NBV
> columns, dispose-with-proceeds, read-only depreciation schedule, "post this month"
> action) + `fixed_assets.*` RBAC + the `fixed_assets` module flag + **full double-entry
> GL posting** (acquisition, monthly depreciation, and the disposal write-off with
> gain/loss, all via the sweep) + tests. Delivers the FRD/gap-analysis backlog item
> FA-1 — the chart's fixed-asset accounts (furniture, accumulated depreciation,
> depreciation expense, plus new gain/loss on disposal) are now **live**.

Property operators own real assets — furniture, HVAC, fit-out, IT, deep-clean
machines. This module keeps the **register** of those assets and recognises their
cost over time via **straight-line depreciation**, so the balance sheet shows net
book value and the P&L carries the monthly depreciation charge.

---

## 0. Design decisions

| Decision | Choice | Why |
|---|---|---|
| **Depreciation method** | **Straight-line** | (cost − salvage) ÷ useful-life-months; the standard default. Declining-balance is a future option. |
| **Schedule model** | **Entry ledger** (one `depreciation_entry` per asset per month; accumulated DERIVED) | Auditable + reconcilable — mirrors the GL / inventory "derived truth"; the monthly run is idempotent via a unique (asset, month). |
| **Scope** | **Per-property** (`asset_id`) | Each mall's fixed assets belong to it; scoped like units/leases/inventory. |
| **Acquisition funding** | `funded_from` (cash \| bank) | The credit side of the acquisition GL entry (Phase 2) — most fixed assets are paid, so this avoids inflating Accounts Payable. |

---

## Importing the register at cut-over (`FixedAssetImporter`, 2026-08-12)

`/admin/fixed-assets` → **Import**. The register feeds depreciation and the balance sheet from day
one, so this is the importer with the most immediate accounting consequence.

**Every imported asset is an OPENING BALANCE.** That is not a column on the file — it is what
importing means here, and two things follow:

- **It posts no acquisition.** `fixed_assets.is_opening_balance` makes
  `FixedAssetAcquisitionJournalizer` return null. A 2023 chiller's cost is already inside the
  accountant's opening journal entry; posting `Dr Furniture & Equipment / Cr Cash` again would
  double it — or be refused for landing in a closed period and stranded inside the best-effort sync
  job. Same rule, same reason, as `invoices.is_opening_balance`.
- **It carries the depreciation already taken**, in `opening_accumulated_depreciation`. Without it a
  chiller three years into a ten-year life would charge its FULL cost again over another ten years
  while the balance sheet carried it at cost. The column is **required** on import: a blank is not
  "zero", it is "the operator has not told us", and a silent zero is the version nobody notices for
  a year.

> **`FixedAsset::accumulatedDepreciation()` is the ONE definition** — opening figure plus posted
> entries. It lives on the model rather than in `DepreciationService` because accumulated
> depreciation was being computed in **four** places: the service, `FixedAssetDisposalJournalizer`
> (its own sum, for gain-or-loss on sale), and a SQL `withSum` in `FixedAssetResource` feeding both
> the table and the register CSV. Teaching one about the opening figure and not the others would
> have booked a phantom loss on every legacy asset ever sold and reported every imported asset at
> cost on the balance-sheet schedule.
>
> The SQL one cannot call PHP — the table sorts on it — so it is a deliberate second expression of
> the rule, and `FixedAssetOpeningBalanceTest` asserts the two agree. That test is the only thing
> keeping a duplicated rule honest.

Identity is **(property, tag)**: the tag is the label stuck on the machine and is unique within a
mall, not globally, because two malls each number their chillers from 1. Property-scoped through
`ResolvesVisibleAssetByCode` — an import bypasses the Create/Edit pages where `assertAssetInScope()`
runs, and an out-of-scope row is skipped rather than written. `method` and `funded_from` are not
importable: depreciation is straight-line only, and `funded_from` picks the credit side of an entry
this importer never posts.

## 1. Domain model

### `fixed_assets` — the register (per property)
| Column | Meaning |
|--------|---------|
| `asset_id` | the property (FK, cascade) |
| `name` · `tag` | label + asset tag (**unique per property**) |
| `category` | free-form (furniture / HVAC / IT …) |
| `acquisition_date` · `acquisition_cost` · `salvage_value` | cost basis |
| `useful_life_months` | straight-line period |
| `method` | `straight_line` (only method today) |
| `funded_from` | `cash` \| `bank` — acquisition credit (Phase 2 GL) |
| `status` · `disposed_on` | `active` / `disposed` |

### `depreciation_entries` — the monthly charge ledger
| Column | Meaning |
|--------|---------|
| `fixed_asset_id` | the asset (FK, cascade) |
| `period_month` | first day of the depreciated month (**unique per asset**) |
| `amount` | that month's charge |
| `created_by_user_id` | audit |

### `fixed_asset_disposals` — the terminal write-off (one per asset)
| Column | Meaning |
|--------|---------|
| `fixed_asset_id` | the asset (FK, cascade, **unique**) |
| `disposed_on` | disposal date (the write-off entry date) |
| `proceeds` · `proceeds_account` | sale proceeds (0 = scrapped) + where they landed (`cash`\|`bank`) |
| `notes` · `created_by_user_id` | audit |

---

## 2. Business rules

**Two guards were promoted to the model on 2026-08-11 (module 23 close-out).** Both were reachable
only through a Filament page, and this module has no create/update service — the model's own save is
the single choke point every path shares (form, console, seeder, factory, import, API), which is the
same reasoning the `acquisition_date` posting-date guard already rested on.

- **A re-cost may never fall below what has already been depreciated.**
  `DepreciationService::assertRecostValid()` states the reason itself: accumulated of 60,000 against
  a new base of 30,000 leaves the ledger carrying −30,000 of net fixed assets. It had exactly ONE
  caller — `EditFixedAsset` — so every other writer walked straight past it. Salvage counts, because
  the base is cost − salvage. Tests: `FixedAssetTerminalAndRecostGuardsTest`.

- **A DISPOSED asset's money and identity fields are frozen** (`acquisition_cost`, `salvage_value`,
  `acquisition_date`, `useful_life_months`, `method`, `asset_id`, `disposed_on`, `status`). Disposal
  is terminal and posts a write-off. The `updated` hook *deliberately* re-derives the child entries
  when `acquisition_cost` moves — right for a live asset whose cost is genuinely corrected, and
  exactly wrong for one that has been sold: it restates an already-posted disposal, changing the gain
  or loss on a sale that happened, in a period that may since have closed, while the acquisition
  entry moves and the disposal's credit does not — leaving Furniture & Equipment carrying an asset
  the company no longer owns. Housekeeping (name, tag, category, notes) stays editable, and the guard
  reads the ORIGINAL status so the disposal itself is not blocked by its own outcome.

**Verified clean during the same pass, and worth recording so nobody re-checks it:** accumulated
depreciation is DERIVED from `depreciation_entries` and never stored, so the "two truths about one
number" class that bit modules 22 and 01 cannot arise here; the monthly run is locked, idempotent per
(asset, month), clamped so accumulated never exceeds the base, and skips assets whose property was
soft-deleted; both GL sources (`DepreciationEntry`, `FixedAssetDisposal`) are exercised through the
real `accounting:sync-ledger` sweep rather than the journalizer alone; and the posting-date guard
covers the disposal, the acquisition date and `--month` on the backfill command.


1. **Monthly charge** = `(acquisition_cost − salvage_value) ÷ useful_life_months`, rounded 2dp.
2. **Accumulated depreciation is derived** = `SUM(depreciation_entries.amount)`; net book
   value = `cost − accumulated`. Never a cached column.
3. **The run is idempotent + lock-safe**: one entry per (asset, month); each asset row
   is locked and re-checked inside its own transaction (the project scheduled-scan invariant).
4. **Never over-depreciates**: the final charge is clamped to the remaining depreciable
   base, so accumulated tops out at `cost − salvage` (never beyond).
5. **No charge before the acquisition month**, and **none once fully depreciated** or `disposed`.
6. **NOT-NULL money** — blank `acquisition_cost`/`salvage_value` coerce to 0 in the model.
7. **Posting respects property authority** — the scheduled `accounting:post-depreciation`
   run is portfolio-wide, but the admin **"Post this month"** button passes the operator's
   visible-property set (`TenantScope::visibleAssetIds()`), so a single-property accounting
   user can never post another mall's depreciation. `run(?period, ?assetIds)` — `null` = all.
8. **Property is scope-guarded on write** — create/edit re-validate the submitted `asset_id`
   against `visibleAssetIds()` server-side (`FixedAssetResource::assertAssetInScope`), closing
   the All-Properties-mode tamper hole even though the Select already scopes its options.
9. **Every date that becomes a GL entry_date is closed-period guarded** (gap-analysis,
   2026-07-29). Three operator-typed dates in this module date a journal entry, and none was
   checked: `acquisition_date` (acquisition entry), `disposed_on` (write-off entry) and
   `period_month` (each depreciation charge). Dated into a **closed** period, the register row
   commits and the operator is told it worked, while the journal entry is refused inside the
   best-effort `SyncDocumentToLedger` job, which logs rather than retries — business state moves,
   the GL does not. Now guarded via `App\Support\PostingDate`:
   - **`disposed_on`** in `DisposeFixedAssetService`, *before* the transaction. The worst of the
     three: a disposal is TERMINAL and cannot be re-run, so Furniture & Equipment goes on carrying
     an asset the company has sold, Accumulated Depreciation is never cleared, and the gain/loss
     never reaches the P&L. It is also the one most likely to be back-dated — the sale happens
     before the paperwork reaches accounting.
   - **`acquisition_date`** in `FixedAsset::saving()` — this module has no create/update service,
     so the model's save is the single choke point every path shares. Fires **only when the date
     is dirty**: re-checking every save would make an asset acquired in a since-closed month
     uneditable (you could not fix its name), which is a different rule from the one intended.
   - **`--month`** in `PostDepreciationCommand`. Only reachable from the console (the scheduler and
     the admin button both use `now()`), but that is exactly the backfill someone reaches for after
     a close — and because the run is idempotent, a later re-run would SKIP the month, making the
     gap permanent.

   A **MISSING** period stays legal (only a CLOSED one is refused), so installs without a chart of
   accounts are unaffected. Tests: `tests/Feature/Regression/FixedAssetClosedPeriodTest.php`.
10. **A disposed asset is terminal (immutable)** — once written off it can't be edited (the edit
   action is hidden + the edit page aborts 403) nor re-disposed. Editing a disposed asset's cost
   would strand its Furniture balance; as a model-level backstop, a change to `acquisition_cost`
   (or `asset_id`) re-flows to the child sources' GL via the parent-lifecycle cascade.

---

## 3. Services & commands

- **`DepreciationService`** — `monthlyAmount()`, `depreciableBase()`, `accumulatedFor()`,
  `netBookValue()`, and `run(?period, ?assetIds)` (the idempotent, lock-safe monthly poster;
  `assetIds` scopes to a property set, `null` = portfolio-wide).
- **`accounting:post-depreciation {--month=YYYY-MM}`** — runs the month's depreciation;
  scheduled monthly (28th 03:30). Idempotent, so a re-run is harmless.
- **`DisposeFixedAssetService`** — the single-action disposal: lock-safe, flips the asset to
  `disposed`, and records the terminal `FixedAssetDisposal` (with proceeds) that the disposal
  journalizer writes off. Rejects a second disposal (422 — terminal).

---

## 3.5 GL posting (Phase 2 + 2b)

Fixed assets post to the double-entry ledger through **three journalizers**, registered in
`LedgerPoster` and reconciled by the `accounting:sync-ledger` sweep — the same
self-healing, idempotent path inventory and marketing spend use (no entanglement with the
write path). They credit/debit only asset/expense/income accounts — **never AR or AP** — so
the GL↔AR/AP tie-out that gates monthly close is unaffected (the GRNI lesson from module 22).

| Event | Source | Entry |
|-------|--------|-------|
| **Acquisition** | `FixedAsset` | Dr Furniture & Equipment `12101001` / Cr **Cash `11101001` \| Bank `11102001`** (per `funded_from`) |
| **Monthly depreciation** | `DepreciationEntry` | Dr Depreciation Expense `51107001` / Cr Accumulated Depreciation `12201001` (contra-asset) |
| **Disposal write-off** | `FixedAssetDisposal` | Dr Accumulated Depreciation (accumulated) + Dr Cash\|Bank (proceeds) + Dr **Loss `52102001`** / Cr Furniture & Equipment (cost) + Cr **Gain `42102001`** |

- **Cash/Bank, not AP** — most fixed assets are paid on acquisition and a fixed asset has
  no vendor bill; crediting the AP control (which ties out to vendor bills) would falsely
  fail the reconcile. Same reasoning as inventory's GRNI clearing account.
- **Disposal math** — net book value = cost − accumulated; gain/loss = proceeds − NBV. The
  write-off + the (retained) acquisition + depreciation entries together net Furniture &
  Equipment and Accumulated Depreciation back to zero for the disposed asset; the gain/loss
  hits the P&L. Proceeds `0` = a pure scrap write-off (loss = remaining NBV).
- **Mappings:** `furniture_equipment`, `accumulated_depreciation`, `gain_on_disposal`,
  `loss_on_disposal` (added to `AccountMappingSeeder`; `depreciation_expense`, `cash`, `bank`
  already existed). Re-point any role from the UI without code.
- **Parent-lifecycle cascade (self-healing under the WINDOWED sweep):** each depreciation charge
  and the disposal are their OWN ledger sources, but the sweep discovers sources by their own
  `updated_at`. So `FixedAsset::booted()` cascades the parent's lifecycle to **both** child
  sources (`ledgerChildRelations()`) — soft-delete soft-deletes them (their entries void),
  restore restores only the exact rows it trashed (matched on `deleted_at`), and a property
  re-home touches them (re-dimensions their entries) — bumping their `updated_at` so the daily
  sweep re-visits them. Without this they would strand (phantom GL / wrong property) until a
  manual `--all` backfill. **Caveat:** `forceDelete()` (out-of-band only — no admin/console path
  exposes it) physically removes the child rows via the FK cascade and orphans their posted
  entries; use soft-delete to correct a mistaken asset. This is a general property of
  force-deleting any GL source, not fixed-asset-specific.

---

## 4. Roadmap

| Phase | Scope | Status |
|-------|-------|--------|
| **1a — Depreciation engine** | register + `DepreciationEntry` + `DepreciationService` (straight-line, derived accumulated/NBV, clamp) + `accounting:post-depreciation` + schedule + tests | ✅ shipped |
| **1b — Admin surfaces** | Filament `FixedAssetResource` (register + schedule columns: cost / accumulated / NBV / monthly) property-scoped, `fixed_assets.*` RBAC (accounting role), `fixed_assets` module flag, read-only depreciation-history relation manager, dispose action, "post this month" list action | ✅ shipped |
| **2 — GL posting** | acquisition → Dr Furniture & Equipment (12101001) / Cr Cash\|Bank (per `funded_from`); depreciation entry → Dr Depreciation Expense (51107001) / Cr Accumulated Depreciation (12201001). Journalizers + mappings + sweep + a tie-out-safe check. | ✅ shipped |
| **2b — Disposal write-off** | `FixedAssetDisposal` source + `DisposeFixedAssetService` + journalizer: Dr Accumulated Depreciation + Dr Cash\|Bank (proceeds) + gain/loss / Cr Furniture & Equipment, so the balance sheet clears the disposed asset. New gain/loss-on-disposal accounts + mappings; dispose-with-proceeds form; parent-lifecycle cascade covers it. | ✅ shipped |

---

### Register CSV export (UX, 2026-07-23)

The register lived only on screen. An accountant preparing or reconciling the balance sheet's
fixed-asset line needs it as a spreadsheet — cost, accumulated depreciation and net book value per
asset — not a table they can only look at. Added an **Export CSV** action (shared `App\Support\ReportCsv`,
UTF-8 BOM so Excel renders Arabic). `FixedAssetResource::registerCsv()` reads the **same
property-scoped query and derived `accumulated` subquery the table shows** (so the export can never
disagree with the screen), emits tag / name / category / property / acquisition date / cost / monthly
charge / accumulated / NBV / status per asset, and closes with **cost, accumulated and NBV totals**.
Double-gated (`visible()` + `authorize()` on `canViewAny()`). Parallels the inventory stock register
and the module-17 financial-report exports — the same accountant-workable finding.

## 5. Tests

`tests/Feature/Regression/FixedAssetRegisterCsvTest.php` — the register CSV values each asset at
`cost − accumulated depreciation` **scoped to the user** (a restricted accounting user gets their
mall's assets, not the portfolio) and closes with cost / accumulated / NBV totals.

`tests/Feature/Services/DepreciationServiceTest.php` — monthly amount (net of salvage),
one entry per asset per month, derived accumulated/NBV, idempotent re-run, no charge
before acquisition, stops-at-base (never over-depreciates), disposed skipped, NOT-NULL
coercion, the command.

`tests/Feature/Resources/FixedAssetResourceTest.php` — `fixed_assets.*` RBAC gating,
module-off hiding, property scoping, the derived accumulated/NBV columns, the dispose
action (flips status + stops future depreciation) with an authz guard, the "post this
month" list action with a read-only guard, that a scoped user posts **only** their
visible properties (never portfolio-wide), and the `assertAssetInScope` write guard
(rejects an out-of-scope `asset_id`, lets a portfolio user target any property).

`tests/Feature/Services/FixedAssetLedgerTest.php` — acquisition (Dr Furniture / Cr Cash,
Cr Bank when funded from bank, zero-cost skipped, idempotent, not touching AP), the
depreciation charge (Dr Depreciation Expense / Cr Accumulated Depreciation, zero skipped),
void-on-delete (acquisition + charges net to zero), the **GL↔AR/AP tie-out stays balanced**
after posting fixed-asset entries (the GRNI-class regression), and the parent-lifecycle
cascade exercised through the **actual windowed `accounting:sync-ledger` run**: an aged
depreciation charge voids after soft-delete, charges restore on restore, and the entries
re-dimension after a property re-home (the review-caught windowed-discovery regression).
Disposal write-off: loss case (proceeds < NBV), gain case (sold above NBV, proceeds to
bank), fully-depreciated scrap (no gain/loss), the whole footprint nets Furniture +
Accumulated to zero after acquire→depreciate→dispose, the disposal entry voids through the
windowed sweep on soft-delete, and the terminal-disposal guard (no second disposal).

**Related:** 21 General Ledger (Phase 2 posting), 01 Properties (asset scope),
22 Inventory (sibling module, same ledger patterns), 18 RBAC (Phase 1b).

---

## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `FixedAsset` | Deletable (super_admin) | operational: soft-delete IS the retirement path — the sweep voids the asset's entire GL footprint, which a scenario test pins |
| `DepreciationEntry` | **Never deletable** | reverse the depreciation run |
| `FixedAssetDisposal` | **Never deletable** | reverse the disposal |

---

## Sweep fixes — 2026-09-05

*Designed by the patch fleet, adversarially reviewed, then applied and tested one at a time.
Each row's full claim is in [docs/qa/DEEP-SWEEP-2026-09-01.md](../qa/DEEP-SWEEP-2026-09-01.md).*

### SW-190

, under "## 2. Business rules":

**A disposal says where its proceeds landed, and the picker is never conditional (SW-190, 2026-09-04).** `fixed_asset_disposals.proceeds_account` decides the proceeds line's chart account — `FixedAssetDisposalJournalizer` resolves it through `MoneyAccount::for(null, $disposal->proceeds_account, …)` — and the dispose modal offered the picker only when `proceeds > 0`, reading a `proceeds` field that carried no `->live()`. Nothing ever re-rendered the schema after the amount was typed, so **the picker could not appear at all**; and a hidden Filament field is not dehydrated (`HasState::isHiddenAndNotDehydratedWhenHidden()` forgets the state path), so `proceeds_account` never reached `$data` and `DisposeFixedAssetService` took its `?? 'cash'` on every disposal there has ever been. An asset sold and **banked** debited cash on hand. It is now shown unconditionally, required, defaulted to cash — the same reasoning as `BankAccountField`, which is deliberately not hidden on a cash rail: making the amount live would have re-rendered it, but a money rail whose answer rides on a blur that races the submit is a rail that is sometimes not asked, and the journalizer raises no cash line at all when proceeds are zero, so on a scrapping this costs one row on the modal and nothing else. **The class is now gated.** `ADisposalSaysWhereItsProceedsLandedTest` sweeps every `visible()`/`hidden()` condition under `app/Filament` by paren depth and fails on any that reads a sibling field which is not `->live()`; that sweep found exactly one other, the charge-schedule *does not prorate* toggle, which failed the opposite way — it never HID, so it could be ticked on a quarterly charge and `prorate => false` stored on a row the rule was never meant to reach. Neither is provable behaviourally: the Livewire harness always evaluates `visible()` against the state the test just set, so the static sweep is the only thing that can see them.

### SW-192

new subsection under '### Register CSV export (UX, 2026-07-23)':

### The register's totals are the BALANCE SHEET's (SW-192, 2026-09-04)

A disposed asset keeps its cost, its accumulated depreciation and its dates on the register — that is the audit trail, and it is why the status filter is deliberately not defaulted to Active. Its CARRYING AMOUNT, though, is zero: `FixedAssetDisposalJournalizer` credits the gross cost off Furniture & Equipment and debits the accumulated depreciation back on the day of disposal. The footer summed every row anyway, under a label calling the net figure the one that agrees with the balance sheet. Measured on `mall_management_qa`: the GL carried **1,911,833.36** of net fixed assets (2,250,000.00 less 338,166.64) while the screen and the register CSV both read **1,962,666.79** — the one disposed floor scrubber's 50,833.43 of residual book value, counted a second time.

**All THREE money totals narrow, not only the net one.** Cost and accumulated were wrong by 75,000.00 and 24,166.57 for the same reason, and fixing only NBV would break the footer's own arithmetic (cost − accumulated = NBV) — which reads as a fault in the subtraction rather than as the tie-out it is. The three summarizers and `FixedAssetResource::registerCsv()` all read `FixedAsset::ON_BOOKS_STATUSES`, and the label is now *Total on the books* / «الإجمالي القائم بالدفاتر» so the footer says what it counts. `ON_BOOKS_STATUSES` is deliberately **not** a second spelling of `scopeActive()`: that answers *is this still being depreciated* (the monthly run's question) and this answers *is this still on the balance sheet*; they agree only because the column holds two values today. Pinned by `ADisposedAssetIsOffTheBooksInTheRegisterTotalsTest`, whose load-bearing control asserts the disposed asset is STILL LISTED — hiding the rows would satisfy the totals and destroy the register.

### SW-193

new subsection under '## 2. Business rules':

### The write-off worklist could not return a single asset (SW-193, 2026-09-04)

*Fully depreciated* asked `acquisition_cost <= Σ depreciation_entries.amount`, which is neither half of the rule. Accumulated tops out at the DEPRECIABLE BASE — `cost − salvage`, the clamp `DepreciationService::run()` applies to the last charge — so for **any** asset carrying a salvage value the predicate is unsatisfiable, and it also ignored `opening_accumulated_depreciation`, i.e. every legacy asset imported at cut-over with no entries at all. Measured on `mall_management_qa`: all six register rows carry a salvage value, so the worklist was empty **by construction, for ever**, on the one screen whose job is to find retirable assets — and an empty worklist reads as *nothing to write off*, which is why nobody reported it.

The filter now compares the depreciable base against `FixedAsset::accumulatedDepreciationSql()` — **the same expression the register's derived `accumulated` column is built from**, so the two can no longer drift; a select alias is not referenceable from a WHERE, which is why the filter could not simply read `accumulated` and why a second hand-written sum grew there in the first place. The seam also adds `deleted_at is null` (the PHP twin reads the relation and gets the soft-delete scope; the old raw copy did not — measured, 0 trashed entries on either database, so nothing moves today). **No `GREATEST` clamp**: SQLite has no such function, and an asset salvaged above its cost has nothing left to depreciate, so a negative base answering *fully depreciated* is the right answer anyway. `TheWriteOffWorklistFindsWhatItExistsForTest` pins both missing terms plus the SQL↔PHP agreement.


### SW-238

**The reversal sat on the list row, under a comment saying the record acts — and the gate could not
see it.** `RowActionPolicy` derives a write verb from `->action(` appearing in the row action's
chain, and `ReverseDocumentAction::make(...)` is a one-line call site whose closure lives in
`app/Filament/Actions/`. Measured: `FixedAssetsTable` reported **zero write verbs** while carrying
the reversal of a posted GL document, and so did `CustodiesTable`; `InvoicesTable` and
`VendorBillsTable` did the same for `PostMonthAction`, the act that re-posts a live document into a
different accounting period. A factory is now resolved to its own file and classified by ITS source
— **with comments stripped first**, because `LedgerEntryAction`'s docblock says *"no `->action()`"*
and a raw match classified the one read-only affordance in that folder as a destructive verb on four
tables at once. All four verbs moved to their record pages; reachability was measured, not assumed
(each gates on the `{module}.edit` the record page already requires, so no role loses the act).

**And the form said nothing about what an edit does to the books.** `acquisition_date` IS the
acquisition entry's `entry_date`, and `funded_from` decides which account its credit leg hits. Both
stay editable — the model deliberately permits it (a re-cost is a supported operation guarded by
`DepreciationService::assertRecostValid()`, and a form stricter than its model is its own defect) —
but each now states the consequence, because `ChangeImpact`'s DERIVED verdict ends *"the operator
must be told"* and `AnnouncesLedgerRestatement` tells them after the save, at the wrong end of the
decision. Full reasoning in
[CHANGE-IMPACT-PLAN §16](../accounting/CHANGE-IMPACT-PLAN.md#16-the-ui-sweep-2026-09-05--a-status-is-the-outcome-of-an-act-and-an-act-is-on-the-record);
regression test `AnActOnAPostedDocumentIsWhereItCanBeSeenTest`.
