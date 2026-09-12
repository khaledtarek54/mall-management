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
| **Acquisition funding** | `funded_from` — an OUTBOUND payment rail (`payment_methods`; `cash \| bank` the floor) + `bank_account_id` (`RecordsBankAccount`) | The credit side of the acquisition GL entry: the asset's own bank account, else the rail's account, else the role (`MoneyAccount`). Most fixed assets are paid, so this avoids inflating Accounts Payable; the supplier BILL that capitalises a purchase (Dr asset / Cr AP — SAP F-90, Odoo's asset-from-bill) is the next slice. |
| **The supplier is a ROW** (2026-09-12, point 15) | `vendor_id` → `vendors`, quick-create from the picker | The market acquires an asset THROUGH the supplier; a typed name nothing can join on is not a counterparty. The "+" carries `vendors.create` — the register's own right. |
| **The asset CLASS is a catalogue row** (2026-09-12) | `fixed_asset_categories` — the seventh `IsCodeCatalogue` | SAP's asset class drives the number range, the depreciation key and the memo value; Yardi Fixed Assets and Odoo carry the same defaults on the category. It was free text with suggestions; the operator could neither add a class nor give one a life. |
| **First-month proration** | `accounting.depreciation_proration` — `full_month` (default) \| `days` | SAP's period control, Odoo's "prorata": the two answers the market offers. Company-level like SAP's depreciation key — two malls of one operator do not keep the register on two conventions. Posting stays MONTHLY either way; a daily journal entry is what no benchmark system does. |

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
runs, and an out-of-scope row is skipped rather than written. `method`, `funded_from` and
`bank_account_id` are not importable: depreciation is straight-line only, and the rail and the bank
pick the credit side of an entry this importer never posts. **`vendor_code` IS** (2026-09-12): the
supplier's own code resolves an EXISTING vendor and an unknown one is refused in words
(`RowImportFailedException`) — an importer minting a counterparty from a spreadsheet cell is the
free-text door the form deliberately does not offer; a mapped blank cell CLEARS on a re-import.

**Since 2026-09-12 the tag may be BLANK** — a row with no tag is a NEW asset numbered by its class,
so it must name one (`tag` is `required_without:category`, and `useful_life_months` likewise:
nothing else can propose either). `category` is nullable and validated against
`ValueSets::allowed()` — every catalogue row INCLUDING a retired one, because a migrating file may
carry a class the operator has since stopped offering and refusing the row would lose it. A blank
life takes the class's proposal; on a RE-IMPORT (the tag matched) a blank cell **keeps the life the
row already has** (`ignoreBlankState()` — Filament fills a blank as null otherwise and the column is
NOT NULL). A class proposing no life and a blank cell is refused from the importer's own
`beforeCreate()` as a `RowImportFailedException` — the ONE exception whose sentence Filament's
`ImportCsv` writes into the failed-rows file; the model's `DomainException` carries the same words
and would arrive there as a message-less failed row. A second import of an UNTAGGED file duplicates
every row (there is no identity to match on) — tag the file, or import once.

## 1. Domain model

### `fixed_assets` — the register (per property)
| Column | Meaning |
|--------|---------|
| `asset_id` | the property (FK, cascade) |
| `name` · `tag` | label + asset tag (**unique per property**) |
| `category` | the CLASS — a `fixed_asset_categories.code` (see below); nullable for a legacy row, required for a new one |
| `acquisition_date` · `acquisition_cost` · `salvage_value` | cost basis; a blank salvage on create takes the class's memo value (1.00 shipped — SAP's rule) |
| `useful_life_months` | straight-line period; the form also reads and writes it as an ANNUAL RATE % (`annualRatePct()` ↔ `monthsForAnnualRate()` — Law 91 states rates), months are the stored truth |
| `tax_pool` | Law 91 pool, proposed by the class, floored to the statutory default on create — **only while the `tax_depreciation` switch is on** (rule 12); null = unstated |
| `method` | `straight_line` (only method today) |
| `funded_from` | the outbound RAIL the purchase moved on — a `payment_methods` code, `cash \| bank` the floor; the acquisition credit |
| `bank_account_id` | WHICH bank account it left (`RecordsBankAccount` — asked · defaulted from the property · required where the rail carries bank money); null resolves to the rail, then the role |
| `vendor_id` | the supplier, a `vendors` row (`withTrashed()`); a reference on the register until the supplier-bill slice |
| `status` · `disposed_on` | `active` / `disposed` |

