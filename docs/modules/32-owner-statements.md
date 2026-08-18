# 32 · Owner Statements & Disbursements

كشوف حساب الملاك وصرف مستحقاتهم — the **operator-for-owner deliverable**. Eltizam runs malls *for* the
legal owners (Jawad); this module produces the periodic **owner statement** (property income − expenses =
net) and the **payout** (disbursement) that pays the owner what they are owed, both first-class,
GL-tied-out money events. It was the single highest-value gap in the property/facility competitive analysis
([docs/gap-analysis/competitors/](../gap-analysis/competitors/README.md)).

> **v1 scope (operator decisions).** (1) **No management fee** — the operator's cut is deferred, so the
> whole property net goes to the owner (a future slice adds the fee). (2) **One owner per mall, gets 100%** —
> the ownership-percentage co-owner split + tenure day-weighting are built as infrastructure but **not
> applied**; the sole owner receives the full net. See §3 and the plan
> [docs/plans/04-owner-statements-disbursements.md](../plans/04-owner-statements-disbursements.md).


> **⚠️ Fixed 2026-08-11 — there was no way to record who owns a property.**
> The `asset_owner` pivot had **no UI anywhere**: only `DemoSeeder` ever wrote it, so on a real
> install nobody could say that Jawad owns Atriom Walk or at what share — and this module
> apportions a property's net by exactly that `ownership_percentage`. No rows meant no owner and
> no statement, and an owner who signed in saw an empty dashboard rather than an error, because
> `AssignedAssets` fails closed with a `[0]` sentinel (the right behaviour, but it makes the
> symptom look like a permissions problem).
>
> `AssetOwnersRelationManager` now sits on the Asset resource, gated on **`assets.edit`** — a
> deliberately different gate from the sibling staff manager's `roles.edit`, because staff
> membership grants panel visibility while ownership decides whose money a statement apportions.
> Tenure is a real window: a sale is recorded with an end date, never by deleting the row, or the
> former owner's issued statements lose their basis. The detach action says so.
>
> Worth recording *why* it stayed invisible: `PropertyIsolation` described `AssetOwner` as
> "managed via User/Asset relations" — a comment asserting a UI that did not exist. That comment
> is corrected too.

## 1. Purpose & business context

An owner wants to know, each month, what their property earned and to be paid their share. The operator
wants that produced from the *real ledger* (not a spreadsheet), posted to the books, and paid with an audit
trail. This module reuses the existing per-property P&L (`LedgerReportService::incomeStatement()`) so the
statement **is** the ledger, freezes it, accrues *the property owes the owner*, and clears that liability
when the payout is made.

## 2. Domain model

| Table | Purpose |
|-------|---------|
| `owner_statement_runs` | One property's statement for one accounting period — the accounting truth + **GL source**. `reference` (OSR-YYYY-NNNN), `asset_id`, `accounting_period_id`, `period_start/end`, `posting_date`, `basis` (accrual/cash), `total_revenue`, `total_expense`, `net_operating_income`, `net_distributable` (= Σ children.owner_share), `version`/`supersedes_id`, `status` (draft/finalised/superseded), `finalised_at`/`finalised_by_user_id`. Unique `(asset_id, accounting_period_id, version)`. |
| `owner_statements` | The per-owner **deliverable** child (v1: exactly one per run). `reference` (OS-…), `owner_statement_run_id`, `asset_id` (denormalized), `user_id` (owner), `ownership_percentage` (snapshot), `tenure_from/to`, `weight`, `owner_share`, `share_revenue/expense`, `paid_to_date` (recomputed), `status` (draft/finalised/sent/superseded), `sent_at`. Not a GL source. |
| `disbursements` | A **payout** against a finalised statement — a **GL source**. `reference` (DISB-…), `owner_statement_id`, `asset_id` (denormalized), `user_id` (payee), `amount`, `method` (bank_transfer/cheque/cash), `required_permission` (frozen at schedule), `status` (scheduled/approved/paid/cancelled), `approved_by_user_id`/`approved_at`, `paid_on`, `external_reference`. |
| `asset_owner` (extended) | Now backed by the `AssetOwner` pivot model — casts `started_at`/`ended_at` → date, `ownership_percentage` → decimal, with `coversDate()`. `User::currentOwnedAssets()`/`currentOwnershipShares()` + `Asset::owners()->using(AssetOwner::class)`. |

Chart accounts (module 21): **`owner_distributions` = 34101001** (its own equity branch, a contra-equity
draw) and **`due_to_owner` = 21802001** (a liability under 218 "Due to Related Parties"). Two new
`AccountMapping` keys.

## 3. Business rules & invariants

- **The statement is the ledger.** Figures come from `LedgerReportService::incomeStatement([asset], from, to)`
  (accrual, GL-derived, closing-entry-excluded). For the sole owner, `net_distributable == net_profit` — the
  signature tie-out.
