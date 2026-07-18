# Plan — Owner Statements + Disbursements (the operator-for-owner deliverable)

> **Status:** design complete; operator decisions taken 2026-07-18 → **management fee DEFERRED** to a
> future slice (v1 statement = income − expenses = net, no fee line), **taxes to become settings-driven**
> (see §0). Building.
>
> ## 0. Scope decision (2026-07-18) — fee deferred, taxes settings-driven
> The operator's call: *"this system shouldn't care about how Eltizam charges Jawad for now — make it a
> future plan"* and *"taxes settings-based / dropdown selection, I'll confirm with the accountant."* So **v1
> drops the management fee entirely**: the statement is **income − expenses = net distributable**, split by
> `ownership_percentage`. This removes `management_fee_terms`, the fee/VAT GL lines, `due_to_operator`,
> `management_fee_expense`, `vat_recoverable`, and the VAT-on-fee question from v1. The GL accrual at finalise
> simplifies to **Dr `owner_distributions` / Cr `due_to_owner`** (two new keys only). Everything else in this
> plan stands. **Future slices (roadmap):** (a) the management-fee engine (`management_fee_terms` + fee/VAT
> lines + a statement fee row — the deferred §2.2/§9.1); (b) a **settings-driven Egyptian tax catalog**
> (configurable tax rates as dropdown selections, replacing hardcoded 14%/5% — a cross-cutting feature the
> operator asked for, tracked separately in ROADMAP).
> **Why #1:** the competitive gap analysis ([competitors/README.md](../gap-analysis/competitors/README.md))
> named this the single highest-value gap — Eltizam runs malls *for* Jawad, and the periodic owner
> statement (income − expenses − management fee = net) plus the payout cycle *is* that relationship's
> deliverable, and Atriom ships none of it. Reviewed with the operator 2026-07-18; owner statements
> chosen as the first strengthening build.
>
> **How this design was produced:** three independent designs (accounting-first / pragmatic-report /
> owner-relationship) were judged head-to-head by three perspective-diverse judges (accounting
> soundness · Atriom-pattern fit · owner-deliverable completeness) and synthesised. Scores: A 87,
> B 88 (fit), C 90 (deliverable). The recommendation below takes C's three-tier spine, A's accrual-GL
> tie-out, and B's reuse discipline.

---

## 1. Recommended architecture

A **three-tier accrual-GL spine** on `/admin` (the `/owner` panel is retired-by-default; owners are
`owner`-role RBAC users inside `/admin`):

- **`OwnerStatementRun`** — property-period accounting truth and **GL source #1**. Finalising posts one
  balanced compound entry: management fee → *Due to Operator* (intercompany), distribution → *Due to Owner*.
- **`OwnerStatement`** — per-owner child, the confidential deliverable each owner receives (own status,
  snapshot PDF, double-scoped visibility). **Not** a GL source.
- **`Disbursement`** — payout grandchild and **GL source #2**, gated by the existing `ApprovalPolicy`
  ladder. Paying an owner clears Dr *Due to Owner* / Cr Bank.

The P&L is reused verbatim from `LedgerReportService::incomeStatement()` (accrual, GL-derived,
closing-entry-free), so for a single full-tenure owner the frozen `net_distributable` **is** the live
income-statement net (the signature tie-out); for co-owners, `net_distributable := Σ owner_share`
penny-reconciled, so *Due to Owner* nets to zero when every owner is paid. Posting rides the house
pattern — the `saved` afterCommit hook + the `accounting:sync-ledger` sweep — never a synchronous post
inside the finalise transaction. Exactly **two** new registry sources; the `asset_owner` pivot cast at
source with effective-dated ownership segments; **F-80 fixed in the same epic** so the per-property
balance sheet ties out.

## 2. Data model

Money columns `decimal(14,2)`; every money/percent column defaults `0` in `$attributes` **and** has a
mutator coercing `''`/`null` → default (the `meter_readings.cost` NOT-NULL class). Enums = string columns
with a `booted()` guard. All new models: `SoftDeletes`, `LogsActivity`, `$bulkDeletable` unset, race-safe
`generateReference()`.