### `fixed_asset_categories` — the asset CLASS (portfolio-shared catalogue, 2026-09-12)
| Column | Meaning |
|--------|---------|
| `code` | the value `fixed_assets.category` stores; immutable on edit (it is the address of every asset carrying it) |
| `name_en` · `name_ar` | the label, read through `IsCodeCatalogue::labelFor()` (inactive rows included) |
| `tag_prefix` | the series a blank tag is numbered in — `{PREFIX}-0001` per property; derived from the code and upper-cased on save, unique |
| `default_useful_life_months` · `default_salvage_value` · `default_tax_pool` | what a NEW asset of the class is proposed with — a PREFILL and nothing else, never re-read once the asset exists (the violation-category rule) |
| `is_active` · `sort_order` | retiring a class keeps every asset carrying it saveable (`CatalogueAwareSelect`) |

Screen: `/admin/fixed-asset-categories` (Setup group), `fixed_asset_categories.{view,create,edit}`, granted to `accounting`. Ships eight classes (`FixedAssetCategorySeeder`): furniture FUR 60 · equipment EQP 84 · HVAC 120 · IT 48 (computers pool) · vehicles VEH 60 · fit-out FIT 120 · generator GEN 180 · elevator ELV 240, every memo value 1.00.

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

### `fixed_asset_transfers` — the move between properties (the ACT, 2026-09-12, point 18)
| Column | Meaning |
|--------|---------|
| `fixed_asset_id` | the asset (FK, cascade) |
| `from_asset_id` · `to_asset_id` | the property it left and the one it joined |
| `transferred_on` | effective for the WHOLE month (both legs' entry date) |
| `cost` · `accumulated_depreciation` | what the act moved — frozen on the row, never re-derived |
| `reason` · `created_by_user_id` | required, and stamped into the audit trail as data |

One row per transfer, written by `TransferFixedAssetService` only (no form onto this table);
`#[NeverDeletable]` — the way back is a second transfer. `FixedAsset::transfers()` is the history
the register answers *"where was it in March?"* from (`propertyOn($date)`).

### `fixed_asset_transfer_legs` — the transfer's two GL sources
| Column | Meaning |
|--------|---------|
| `fixed_asset_transfer_id` · `fixed_asset_id` | the act, the asset |
| `asset_id` | the property THIS leg's entry is dimensioned to |
| `direction` | `out` (the property it left) · `in` (the property it joined) — `ValueSets` |
| `transferred_on` · `cost` · `accumulated_depreciation` | the act's figures, copied so each leg posts alone |

Two sources rather than one entry with mixed lines, because every financial statement scopes on
the ENTRY's `asset_id` (only the CAM pool reads line-level) — a single entry would put one mall's
half of the move into the other mall's balance sheet.

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


1. **Monthly charge** = `(acquisition_cost − salvage_value) ÷ useful_life_months`, rounded 2dp — and
   the acquisition month takes the share `accounting.depreciation_proration` says: whole
   (`full_month`, the default, what every install did) or the days held over the month's days
   (`days`). `DepreciationService::chargeFor()` is the ONE sizing rule — `run()` posts it and the
   tax page's book column (`TaxDepreciationService::bookChargeFor()`) projects a year of it, so the
   two cannot disagree by a prorated first month (a second copy of the arithmetic did, until the
   review caught it). A prorated first month lengthens the schedule by one partial month at the
   end through rule 4; the total is still the base. Read at RUN time: it sizes any acquisition month
   not yet posted and never restates one already posted.
2. **Accumulated depreciation is derived** = `SUM(depreciation_entries.amount)`; net book
   value = `cost − accumulated`. Never a cached column.
3. **The run is idempotent + lock-safe**: one entry per (asset, month); each asset row
   is locked and re-checked inside its own transaction (the project scheduled-scan invariant).
4. **Never over-depreciates**: the final charge is clamped to the remaining depreciable
   base, so accumulated tops out at `cost − salvage` (never beyond).
5. **No charge before the acquisition month**, and **none once fully depreciated** or `disposed`.
6. **NOT-NULL money** — blank `acquisition_cost`/`salvage_value` coerce to 0 in the model.
7a. **The number comes from the CLASS** (meeting 2026-09-02, point 11). A blank `tag` on create is
   allocated `{prefix}-0001` in the property's series for the category under the document-number
   lock (`AllocatesDocumentNumber`, `FixedAsset::generateTag()` — LENGTH-first over `withTrashed()`,
   and only a NUMERIC tail counts as a member of the series, because a kept `FUR-2026-0001` would
   otherwise read as 2026). A typed tag — the form's, or a migrating register's — is KEPT, the
   counterparty-code rule. An asset with no class, or a class with no series, still needs one: the
   form requires it there, and on EDIT always (the column is NOT NULL and nothing re-allocates).
7b. **The class PROPOSES, the row DECIDES** (points 13 · 14). On create, a blank salvage takes the
   class's memo value, a blank life the class's, a blank pool the class's and then the statutory
   default — in `FixedAsset::saving`, BEFORE the NOT-NULL coercion of rule 6, so every door (form,
   importer, seeder) gets the same proposal. A figure stated — including an explicit zero salvage —
   is never overwritten, and nothing re-reads the class once the asset exists. A create with no life
   from either is refused in words (`admin.fixed_assets.errors.useful_life_required`).
7c. **The credit leg names the bank, and the rail is a row** (point 15, 2026-09-12). `funded_from` is
   `ValueSets`-widened to the outbound rails (`PaymentMethod::outboundCodes()`, floor `cash|bank` so
   every row already written stays valid), and the asset carries `RecordsBankAccount`: on create a
   bank rail defaults `bank_account_id` from the property (Yardi's shape — the operator confirms),
   a cash rail names none, another mall's account is refused, and the form requires one exactly
   where the expense form does. Two things the shared field learned here: **the requirement stands
   down on a row that never named one** (every pre-register asset is `bank` / null, and a name-only
   save was refused — then answering it was a DERIVED re-post a closed period refused too) and is
   re-asked only where the rail MOVES or the row already names a bank; and **a bank the rail does not
   carry is not recorded** (the field fills the property's account from mount beside a rail
   defaulting to `cash`, and `MoneyAccount` lets a named account win — a purchase left on both
   defaults credited the BANK). On a DISPOSED asset both are frozen with the cost. `vendor_id` is
   NEUTRAL to the books — a reference until the supplier-bill slice — and a supplier or a rail an
   asset names cannot be deleted (`Vendor::fixedAssets()`, `PaymentMethod::fixedAssets()`).
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
   (or `asset_id`, on the one uncommitted re-home still allowed — rule 11) re-flows to the child
   sources' GL via the parent-lifecycle cascade.
11. **Moving an asset to another property is a dated ACT with a reason, never an edit of
   `asset_id`** (meeting 2026-09-02, point 18 — *"na2l asl … mn mkan le mkan"*; shipped
   2026-09-12). Until then the only way to move a chiller from one mall to another was to edit the
   property on the form, and that re-homed the WHOLE history: the acquisition entry and every
   posted depreciation charge were voided and re-posted into the new mall's dimension — months
   that may be closed restated, and refused outright once one was. **The standard**: SAP's
   intra-company transfer (ABUMN) and Yardi Fixed Assets both post cost and accumulated
   depreciation OUT of the old books and IN to the new on the transfer date, leave history where
   it was, and let future depreciation follow the asset. So:
   - `TransferFixedAssetService::transfer($asset, [to_asset_id, transferred_on, reason])` — under
     a lock on the asset, in one transaction: the `FixedAssetTransfer` row, its two legs, an
     activity row (`fixed_asset` · `transferred` · `fixed_asset.transferred`, the reason as DATA —
     the `ReversalReason` rule), then the asset's `asset_id`.
   - **The OUT leg** (in the property it left): Cr Furniture & Equipment (cost) · Dr Accumulated
     Depreciation (accumulated to date) · Dr **Inter-property Transfers Clearing** (the net book
     value — posting role `inter_property_clearing`, shipped as `11801001`). **The IN leg** is the
     mirror in the receiving property. Per property the clearing account states what one mall
     handed another; portfolio-wide it nets to zero (the trial balance's proof).
   - **A transfer is effective for the whole of its month** (SAP's period control): the transfer
     month's charge belongs to the receiving property, and the OUT leg's accumulated figure is
     exactly the charges posted for the months before it (+ `opening_accumulated_depreciation`,
     which left with the asset).
   - **`FixedAsset::propertyOn($date)` is the ONE reading every journalizer takes** — the
     acquisition on `acquisition_date`, each charge on its `period_month`, the disposal on
     `disposed_on` — answered from the transfer rows: the earliest transfer dated in a LATER month
     says where the asset was. With no transfer the answer is `asset_id`, so nothing an install
     already holds reads any differently. That is what keeps history where it was: after a
     transfer the sweep re-reads the old entries and finds them unchanged.
   - **`asset_id` is REFUSED once the asset has begun depreciating or been disposed**
     (`ChangeImpact::POLICY`, enforced by `RefusesRestatementOfCommittedMoney`;
     `FixedAsset::isCommittedMoney()`). Before that — a wrong property at registration — the
     free edit is still a correction: nothing posted rests on the old dimension and the
     acquisition re-derives. The act's own write of `asset_id` passes by its SHAPE
     (`restatementPermittedBecause()`: only `asset_id` dirty, and the LATEST transfer row says
     exactly this move — *latest*, because after a round trip a row "from A to B" exists for
     ever and `exists()` would reopen the free edit).
   - **The purchase bank stays in the property the asset was bought in**:
     `RecordsBankAccount`'s guard asks `FixedAsset::bankAccountAssetOf()`, which reads
     `propertyOn(acquisition_date)` — read off `asset_id` it would refuse the transfer's own save
     for naming another mall's bank.
   - **Refused, each in the reader's words** (`admin.fixed_assets.errors.transfer_*`): no
     reason · a disposed asset · the property it is already in · the "All Properties"
     pseudo-asset or a property the actor does not hold (`AssignedAssets::idsForCurrentUser()`,
     the same reach as the user form's grant picker — re-checked in the SERVICE, the picker is
     not the guard) · a future date or a closed period (`PostingDate::assertNotFuture`) · a date
     in or before the acquisition month (that is a correction, not a transfer) · a date in or
     before a month already depreciated HERE (that month's charge belongs to the receiver — date
     it from the next month) · a date before an earlier transfer (history is chronological) · a
     tag the receiving property already uses (tags are unique per property; re-tag the other
     asset first).
   - **Doors**: the *Transfer* act on the asset's own page (`transfer` in `FixedAssetActions::all()` — the
     destination picker is `EntitySelect ->acrossProperties()`, the one deliberate exception to
     "no screen offers a property other than the selected one", narrowed to what the actor holds
     and hidden when that set is empty; after the act the page follows the asset to its new
     property, because this page is scoped to the one it left) and the read-only **Transfers**
     tab (`FixedAssetTransfersRelationManager`) that answers *"where did that chiller go"*. The
     importer cannot re-home (it resolves an existing row by `(asset_code, tag)`, so another
     property is another asset), and the form's property field is pinned.
   - **The review found five more rules the act needs, all built the same day**: (a) **what the
     legs froze is LOCKED** — `FixedAsset::TRANSFER_FROZEN` (`acquisition_cost`,
     `acquisition_date`, `is_opening_balance`, `opening_accumulated_depreciation`) is refused on
     the model once a transfer row exists (`historyLockedByTransfer()`; the form disables the two
     on-form columns and says why, the importer words it), because a re-cost after a transfer
     left the old mall's Furniture carrying the difference for an asset it no longer holds while
     the portfolio trial balance still footed — the correction is transfer back, edit, transfer
     again; (b) **every month before the transfer must be POSTED** (`transfer_month_uncharged`,
     `DepreciationService::firstUnchargedMonthBefore()` — `run()`'s own gates walked month by
     month; a cut-over asset starts at its first posted month, its earlier months being the
     accountant's opening figure), because the OUT leg carries what is posted and a catch-up after
     the move would dimension that month to the old property with no leg to carry it across; (c)
     **a disposal cannot be dated before the latest transfer** (`disposed_before_transfer`,
     `DisposeFixedAssetService`) — it would write the asset off in the property it LEFT; (d)
     **a property's scoped depreciation run posts the months it HELD the asset**
     (`DepreciationService::run()` asks `propertyOn($month)`, not today's `asset_id`), so the
     receiving mall's *Post this month* cannot write into the sending mall's ledger — belt-and-
     braces beside (b); and (e) **the bank picker narrows to the property the DOCUMENT answers for**
     (`BankAccountField` asks `RecordsBankAccount::bankAccountPropertyOf()`, `acrossProperties()`
     with its own one-property guard): narrowed to the switcher, a transferred asset's Edit page
     could label neither the buying mall's bank it names nor accept the receiving mall's, and every
     save was refused on a field nobody touched.
   - **Stated deviations and limits**: none from SAP/Yardi on the shape. Stricter than both in one
     place — a transfer may not be dated into a month already depreciated in the old property,
     where SAP would re-dimension that month's charge; here re-dimensioning a POSTED charge is
     exactly the restatement the act exists to avoid, so the month is refused with the way out.
     Since the scheduler posts the CURRENT month on the 28th, a move recorded from the 28th onward
     is dated from the 1st of the next month. The clearing account nets to zero portfolio-wide
     only while every property maps `inter_property_clearing` to the same chart account (the
     posting map is per property — the shipped mapping is portfolio-wide). And the asset belongs
     to ONE property: after a transfer the sending mall's register no longer lists it and its Edit
     page there 404s — the sending mall reads the OUT journal entry (named through the leg's
     `label()`) and the activity trail; the Transfers tab lives with the asset. **No new
     setting** (skill §3b): neither system configures the transfer's posting shape, and the
     clearing account is a posting-map row like every other role.
12. **The income-tax depreciation schedule is a MODULE SWITCH, and it is OFF on the client's
   install until further work** (meeting 2026-09-02 point 12 — *"kelmt ehlak dareebi, 5leha
   ehlak"* — decided 2026-09-13 by the owner: *"stop it, stop posting to the ledger, mark it in
   the docs as stopped, no reference on the dashboard or in a money action, re-enableable later"*).
   Two facts first, because the ask conflated them: `/admin/tax-depreciation` (Law 91/2005 art.
   25 — `App\Support\TaxDepreciation` + `TaxDepreciationService`) is a REPORT for the corporate
   return and **has never posted a journal entry** (`TheTaxDepreciationScheduleIsASwitchTest`
   pins that its three files name no ledger, no entry and no write); what posts monthly is the
   BOOK depreciation — `accounting:post-depreciation`, the *Post depreciation* button and the
   entries tab — which is the `fixed_assets` module and is **untouched**: assets go on
   depreciating in the books. Asked which was meant, the owner chose *only the tax page*.
   - **The standard**: every fixed-asset system in the benchmark set keeps a TAX book beside the
     accounting one and makes keeping it a per-company configuration (Yardi Fixed Assets' books,
     SAP's depreciation areas, Odoo's fiscal vs. accounting). So it is a switch that **ships ON**
     — `Modules::KEYS['tax_depreciation']`, `ModulesSettings::$tax_depreciation = true`, Settings →
     Modules → Inventory & assets — and the client's OFF is a configuration act on their box,
     never the code default (skill §3b). A switch and not a code freeze (`Modules::FROZEN` is for
     UNFINISHED work — the ETA precedent): the schedule is finished and correct, what the client is
     deciding is whether to look at it. Its own key, deliberately NOT `FEATURE_OF` `fixed_assets`
     — a follower answers whatever its owner answers, and this must be off while the register is on.
   - **Off hides every door and posts nothing new**: `TaxDepreciation::canAccess()` reads the
     switch, and the sidebar (`Navigation::itemsFor()`), the report hub (`ReportCatalogue::
     visibleTo()`), the delivery options and every scheduled delivery (`DeliverSavedReportService`
     re-asks `canAccess()`) and the assistant's report tier all ask that one method — 403 on the
     route, absent everywhere else. The `tax_pool` Select on the asset form, the class form's
     `default_tax_pool` and the classes table's column are `->visible()` on the same switch; the
     asset form's class helper stops naming a pool that is no longer below it. Nothing on a
     dashboard widget, the month-end close or any money action ever referenced the schedule
     (measured by grep before the change), and the page carries no act of its own.
   - **The data while off — UNSTATED, never invented (found by review).** A hidden field is not
     dehydrated, so an existing asset keeps its pool and a new one takes its CLASS's proposal
     through `FixedAsset::saving`. But the floor to the statutory default (`general`, 25% DB) runs
     **only while the switch is on**: with it off nobody can confirm a pool, so an asset whose
     class proposes none — every class registered while off, since its field is hidden too — is
     left NULL. The schedule already reads null as the law's default (`TaxDepreciationScheduleTest`:
     *general, never dropped*), and the form shows the blank BACK the day the switch returns —
     the review step below — where a `general` the system invented would have been a figure on a
     tax return nobody looked at twice.
   - **A saved view of the schedule is not stranded (found by review).** The hub filtered out
     every saved view of a report the reader could not open — and the hub is the ONLY surface
     that manages a `SavedReport`, so a scheduled delivery of the tax schedule would have gone on
     being claimed by `reports:deliver`, refused by the service (correctly) and counted as a
     FAILURE on every due day for as long as the switch was off, with nothing anywhere to retire
     it. An operator's OWN view of an unopenable report is listed now, unlinked, saying why
     (`admin.report_hub.unavailable_view`), with delete and the schedule modal still on it; a
     colleague's stays hidden (not theirs to touch). General: it covers a report whose module is
     off AND a report the reader lost the right to.
   - **To switch it back on**: Settings → Modules → *Tax depreciation* (super_admin). Then
     review the pools registered while it was off — `fixed_asset_categories` with a null
     `default_tax_pool` and `fixed_assets` with a null `tax_pool` (blank on the form, `general` to
     the schedule) — and confirm the rates the return is filed at (STATUS A6.1). Nothing else is
     needed: no migration, no deploy, no data was deleted.
   - **Left as it is, and why**: the `tax_pool` column stays NOT-NULL-by-convention only through
     the floor, so a null is now a legitimate value meaning *unstated* (the column was nullable
     from its migration and the service always read `?: default()`); the four permission /
     screen-guide / empty-state strings that mention "tax pool" describe what a RIGHT allows or
     what the classes screen is for and stay; the handbook's screens dataset lists every screen
     regardless of any switch (`cam` too). Client's install: **`modules.tax_depreciation = false`
     SET on staging 2026-09-13** (STATUS §9).

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
| **Acquisition** | `FixedAsset` | Dr Furniture & Equipment `12101001` / Cr **the asset's bank account's own leaf**, else the rail's account, else **Cash `11101001` \| Bank `11102001`** by role (`MoneyAccount::for(bank_account_id, funded_from, …)`) |
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
  sources (`ledgerChildRelations()` — the charges, the disposal, and since point 18 the transfer
  legs and their parent rows) — soft-delete soft-deletes them (their entries void), restore
  restores only the exact rows it trashed (matched on `deleted_at`), and a property change
  touches them so the sweep re-reads them — which re-dimensions them only on the free re-home of
  an UNCOMMITTED asset; after a transfer `propertyOn()` answers each posted month its old
  property and the re-read is a no-op — bumping their `updated_at` so the daily sweep re-visits
  them. Without this they would strand (phantom GL / wrong property) until a
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
| **2 — GL posting** | acquisition → Dr Furniture & Equipment (12101001) / Cr Cash\|Bank (per `funded_from`; since 2026-09-12 the asset's own bank account first — see phase 4); depreciation entry → Dr Depreciation Expense (51107001) / Cr Accumulated Depreciation (12201001). Journalizers + mappings + sweep + a tie-out-safe check. | ✅ shipped |
| **3 — The class, the number, the rate, the first month** (meeting 2026-09-02, points 11 · 13 · 14) | `fixed_asset_categories` catalogue + screen; tag allocated from the class per property; class defaults prefilled on the form and by the model; useful life read as an annual rate; `accounting.depreciation_proration` (`full_month` \| `days`); importer widened. **Deploy note**: the migration rows every value the register already holds (grouped case-insensitively, the register rewritten to the row's spelling) and deliberately SKIPS the shipped codes so `atriom:install --force`'s seeder creates those with their life, pool and prefix — a row it had created for `HVAC` on a box already holding HVAC assets shipped the class lifeless, on exactly the installs that have assets. | ✅ shipped |
| **4 — The rail, the bank and the supplier** (meeting 2026-09-02, point 15, slice 1) | `funded_from` reads the outbound rail catalogue; the asset is the ninth document on `RecordsBankAccount` (the credit leg lands in the named bank's own chart account — a bank-funded asset had credited the generic `bank` ROLE, the unattributed state SW-228 closed for receipts); `vendor_id` with a quick-create gated on `vendors.create`; the importer takes `vendor_code`. **Slice 2, not built**: `funded_from = payable` raising a draft supplier bill (Dr asset / Cr AP). | ✅ shipped |
| **5 — Transfer between properties** (meeting 2026-09-02, point 18) | `fixed_asset_transfers` + `fixed_asset_transfer_legs` (two GL sources, `inter_property_clearing` role + chart leaf `11801001`), `TransferFixedAssetService`, `FixedAsset::propertyOn()` read by all three journalizers, `asset_id` REFUSED once depreciating (the act's own write passes by shape), the *Transfer* act on the record page + the *Transfers* tab. **Deploy note**: `atriom:install --force` seeds the new chart leaf and posting-map row; nothing already posted moves. | ✅ shipped |
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

`tests/Feature/Regression/AFixedAssetIsNumberedAndDefaultedByItsClassTest.php` — points 11 · 13 · 14
end to end: numbering per property and past padding, kept tags (including one in another numeric
shape), the class's proposals and the stated figure winning, the rate↔months pair, the first month
by days summing to the base, the setting read at run time and clamped, the form (class first, the
prefill, a life under a year, a cleared tag refused), the catalogue screen, the importer (kept /
allocated / re-import keeping the life / refusals in words), the migration's backfill leaving the
shipped codes to the seeder and rewriting the register, and the tax page's book column agreeing
with the ledger. Seventeen cases; thirty mutations, each killing its own tooth.

`tests/Feature/Regression/AFixedAssetNamesItsRailItsBankAndItsSupplierTest.php` — point 15: the
credit leg on the named bank's own leaf (the role only when none is named), the outbound catalogue
accepted and an inbound-only rail refused, another mall's account refused on create and on a
re-home, the disposed freeze on the credit leg, a supplier and a rail undeletable while named, the
form (rails offered, bank asked/defaulted/required, a legacy row still editable, the re-rail re-asked,
a named bank never cleared, a cash purchase left on the form's defaults booking to cash, the "+"
refused to `accounting` and creating a real supplier for `manager`), the importer (supplier by code,
blank clears, unknown refused in words, no rail column). Ten cases; twenty-one mutations.

`tests/Feature/Regression/AFixedAssetMovesByATransferActNotAnEditTest.php` — point 18: one
balanced leg per property carrying cost and accumulated depreciation (Cr Furniture / Dr Accumulated
/ Dr Clearing out, the mirror in; the trial balance per property and netting to zero portfolio-
wide; a second sweep moves nothing), history left where it was (the SAME acquisition and charge
entries, still posted, still in the old property) with the transfer month's charge landing in the
new one and `propertyOn()` month by month, the opening write-off travelling with the asset, the
reason as data on the trail and both legs worded EN/AR naming the counterparty, the free edit
refused on a depreciating and on a disposed asset and still allowed on an uncommitted one, the
carve-out reading the LATEST transfer (a round trip does not reopen the edit) and refusing money
riding along, the purchase bank staying in the buying property so the bank guard does not block
the move, every refusal beside its control (reason · disposed · same property · pseudo-property ·
future · closed period · acquisition month · charged month · out of order · not held · tag clash),
the act on the Edit page redirecting to the asset under its new tenant and hidden for a manager
with nowhere to move it, and the Transfers tab (registered on the resource); from the review —
a bank-funded asset's page still saving a rename under the receiving mall, the four frozen figures
refused (model, form disabled, importer worded) with housekeeping and the life still open, the
uncharged-month refusal with its two non-gaps (fully depreciated, cut-over), a disposal dated
before the transfer refused, and a property's scoped run posting the months it held the asset.
Fifteen cases; forty-three mutations, each killing its own tooth.

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
depreciation charge voids after soft-delete, charges restore on restore, the free re-home of a
DEPRECIATING asset is refused with its entries left in the old property (point 18 — until then
this case pinned the re-dimensioning the transfer act replaced), and an UNCOMMITTED asset's
acquisition still follows a correction of its property.
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
acquisition entry's `entry_date`, and `funded_from` (with `bank_account_id` beside it since
2026-09-12) decides which account its credit leg hits. Both stay editable — the model deliberately permits it (a re-cost is a supported operation guarded by
`DepreciationService::assertRecostValid()`, and a form stricter than its model is its own defect) —
but each now states the consequence, because `ChangeImpact`'s DERIVED verdict ends *"the operator
must be told"* and `AnnouncesLedgerRestatement` tells them after the save, at the wrong end of the
decision. Full reasoning in
[CHANGE-IMPACT-PLAN §16](../accounting/CHANGE-IMPACT-PLAN.md#16-the-ui-sweep-2026-09-05--a-status-is-the-outcome-of-an-act-and-an-act-is-on-the-record);
regression test `AnActOnAPostedDocumentIsWhereItCanBeSeenTest`.