- **Recompute-then-freeze.** Finalise re-reads the ledger and freezes the figures; a draft is disposable and
  regenerable. A finalised run is corrected only via **Revise** (supersede → new version).
- **`net_distributable = Σ owner_share`, penny-reconciled** — so the *Due to Owner* accrual equals exactly
  what the disbursements clear, and nets to zero once the owner is fully paid.
- **v1: one owner, 100%.** The generate service resolves the *current* owner (tenure-aware — a sold-off owner
  drops out) and gives them the full net. `ownership_percentage`/tenure-day-weighting exist but aren't
  applied (a >1-owner mall would split normalized to the full net — defensive only).
- **A statement with no owner cannot be FINALISED** (2026-08-11). A property whose owner has not been
  assigned distributes nothing, the journalizer skips a zero, and what remained was a finalised document
  addressed to nobody that posted nothing — over a P&L showing real money the owner is owed. Refused in
  `FinaliseOwnerStatementRunService` with the remedy named. The **draft is still allowed**: generating it is
  how an operator finds out the owner is missing.
- **A run whose ownership does not total 100% cannot be FINALISED** (2026-08-18). The generate
  service weights each owner `pct / Σ pct`, so shares always sum to the full net — right when every
  owner is recorded, and wrong when they are not: one owner recorded at 50% has Σ = 50, weight 1, and
  takes the whole net, which then posts as `due_to_owner` and becomes the cap disbursements pay
  against. The v1 assumption ("one owner, 100%") is now *enforced* rather than assumed. Refused in
  `FinaliseOwnerStatementRunService`, naming the property and the recorded total.
  **Guarded on the money path, not the owners form**: a 50/50 register cannot be built in one save,
  so blocking data entry would make co-ownership unenterable — the relation manager shows the
  running total instead, and the draft stays generatable because that is how the shortfall is found.
  Genuine co-owners summing to 100 finalise normally. No GL amount changed.
- **`basis` is accrual, and only accrual.** A `cash` constant existed with nothing behind it —
  `LedgerReportService::incomeStatement()` has no basis parameter — so a run stamped `cash` would have
  carried accrual figures under a cash label, which is the one thing a cash-basis reader must not be given.
  Removed 2026-08-11; cash-basis reporting is a real build (a receipts-and-payments P&L), deferred with its
  trigger in the closure ledger.
- **`paid_to_date` is derived** (`OwnerStatement::recomputePaidToDate()` = Σ paid disbursements) — never
  hand-set, mirroring `Invoice::recomputeTotals()`.
- **Disbursement cap:** a payout is capped at `owner_share − paid_to_date`, re-checked **under lock** at both
  schedule and payment; partial payouts allowed. A **paid/cancelled disbursement is terminal-immutable**.
- **Posting-date guard:** the finalise posting date and the disbursement `paid_on` go through
  `App\Support\PostingDate` **in the service** (a closed period is refused). `paid_on` also can't be future.
- **GL single registry:** `OwnerStatementRun` and `Disbursement` are two entries in `LedgerPoster::JOURNALIZERS`
  + `LedgerRealtimeSync::SOURCE_DATE_COLUMNS` (`posting_date` / `paid_on`). Nothing posts synchronously — the
  `saved` afterCommit hook + the `accounting:sync-ledger` sweep do (superseding a run returns `null` → the
  sweep voids its entry, the Revise self-heal).