- **`asset_owner` pivot (root fix):** add `AssetOwner extends Pivot` (cast `started_at`/`ended_at` → date,
  `ownership_percentage` → decimal:2; `->using()` on both relations). Relax `unique(user_id, asset_id)` →
  `unique(user_id, asset_id, started_at)` so an owner holds **non-overlapping tenure segments** (a mid-period
  60%→50% change = close current segment + open a new one). Add a `currentOwnership()` scope; fix
  `PortfolioStats` to use it (companion slice — stops the 50% co-owner seeing 100%).
- **`management_fee_terms`:** `asset_id` (nullable = global default; row overrides), `basis`
  (`gross_billed`·`collected`·`noi`·`fixed_monthly`, default `noi`), `rate_percent`, `flat_amount`,
  `vat_on_fee` (default true), `started_on`/`ended_on`, `is_active`. No term → 0% (fail-safe). Resolves
  per-asset-over-global like `AccountResolver`. Registered **isolated** in `PropertyIsolation`.
- **`owner_statement_runs`** (GL source #1): `reference` (`OSR-YYYY-NNNN`), `asset_id`,
  `accounting_period_id`, `period_start`/`period_end`, `posting_date` (default `period_end`), `basis`
  (`accrual`·`cash`), frozen fee copies, `total_revenue`/`total_expense` (from `incomeStatement()`, expense
  **excludes `management_fee_expense`** so a re-run never double-counts), `collected_revenue`/
  `cash_expense_paid`, `management_fee_amount`/`fee_vat_amount`, `net_operating_income`,
  `net_distributable` (`Σ children.owner_share`), `version`/`supersedes_id`, `status`
  (`draft`·`finalised`·`superseded`), `finalised_at`/`finalised_by_user_id`.
  `unique(asset_id, accounting_period_id, version)`.
- **`owner_statements`** (per-owner deliverable, not a GL source): `reference` (`OS-…`),
  `owner_statement_run_id`, `asset_id` (denormalized ⇒ uniform auto-scope), `user_id`,
  `ownership_percentage` (snapshot), `tenure_from`/`tenure_to`, `weight`, `owner_share`,
  `share_revenue`/`share_expense`/`share_management_fee`, `paid_to_date` (recomputed, never hand-set),
  `status` (`draft`·`finalised`·`sent`·`superseded`), `sent_at`.
- **`disbursements`** (GL source #2): `reference` (`DISB-…`), `owner_statement_id`, `asset_id`
  (denormalized — journalizer reads own row, never walks a `withTrashed` parent), `user_id`, `amount`
  (`≤ share − paid_to_date`), `method` (`bank_transfer`·`cheque`·`cash`), `required_permission` (frozen at
  schedule from `ApprovalPolicy::permissionFor`), `status` (`scheduled`·`approved`·`paid`·`cancelled`),
  `approved_by_user_id`/`approved_at`, `paid_on` (drives `entry_date`), `external_reference`. No
  `unique(asset,owner,period)` — partials allowed; cap enforced under lock. `paid`/`cancelled`
  terminal-immutable.

## 3. Services + lock story + weighting math

Single-action services under `app/Services/OwnerAccounting/`; pages/resources thin.

- **`GenerateOwnerStatementRunService(Asset, AccountingPeriod, basis)`** → draft. `DB::transaction` +
  `lockForUpdate` the `(asset, period)` run; re-check no *finalised* run exists inside the lock.
  `assertAssetInScope`. `incomeStatement([asset->id], period_start, period_end)` (same bounds as
  `posting_date`). Resolve + freeze the fee term. Build child `owner_statements` via the weighting math;
  penny-reconcile `net_distributable := Σ owner_share`. Idempotent upsert on `(asset, period, version)`;
  **no GL** (a draft is disposable).
- **`FinaliseOwnerStatementRunService(run, actor, ?postingDate)`** (draft → finalised). One transaction:
  `lockForUpdate` + re-check `status==='draft'`; `posting_date` through **`App\Support\PostingDate`** in
  the service (closed period refused, missing allowed); recompute-and-freeze under the lock; **refuse if
  `Σ weight > 1.0`**, flag `Σ pct ≠ 100`; flip children to `finalised`. **No synchronous
  `LedgerPoster::sync()`** — the `saved` afterCommit hook + daily sweep post it.
- **`ReviseOwnerStatementRunService(run)`** (finalised → superseded, `version+1`): old run's journalizer
  returns `null` ⇒ sweep **voids** its entry (reuse existing void self-heal); clone + regenerate + finalise.
- **`SendOwnerStatementService`**: render + store immutable snapshot PDF on the **private disk**
  (`useDisk('local')`), stamp `sent_at`, notify the owner.
- **`DisbursementService`** (multi-verb, modeled on `PurchaseRequestService`): `schedule` (freeze
  `required_permission`), `approve` (ladder + frozen permission), `markPaid` (cap re-checked under lock,
  `paid_on` through `PostingDate`, recompute `paid_to_date`, hook posts Dr *Due to Owner* / Cr Bank),
  `cancel` (entry self-heals to void).
- **Scheduled `owner-statements:generate-monthly`** (add to `routes/console.php`): drafts only, idempotent
  + lock-safe. A human always finalises.

**Weighting math** (period `[P0,P1]`, `period_days = days(P0,P1)+1`; iterate every `asset_owner` segment
overlapping the period):
```
S = started_at ?? P0 ;  E = ended_at ?? P1
overlap_days = max(0, days(max(S,P0), min(E,P1)) + 1)      // 0 ⇒ no statement for this owner
tenure_frac  = overlap_days / period_days
weight       = (ownership_percentage / 100) × tenure_frac
owner_share  = round(net × weight, 2)
```
Penny reconciliation: allocate the rounding residual to the largest share (largest-remainder), then set
`net_distributable := Σ owner_share`. `Σ weight < 1` ⇒ the undistributed remainder legitimately stays in
retained earnings (operator/vacant share) — disclose as a statement footnote. `Σ weight > 1` ⇒ refuse
finalise.

## 4. GL design

**New `AccountMapping` keys** (resolve via `AccountResolver::id($key, $assetId)`) — **v1 (fee deferred) needs
only two:**

| Key | Account | COA action |
|---|---|---|
| `owner_distributions` | **`34101001`** "Owner Distributions / توزيعات الملاك" (its own equity branch `34`, contra-draw) | ✅ shipped (slice 1) |
| `due_to_owner` | `21802001` "Distributions Payable to Owners / توزيعات مستحقة للملاك" (liability under `218`) | ✅ shipped (slice 1) |

*(Deferred with the fee: `management_fee_expense`, `due_to_operator` = `21801001`, `vat_recoverable` =
`11401001` — add when the fee slice ships.)*

> **Resolved chart facts (slice 1):** `normal_balance` is **derived from `type`** in `LedgerAccount::booted()`
> (`equity → credit`) and the conformance gate enforces the lockstep — so a contra-equity account is typed
> `equity`/credit-normal and simply carries a **debit balance** that reduces equity via the balance-sheet
> `credit − debit` math (dividends-account pattern). The `saving()` hook also enforces leading-digit → type,
> so the codes had to be `3…`/`2…`. Owner Distributions went to its **own equity branch `34`** (not `322`)
> to avoid nesting under "Retained Earnings" (32) and mismatching that parent's name. `34*`/`21802*` were
> verified free. Pinned by `tests/Feature/Regression/OwnerDistributionAccountsTest.php`.

**Journalizer #1 — `OwnerStatementRunJournalizer`** (`payload()` = `null` unless `finalised`;
`entry_date = posting_date`, reads own-row `net_distributable` only) — **v1 two-line accrual:**
```
Dr  owner_distributions   D  (= net_distributable)   (asset)   // contra-equity draw
Cr  due_to_owner          D                            (asset)   // liability: property owes the owner
```
The distribution is a contra-equity draw (does **not** touch P&L). With no fee in v1, `net_distributable`
for a single 100% full-tenure owner **is exactly** `incomeStatement()->net_profit` — the signature tie-out.
*(When the fee ships: prepend `Dr management_fee_expense F` / `Dr vat_recoverable V` / `Cr due_to_operator
F+V`, and `net_distributable` becomes `net_profit − F`.)*

**Journalizer #2 — `DisbursementJournalizer`** (`null` unless `paid`; `entry_date = paid_on`, own-row
`asset_id` — no child-source windowed-sweep trap):
```
Dr  due_to_owner   amount   (asset)
Cr  bank | cash     amount   (asset)
```
Σ paid = the run's `D` ⇒ *Due to Owner* nets to zero when every owner is fully paid.

**The ONE registry edit** — `LedgerPoster::JOURNALIZERS` (two lines) + `LedgerRealtimeSync::SOURCE_DATE_COLUMNS`
(two lines, keys equal `sources()` exactly or `GlRegistryConformanceTest` fails). Both models `SoftDeletes`
⇒ `LedgerRealtimeSync::register()` auto-wires `saved`/`deleted`/`restored`.

**Tie-out test** (`tests/Feature/Scenarios/OwnerStatementGlScenarioTest.php`): drives the **real service +
`artisan('accounting:sync-ledger')`** (never `LedgerPoster::post()` directly) — asserts the five-line
compound entry, `net_distributable === incomeStatement()->net_profit` (single 100% owner), trial balance
balanced, AR/AP untouched; then `schedule→approve→markPaid → sweep` nets *Due to Owner* to zero and is
idempotent on a second sweep; `Revise → sweep` voids the old entry; 60/40 co-owner penny-reconciliation.

**F-80 — fix in the same epic, sequenced BEFORE the posting slice.** This module is the first to post
per-property equity (*Owner Distributions*) and a per-property liability (*Due to Owner*); the per-property
balance sheet only ties out once `YearEndCloseService::postClosing()` dimensions the retained-earnings roll
by `asset_id` (today it posts `NULL`). Its own per-property tie-out test.