- **Approval:** disbursements use `ApprovalRule::MODULE_DISBURSEMENT` with **no seeded bands** — approval is
  off by default (the operator's payout policy is unknown); the tier is **frozen at schedule** so a later
  amount edit can't launder authority.

## 4. Lifecycle

```
Run:        draft ──finalise──▶ finalised ──revise──▶ superseded (+ a new finalised version)
Statement:  draft ─────────────▶ finalised ──send──▶ sent          (superseded with its run)
Disburse:   scheduled ─approve─▶ approved ─markPaid─▶ paid          (cancel: → cancelled, if not paid)
```

GL: **finalise** posts Dr `owner_distributions` / Cr `due_to_owner` for `net_distributable`. **markPaid**
posts Dr `due_to_owner` / Cr Bank|Cash for `amount`. Both only in those states; both ride the sweep.

## 5. Services & commands

`app/Services/OwnerAccounting/`:
- **`GenerateOwnerStatementRunService::generate(Asset, AccountingPeriod, basis)`** → draft (idempotent; version-aware).
- **`FinaliseOwnerStatementRunService::finalise(run, actor, ?postingDate)`** → recompute + freeze + status finalised (no synchronous post).
- **`ReviseOwnerStatementRunService::revise(run, actor, ?postingDate)`** → supersede + finalise a fresh version.
- **`DisbursementService::{schedule, approve, markPaid, cancel}`** → the payout lifecycle (cap under lock, frozen tier, PostingDate).
- **`OwnerStatementPdfService::build(OwnerStatement)`** → the bilingual mpdf statement PDF.

Journalizers: `OwnerStatementRunJournalizer`, `DisbursementJournalizer`.

## 6. Filament surface

Two `/admin` resources in the **Accounting** group, both property-scoped via the AnnouncementResource
pattern (`BypassesFilamentTenantAutoScope` + manual `getEloquentQuery`), every write action **dual-gated**
(`visible()` + `authorize()` + `abort_unless`):
- **Owner Statement Runs** — a **Generate** header action (property + period), and **Finalise / Revise /
  Schedule-payout / Download-PDF / Send** row actions. Single-owner columns show owner + share + paid.
- **Disbursements** — the payout board with **Approve / Mark-paid / Cancel** row actions.

The owner (an `owner`-role RBAC user in `/admin`) sees the runs for **their** properties (read-only —
operator actions are permission-hidden) and can **Download PDF** their statement; **Send** bells them.

**The `/owner` panel this section used to describe is GONE** (removed in module 15's close-out, commit
`cadd3a2` — owners are `/admin` users with `owner`-role RBAC, not a separate panel). Its
`OwnerStatementResource` went with it; what an owner sees is the paragraph above — their properties' runs
in `/admin`, read-only, with the PDF. Corrected 2026-08-11: the description of a panel that no longer
exists is worse than no description, because the next person builds their owner surface into it.

**The statement is itemized.** `owner_statement_runs.income_breakdown` (JSON) snapshots the per-account
revenue and expense lines from `LedgerReportService::incomeStatement()` **at generate time**, frozen
alongside the totals (recompute-then-freeze), so the detail can never drift from the net it sums to. The
PDF and both "View working" modals render revenue-by-account → expenses-by-account → net. Legacy runs
(pre-snapshot) fall back to the bare totals.

Permissions: `owner_statements.{view,generate,finalise,revise,send,view_own}`,
`disbursements.{view,schedule,approve,pay,cancel}` — owner auto-gets `.view` + pushed `view_own`; accounting
gets the operator actions; manager auto-gets the non-delete set.

## 7. Notifications / integrations

`OwnerStatementSentNotification` (database bell) to the owner on **Send**. The GL is the main integration
(module 21) — two sources, tie-out-gated.

## 8. Extension points (how to change safely)

- **Add the management fee** (future): a `management_fee_terms` config + fee/VAT lines prepended to the run
  journalizer (`management_fee_expense`, `due_to_operator` 21801001, `vat_recoverable`), and
  `net_distributable = net_profit − fee`. The design is pre-written in the plan §9.1.
- **Multi-owner co-ownership**: the split infrastructure is already present (`weight`, tenure, penny
  reconciliation); apply it by removing the "sole owner = 100%" simplification in
  `GenerateOwnerStatementRunService::rebuildStatements()`, and relax `asset_owner`'s unique for tenure
  segments (see the plan's slice-3 note).
- **A new payout method or approval policy** — a `Disbursement::METHODS` value / seed `disbursement`
  approval bands (turns the gate on; no code change).

## 9. Gotchas

- **Don't set `paid_to_date`/`net_distributable`/`balance` by hand** — they're derived. Route money through
  the services + `recomputePaidToDate()`.
- **A superseded run's journalizer returns `null`** on purpose — the sweep voids its stale entry. Don't add a
  bespoke reversal.
- **F-80 was a prerequisite** — the per-property year-end close (fixed in this epic) is what makes a
  per-property balance sheet reflect the distribution; a consolidated close would strand it.
- **The `/owner` panel is retired-by-default** — owners live in `/admin`; build owner surfaces there, gated
  on `owner_statements.view_own`, not in the owner panel.

## 10. Tests

- **Domain/GL tie-out** (drive the real service + `accounting:sync-ledger` sweep): `OwnerStatementScenarioTest`,
  `OwnerStatementGlScenarioTest`, `OwnerDisbursementGlScenarioTest`, `OwnerDistributionAccountsTest`,
  `OwnershipWeightingTest`, the per-property `YearEndCloseTest`.
- **Resource/UI**: `OwnerStatementResourcesTest` (render-with-rows, the Generate action, the permission gate,
  the PDF, Send-bells-the-owner).
- **Conformance gates** that apply: `GlRegistryConformanceTest`, `PropertyIsolationConformanceTest`,
  `ChartOfAccountsConformanceTest`, `AdminSmokeManifestConformanceTest`, `TranslationCoverageTest`.

---

## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `Disbursement` | **Never deletable** | cancel the disbursement — it is a GL source and an owner payout |
| `OwnerStatementRun` | Deletable (super_admin) | operational: superseded by a new version rather than removed |
| `OwnerStatement` | Deletable (super_admin) | parent-managed: force-deleted when its run is rebuilt |