## 5. Filament surface (`/admin`)

- **`MyOwnerStatements` Page** (owner-facing, read-only) — mirrors `IncomeStatement`, but **rebinds both
  `canAccess()` and `canViewReports()`** to `owner_statements.view_own` (inheriting `general_ledger.view`
  would leak). Query **triple-scoped**: isolation ∧ `scopedAssetIds()` ∩ `whereHas('owners', Auth::id())`
  ∧ `where('user_id', Auth::id())` ∧ `status ∈ {finalised, sent}`. PDF action streams the owner's own
  statement. Carry-forward block: `accrued_to_date − paid_to_date = balance_due`.
- **`OwnerStatementRunResource`** (operator worklist, Accounting nav) — actions Generate / Finalise
  (confirm + posting-date picker) / Revise; relation manager on child `OwnerStatement`s with Send + PDF.
  `getEloquentQuery()` uses `BypassesFilamentTenantAutoScope` + manual scope (AnnouncementResource pattern).
- **`DisbursementResource`** (the payout board) — Schedule / Approve / Mark paid / Cancel via
  `DisbursementService`.
- **Gating** — every write action gates in **both** `visible()` and `action()` (`abort_unless`/`authorize`),
  predicate named once. Tests dispatch via `mountAction`+`callMountedAction`, never `->callAction()`.
  Permissions: `owner_statements.{view,generate,finalise,send,view_own}`,
  `disbursements.{view,schedule,approve,pay,cancel}`. Owner role gets **only** `owner_statements.view_own`
  (+ read-only own disbursements) — never `general_ledger.view`. Delete/void/reopen = super_admin;
  bulk-delete off.
- **Isolation** — register all four new models property-owned in `PropertyIsolation`; `assertAssetInScope`
  on every editable/derived `asset_id`.
- **Media** — snapshot PDF collection declares `useDisk('local')` (owner financials = the fail-open class).

## 6. Test plan (Pest `--parallel`)

- **Scenario:** full lifecycle (generate→finalise→send→schedule→approve→pay, 100% owner);
  `OwnerSplitScenarioTest` (60/40 penny reconciliation, owner A cannot query owner B's statement);
  `OwnerTenureScenarioTest` (mid-period sale, day-weighted); `OwnerPctChangeScenarioTest` (60→50 via
  segments); the GL tie-out (§4).
- **Regression:** weighting (50% co-owner sees 50%, not 100% — the `PortfolioStats` bug); snapshot-freeze
  (editing invoices post-finalise doesn't move frozen figures; no double-count); fee-never-double-counted
  on re-run; re-finalise idempotency; closed-period finalise/pay refused; approval tier frozen-at-schedule;
  dual-gate refusal via `mountAction`; disbursement cap; NOT-NULL coercion; isolation 404.
- **Conformance gates:** `GlRegistryConformanceTest`, `PropertyIsolationConformanceTest`,
  `MediaPrivacyConformanceTest`, `ChartOfAccountsConformanceTest`, `AdminSmokeManifestConformanceTest`.
  Regenerate via `atriom:dump-system-census` + `atriom:dump-admin-manifest`.

## 7. Docs

New `docs/modules/27-owner-statements.md` (standard 10-section template). Same-commit updates:
`docs/OVERVIEW.md`, `docs/modules/21-general-ledger.md` (two registry sources, four keys),
`docs/PROPERTY-ISOLATION.md` (four property-owned models), `docs/BUSINESS-RULES.md` (fee basis + rate
default + 14% VAT-on-fee sign-off + operator-residual disclosure), `docs/accounting/README.md`
(plain-language "what the owner gets paid and why"), `docs/ROADMAP.md` (flagship gap closed).
`DemoSeeder`: a global default fee term + one full paid cycle.

## 8. Build sequence (each green before the next)

1. **Chart + mappings** — two new COA rows (`owner_distributions`, `due_to_owner`; verified-free codes) + two map keys; `ChartOfAccountsConformanceTest` green.
2. **F-80 fix** — dimension the year-end retained-earnings roll by `asset_id` + per-property tie-out. (Before any distribution posting exists.)
3. **Ownership foundation** — `AssetOwner` pivot cast + tenure segments + `currentOwnership()` + `PortfolioStats` fix + weighting regression.
4. **Runs (read + draft)** — `owner_statement_runs` + `owner_statements`, `Generate` service (income − expenses = net, no fee), resource + relation manager, weighting + penny reconciliation. No GL yet.
5. **Run journalizer + finalise** — registry line #1 + `SOURCE_DATE_COLUMNS`, `Finalise`/`Revise`, afterCommit hook, GL tie-out driving the sweep, `GlRegistryConformanceTest` green.
6. **Disbursements** — registry line #2, `DisbursementService` lifecycle + `ApprovalPolicy` `MODULE_DISBURSEMENT`, resource, cap + frozen-permission, disbursement tie-out.
7. **Deliverable polish** — private snapshot PDF, `MyOwnerStatements` page (triple-scope, both gate methods), notifications, carry-forward; regenerate census + manifest; docs.
8. **Future slices** (deferred): the **management-fee engine** (`management_fee_terms` + fee/VAT GL lines + statement fee row); the **settings-driven Egyptian tax catalog**; **mid-period ownership-percentage segments** (relax the `asset_owner` unique to `(user_id, asset_id, started_at)` — see the slice-3 note below); OwnerRequest tie-back.

> **Slice-3 scope decision (shipped):** kept `unique(user_id, asset_id)` (one ownership row per
> owner-property) rather than relaxing to tenure *segments*. Relaxing it risks an ambiguity — MySQL
> treats a `NULL` `started_at` as distinct, so two un-dated rows could silently double a share. The
> single row's `started_at`/`ended_at` already handle a mid-period **sale** (tenure fraction in the
> weighting math); only a mid-period **percentage change** needs multiple segments, which the operator
> hasn't asked for. `AssetOwner` pivot casts dates→Carbon + `coversDate()`; `User::currentOwnedAssets()`
> / `currentOwnershipShares()` are tenure-aware; `PortfolioStats` now weights MRR/AR by the share.

## 9. Decisions

| # | Decision | Outcome | Status |
|---|---|---|---|
| 1 | **Management-fee basis + rate** | **DEFERRED** to a future slice — v1 has no fee (statement = income − expenses = net). Operator: *"system shouldn't care how Eltizam charges Jawad for now."* | ✅ decided (deferred) |
| 2 | Statement basis | **Accrual** headline (ties to GL) + supplementary cash "distributable now" view | ✅ take default |
| 3 | Fee posts to GL? + VAT | **Moot in v1** (no fee). Broader: operator wants a **settings-driven Egyptian tax catalog** (dropdowns), confirmed with the accountant later — tracked in ROADMAP, not this build | ✅ decided (deferred) |
| 4 | Disbursement posts / approval / partials | **Yes / Yes / Yes** — accrue *Due to Owner* at finalise, clear at payment; ladder frozen at schedule; partials capped | ✅ take default |
| 5 | Ownership weighting | **Time-weighted** (`net × pct/100 × tenure_days/period_days`), penny-reconciled, effective-dated segments | ✅ take default |
| 6 | F-80 | **Fix now**, sequenced before the distribution-posting slice | ✅ take default |
