# GL Integrity Hardening Plan — "posted, then edited" & void coverage

> **Purpose.** Answer two questions from the owner and turn the answer into an actionable plan:
> 1. *An invoice is created → posted to the ledger → someone edits it later. The numbers are now wrong. What is the best practice?*
> 2. *Do all modules that post to the GL have a "void" so the posted ledger entry is reversed?*
>
> **Scope.** Every document that posts into the double-entry General Ledger (module 21) via
> `LedgerPoster` / `accounting:sync-ledger`. Audited 2026-07-10 across all 17 posting sources.
> **Status of this doc:** plan / proposal — nothing here is implemented yet.

---

## 0. TL;DR — the verdict

The accounting core itself is **strong**: balanced-or-reject posting, immutable journal entries,
reverse-via-void, closed-period refusal, idempotent + lock-safe, and a **self-healing reconciling
sweep** (`LedgerPoster::sync()`) that re-derives each entry from the document's *current* state.
For a **money-amount edit**, the ledger is **not permanently wrong** — the next sweep voids the stale
entry and re-posts the corrected one.

But the current model has **four real weaknesses** for this market (live ERP, Egyptian ETA
e-invoicing, external auditors):

1. **Staleness window.** Posting is never synchronous — it only happens on a **daily 05:00 sweep**
   (2-day window) or a manual "Post to GL now" button. Between an edit and the next sweep the Trial
   Balance / Income Statement / Balance Sheet show **wrong numbers for up to ~24–29 h**, with no
   "last-synced" indicator on the statement pages.
2. **Posted documents are freely editable (AR side).** Invoices, payments and credit notes can be
   edited *after* they carry a live GL entry — even after ETA filing. The **AP/expense side already
   locks** money fields after `draft` (`$locked = status !== 'draft'`); the AR side never got the
   same guard, and there is **no model-layer immutability guard anywhere**.
3. **Silent-drift edits ("windowed-miss").** Some money-affecting edits don't bump the swept
   document's `updated_at`, so the windowed sweep never revisits them and the GL stays wrong
   **indefinitely** (no scheduled `--all`). Top case: **editing an invoice line-item's `type`**
   (e.g. `base_rent`→`service_charge`) silently re-posts revenue to the wrong account, and the AR
   tie-out stays green because the total is unchanged.
4. **Closed-period trap + weak safety net.** Editing/deleting a document whose entry sits in a
   **closed period** makes the reversal throw; the nightly sweep **swallows it (exit 0, log only)**.
   The reconcile harness's GL tie-out covers **only AR + AP** (not revenue/VAT/cash/inventory/
   deposits/payroll) and is **skipped entirely on `--month` runs** — the very run used before a close.
   Period close is a **bare status flip** with no "is the GL fully synced?" gate, so a normal close
   can *manufacture* the closed-period trap.

**Answer to Q1 (best practice):** *Lock the document once it is posted/issued/filed; correct it
only via a reversing document (void + re-issue, or a credit note); keep journal entries immutable
and reversible; and post to the GL synchronously (near-real-time) with the sweep retained as a
backstop.* Free-editing a posted financial document is not acceptable practice **regardless of how
good the reconciliation is**, because it destroys the audit trail and breaks the filed-invoice ↔
books correspondence that ETA and auditors depend on.

**Answer to Q2 (void coverage):** *Effectively yes, but with holes.* 16 of 17 sources reverse
correctly (soft-delete → the sweep voids the entry; the AP/expense/deposit/credit-note side also has
explicit `cancel_*` actions whose journalizer returns "no effect"). **The holes:**
`VendorBillPayment` has **no `SoftDeletes`** (a hard delete **orphans** its GL entry); the **AR
documents have no proper user-facing "void/cancel" action** (only a super-admin delete); and every
reversal **fails silently in a closed period**.

---

## 1. How posting works today (one paragraph)

Business documents are the sub-ledgers; the GL is derived. `LedgerPoster::sync($doc)` (called only
by `accounting:sync-ledger`) re-derives a balanced payload from the document's current state via its
`Journalizer`, then: no entry + has effect → **post**; entry matches → **no-op**; entry differs →
**void the stale one + re-post**; no effect now (cancelled / soft-deleted / draft) → **void**.
Journal entries are immutable; `void()` posts a balanced **reversing entry** and needs an **open
period**. The sweep runs **daily at 05:00** over a **2-day `updated_at` window** (`SyncLedgerCommand`),
plus `--all`/`--since` backfills (not scheduled) and an on-demand **"Post to GL now"** button
(`PostsToLedger`, 2-day window, only on the Trial Balance + Journal Entries pages).

---

## 2. Findings — ranked, with evidence

| # | Severity | Finding | Evidence |
|---|----------|---------|----------|
| F1 | **High** | **Staleness window.** No synchronous posting; statements are stale up to ~24–29 h after an edit. Income Statement / Balance Sheet / General Ledger pages don't expose the "Post to GL now" button or a "last synced" note. | `routes/console.php:112` (05:00 daily); `SyncLedgerCommand.php:102` (2-day window); `PostsToLedger.php:20-46`; `TrialBalance.php` / `ListJournalEntries.php` use the trait, `IncomeStatement.php` / `BalanceSheet.php` don't. |
| F2 | **High** | **Posted AR documents are freely editable.** Invoice/Payment/CreditNote money fields (amount, vat_rate, items, date) editable after the doc has a live GL entry — incl. ETA-`valid` invoices. AP side already locks after `draft`; AR side has no lock and no model-layer guard. | `InvoiceForm.php` (no `$locked`), `PaymentForm.php`, `CreditNoteForm.php` vs `VendorBillForm.php:22`, `ExpenseForm.php:19`, `PayrollForm.php:21`. |
| F3 | **High** | **Windowed-miss: invoice item `type` edit.** Changing a line item's `type` remaps the revenue account (`REVENUE_ROLE`) but leaves `invoice.total` unchanged → `invoice.updated_at` doesn't bump (`InvoiceItem` has no `$touches`) → sweep never re-derives → revenue mis-posted **silently** (AR tie-out stays green). | `InvoiceJournalizer.php:20-28,50-51`; `InvoiceItem.php` (no `$touches`); probe: item-`type` edit did not bump `Invoice.updated_at`. |
| F4 | **High** | **Closed-period reversal trap, swallowed.** Editing/deleting a doc whose entry is in a closed period → `void()`→`reversalPeriod()` throws → `SyncLedgerCommand::syncOne` catches, `counts['failed']++`, `Log::warning`, **exit 0** on the scheduled run. GL stays wrong; nobody is alerted. | `JournalPostingService.php:338-348`; `SyncLedgerCommand.php:154-170,87-89`; schedule has no `->onFailure()` (`routes/console.php:112-115`). |
| F5 | **High** | **No close-time GL gate.** `PeriodService::closePeriod/closeFiscalYear` and `YearEndCloseService::close` are bare status flips — no "sync first / tie-out clean" check. A close can be done while edited-but-unswept docs are pending, then those hit F4. | `PeriodService.php:16-39`; `YearEndCloseService.php:47-66`. |
| F6 | **Med** | **Tie-out covers only AR + AP**, and is **skipped on `--month`** (the pre-close run). Revenue, VAT output, cash/bank, inventory, deposits-held, payroll accruals are never reconciled to source, so F3-class drift is invisible to `billing:reconcile`. | `BooksReconciliationService.php:191,246-280`. |
| F7 | **Med** | **`VendorBillPayment` has no `SoftDeletes`** → a hard delete orphans its GL entry (the sweep can't find a trashed row to void). Only structural orphan path in the system. | `VendorBillPayment.php` (no `use SoftDeletes`); all other 16 sources have it. |
| F8 | **Med** | **Windowed-miss: payment reallocation.** Editing pivot `allocated_amount` re-buckets AR/per-asset credits but doesn't bump `Payment.updated_at`. | `PaymentJournalizer.php:41-96`; `EditPayment.php:97-101` recomputes invoices, not the payment. |
| F9 | **Med** | **Windowed-miss: warehouse / vendor-bill asset re-point.** `Warehouse.asset_id` (unscoped users) and `VendorBill.asset_id` are editable with no cascade to their child movements/payments → GL asset dimension stranded. | `WarehouseForm.php:18-23`, no `Warehouse` cascade; `VendorBill.php:136-168`, no payment cascade. |
| F10 | **Med** | **No proper "void" action on AR documents.** Invoices and payments can only be *deleted* (super-admin), not cancelled/voided with an audit reason. Reversal relies entirely on soft-delete → sweep. | `EditInvoice.php` (Delete/ForceDelete/Restore only); `Payment` (DeleteAction only). |
| F11 | **Low** | **No scheduled `--all`.** Anything the 2-day window misses (F3/F4/F8/F9) stays wrong until a human runs `--all`. | `routes/console.php` (only the windowed run is scheduled). |
| — | ✅ Good | **Correctly safe:** VendorBill, Expense, Payroll (line edits self-heal via `recomputeFromLines`→parent `saveQuietly`), DepositTransaction, FixedAsset acquisition/depreciation/disposal (module-23 child cascade + read-only depreciation), the four Advance/Custody journalizers (denormalized own-column `asset_id`), MarketingSpend (budget `asset_id` form-disabled). | per journalizer audit |

---

## 3. The design decision (the important part)

Two philosophies are in tension:

- **(A) Free-edit + self-heal** — today's model. Documents stay editable; the sweep keeps the GL
  eventually-correct by re-deriving.
- **(B) Lock-on-post + correct-by-reversal** — the ERP/accounting standard (SAP, Oracle, NetSuite,
  QuickBooks) and what ETA compliance legally implies.

**Recommendation: adopt (B) as the primary control, keep (A)'s sweep as the backstop.** Concretely:

1. **A posted/issued/filed document is immutable** in its money fields. To change it you **reverse
   and re-issue** (void the document, which the sweep reverses in the GL, then create a corrected
   one) or issue an **offsetting document** (credit note / debit memo). This preserves the original,
   keeps the books ↔ filed-invoice correspondence, and gives auditors a clean trail.
2. **Post near-real-time.** Because `LedgerPoster::sync()` is already idempotent + lock-safe, fire it
   from an **`afterCommit` queued job** on each finalize/cancel/delete. This gives immediate freshness
   **without** touching the delicate `recomputeTotals`/`saveQuietly` machinery (the reason real-time
   was originally deferred). The daily sweep + `--all` remain the self-healing net.
3. **Period locks guard edits/deletes, not just new posts.** If a document has a posted entry in a
   closed period, **block** editing/deleting it (reopen the period first). A silent GL drift becomes
   an explicit, correct refusal.

Lock-on-post is *not* a regression of the elegant sweep — it makes the sweep's job trivial (documents
rarely change after posting) and removes every silent-drift path at the source.

---

## 4. Remediation plan (phased, priority-ordered)

> Convention reminder: every fix ships with a **regression test in `tests/Feature/Regression/`** that
> **exercises the real `accounting:sync-ledger` windowed path** (not `LedgerPoster::sync()` directly) —
> per the "child-source windowed-sweep trap" lesson — plus a docs update to
> `docs/modules/21-general-ledger.md` in the same commit.

### Phase 0 — Structural / silent-drift fixes (fast, high value)
Close the orphan + windowed-miss holes. Small, surgical, independently shippable.

- [ ] **F7 — `VendorBillPayment` soft-deletes.** Add `use SoftDeletes` + `deleted_at` migration so a
  deleted payment self-heals to a voided GL entry like every other source. Add it to any `withTrashed`
  sweep query if needed.
- [ ] **F3 — `InvoiceItem $touches`.** Add `protected $touches = ['invoice'];` so any item edit
  (incl. `type`-only and direct/API/relation-manager edits) bumps `invoice.updated_at` and the
  windowed sweep re-derives. (Mirrors the memory-note's own fix pattern.)
- [ ] **F8 — Payment reallocation touch.** In `EditPayment::afterSave` (and any pivot-sync path) call
  `$payment->touch()` after re-syncing allocations.
- [ ] **F9 — Asset-repoint cascades.** Add a `FixedAsset`-style `updated` cascade to
  `Warehouse::booted()` (bump `movements` when `asset_id` changes) and to `VendorBill::booted()`
  (bump `payments` when `asset_id` changes). Consider a `Unit`→open-invoices cascade for the
  invoice/credit-note asset-dimension vector (F-minor), or accept it as covered once posting is
  near-real-time (Phase 2).
- [ ] **F11 — Schedule a weekly `accounting:sync-ledger --all`** (defense-in-depth) so nothing the
  window misses can persist beyond a week. Keep the daily windowed run for freshness.

### Phase 1 — Document immutability ✅ DONE (2026-07-10)
- [x] **F2 — Extend the `$locked` pattern to AR forms.** `InvoiceForm`/`PaymentForm`/`CreditNoteForm`
  now disable the money-affecting fields once finalized, matching the existing VendorBill/Expense
  convention. Invoice: `status !== 'draft'` → lock items repeater + lease/tenant/issue_date. Payment:
  `record !== null` → lock amount/date/method/tenant (allocations + status stay open). Credit note:
  `status !== 'draft'` → lock items + tenant/invoice/lease/issue_date.
- [x] **Lock-bypass guard (from review — was CRITICAL).** `status` stays editable for legitimate
  transitions, but reverting a finalized invoice/credit-note to `draft` would re-open the locked
  fields. Closed at **both** layers: `draft` dropped from the status options (UI + `in:` validation)
  **and** a model `updating` guard throws on a non-draft→draft transition (covers JS-tamper / API /
  tinker). `EditPayment::guardAllocationsTotal` also had to fall back to the persisted amount, since
  the now-disabled `amount` field isn't in the submitted data.
- [x] **Model-layer immutability guards (defense-in-depth, added at owner's request).** `updating`
  guards on the fields that **no system path ever writes post-finalization** (verified via the
  mutation-surface discovery), keyed on the record's *original* status so the transition *into*
  finalized is never blocked:
  - **Invoice** (was issued+): `issue_date` (period), `tenant_id`, `lease_id` (AR dimension) are immutable.
  - **Payment** (was captured): `amount`, `payment_date` are immutable (the gateway callback only flips
    status and returns early on an already-captured payment).
  - **Credit note** (was issued+): `issue_date`, `tenant_id`, `invoice_id`, `lease_id` are immutable.
  These cover the API / tinker / bulk-edit paths the UI lock can't. `subtotal`/`total`/`items` are
  **deliberately NOT** model-guarded — LateFeeService/CAM legitimately rewrite them on issued invoices
  (via `saveQuietly`, which skips the event anyway), and guarding derived floats risks round-trip false
  positives; those stay UI-locked. All legitimate flows (late fee, CAM, capture, credit apply, every
  forward status transition) still pass — full suite green.
- **Deliberate scope calls** (backed by the discovery):
  - **MarketingSpend NOT locked** — edits fully reconcile via its budget + GL cascade; locking removes a
    valid correction with no integrity gain.
  - **FixedAsset NOT locked** — terminal immutability is already enforced (`EditFixedAsset` `abort_unless(active)`
    + hidden edit action for disposed); 'active' is the normal working state and cost edits cascade to the GL.
- **Tests:** `tests/Feature/Regression/FinalizedDocumentLockTest.php` (10) — fields disabled when finalized /
  enabled when draft, allocations stay open, both layers of the draft-revert guard, and each model guard
  fires while its legitimate transitions/system writes still pass. Full suite **1702 green**.

### Phase 2 — Near-real-time posting + freshness everywhere ✅ DONE (2026-07-10)
- [x] **`afterCommit` sync job.** `App\Jobs\SyncDocumentToLedger` (queued, `ShouldBeUnique` per source)
  re-runs the idempotent, lock-safe `LedgerPoster::sync()`. `App\Support\LedgerRealtimeSync::register()`
  (called from `AppServiceProvider::boot`) wires it to every posting source's `saved`/`deleted`/`restored`
  events with `->afterCommit()`, so a document's entry reconciles within seconds of a change instead of
  waiting up to a day. It fires only on real model events, not on `saveQuietly` — a payment's
  `recomputeTotals` re-derives only the GL-neutral `paid_amount`/`balance`, so it dispatches nothing. The
  one `saveQuietly` path that *does* change the GL (a late-fee item add) intentionally defers to the daily
  sweep (late fees are a scheduled batch, not time-critical). The daily sweep + weekly `--all` remain the backstop.
  Carries the source's class+key (not the model) so a soft-deleted source still resolves (withTrashed) and
  voids its entry; failures are logged best-effort (the sweep re-attempts).
  - **Gated by `config('accounting.realtime_ledger_sync')`** (default on). Disabled in the test env
    (`phpunit.xml`) because the `sync` queue driver runs the `afterCommit` job immediately and the 1707-test
    suite drives sync/the sweep explicitly for deterministic posting — 12 GL tests double-booked with it on.
    Production keeps a real queue worker, so the job runs async after the real commit.
- [x] **Freshness indicator on all statement pages.** The `PostsToLedger` trait (the "Post to GL now"
  button + "Ledger last synced …" subheading) is now on the **Income Statement, Balance Sheet, and General
  Ledger** pages too (previously only Trial Balance + Journal Entries) — so no report can silently show
  stale numbers without the accountant seeing the freshness and being able to force a sync.
- **Tests:** `tests/Feature/Regression/RealtimeLedgerSyncTest.php` (5) — the job posts / voids a document,
  no-ops on a missing source, the dispatch is wired to every source, and all three statement pages expose
  the button + subheading. Full suite **1707 green**.

### Phase 3 — Sweep-failure alerting (F4 safety net) ✅ DONE (2026-07-10)
- [x] **Alert on sweep failures.** `SyncLedgerCommand` now stamps `ledger_last_sync_failures` and sends
  a `LedgerSyncFailedNotification` (bell, database channel) to the GL managers (`permission('journal_entries.post')`
  → super_admin + accounting) whenever the failure count newly appears or changes — de-duped so a persistent
  failure alerts once, not every night. The count is also surfaced on **every** report page's freshness
  subheading (⚠ N documents could not be posted — reopen the period, then Post to GL now). The scheduled run
  stays best-effort exit-0 (so the cron isn't perpetually red) but the failure is now **never silent** — which
  is the actual fix for the closed-period reversal trap (a doc that can't be voided/re-posted because its
  period is closed surfaces loudly).
- **Reconsidered — no broad "block edit/delete of a closed-period document" guard.** The original sketch
  wanted a model guard refusing any change to a doc with a closed-period entry. On inspection that's both
  mostly-redundant and partly wrong:
  - **Edits** of a finalized doc's GL fields are already blocked by Phase 1 (form locks + identity model
    guards); the residual GL-changing path (a late fee added via `saveQuietly`) bypasses model events anyway,
    so a guard wouldn't catch it — the sweep + this alert do.
  - **Deletes** of a closed-period doc are **intentionally handled** by the existing design (the void reverses
    into the current open period — documented in module 21); a blanket block would contradict that and risk
    breaking legitimate system writes (e.g. ETA re-submission on an old invoice).
  - **Preventing** the trap (don't close a period while docs are pending re-sync) is the right lever, and it
    lives in **Phase 4's close-gate** (sync + clean tie-out before close). So Phase 3 makes the trap loud;
    Phase 4 stops it being created.
- **Review fixes:** a **windowed** run no longer false-clears the warning for an *old* stranded doc
  (only a full `--all` run may reset the count to 0 — the trap doc is outside the 2-day window), and the
  `Notification::send` is wrapped best-effort so a notification hiccup can't fail the exit-0 sweep.
- **Tests:** `tests/Feature/Regression/LedgerSyncAlertTest.php` (5) — alerts the GL managers on a failure,
  de-dupes an unchanged count, shows the warning on a report subheading, a windowed run preserves an
  out-of-window warning, and a full `--all` clears it once everything posts.

### Phase 4 — Trustworthy tie-out + close gate ✅ DONE (2026-07-10)
- [x] **F5 — Close gate (prevents the trap being created).** `PeriodService::closePeriod` /
  `closeFiscalYear` now call `assertPeriodsReconciled()` before flipping status: for every posting
  document whose *posted* entry lives in the period(s) being closed, a read-only dry-run
  (`LedgerPoster::wouldChange`) checks it still matches its current state. If any is out of sync (an
  edit/delete not yet re-synced), the close is **refused** with "N document(s) not yet posted — Post to
  GL now, then close" — so closing can never strand a pending re-post/void in a newly-closed period (the
  F4 trap). The year-end action gates **before** posting the closing entry (no orphan on refusal); both
  Filament close actions catch the `DomainException` and show it as a notification.
- [x] **F6 — Universal GL-in-sync check (expands the tie-out beyond AR/AP).** `billing:reconcile --deep`
  adds a `gl_in_sync` check that dry-runs `wouldChange` over **every** posting document — catching drift
  the AR/AP control-account tie-out can't see (a mis-typed revenue split, wrong VAT, a stale
  cash/inventory/deposit/payroll posting). Opt-in (`--deep`) because it re-derives every doc; the default
  run stays fast. This is the general form of "every source's re-derived payload equals its posted entry",
  which subsumes per-account tie-outs. (The AR/AP balance tie-out remains all-time only, as before —
  documented; the per-period pre-close guarantee is the close gate above.)
- **Tests:** `tests/Feature/Regression/CloseGateTest.php` (3) — the gate refuses a period with an
  out-of-sync doc and allows it once synced, and `--deep` catches a revenue-split drift that the shallow
  AR tie-out passes (proving the added coverage). Full suite green.
- **Review:** safe to commit, no critical/high (`wouldChange` verified to exactly mirror `sync()`).
  Fixed the block-message wording (docs can be posted-but-stale/deleted, not only "not yet posted"), noted
  `--deep` can take minutes on a large DB, and documented that `wouldChange` reads `posted` entries only.
  Known follow-ups (non-blocking): `--deep` could chunk; the year-end double-gate has a narrow theoretical
  race (`close()` is idempotent, so a retry recovers).

### Phase 5 — First-class void/cancel for AR documents ✅ DONE (2026-07-10)
- [x] **"Void invoice" action.** `VoidInvoiceService::void($invoice, $reason)` (single-action) sets
  `status='cancelled'`: the `updated` hook returns any applied credit (offsetting credit note),
  `recomputeTotals` zeros the balance, and the sync/sweep voids the GL entry (journalizer returns no
  effect for cancelled). Captured **cash** payments block it ("refund the payment first"); applied
  credit is fine. Exposed as a gated "Void invoice" header action on `EditInvoice` (visible for
  issued/overdue with no captured cash, `invoices.edit`), with a required reason (appended to notes,
  status change is activity-logged).
- [x] **"Void / refund payment" action.** `VoidPaymentService::void($payment, $reason)` sets
  `status='refunded'`: the `saved` hook recomputes the allocated invoices (AR re-opens, since
  `recomputeTotals` counts only captured payments) and the sync/sweep voids the GL leg. Exposed as a
  gated "Void / refund" header action on `EditPayment` (captured only, `payments.edit`), with a reason.
  (Both consistent with the Phase-1 model guards, which allow the status transition but not money-field edits.)
- **ETA guard (from review — was HIGH).** A tax invoice already filed with the Egyptian Tax Authority
  (`eta_status='valid'`) **cannot** be voided internally (that would diverge the books from ETA) — the
  service throws and the action hides it, steering to a credit note / ETA cancellation. The void **reason**
  is also written to the immutable activity log (not only the mutable `notes`).
- **Deferred (non-blocking):** a dedicated `invoices.void` / `payments.void` permission (currently gated on
  `.edit`, which is reasonable now that editing is locked — the `.edit` audience is who corrects invoices).
- **Tests:** `tests/Feature/Regression/VoidActionsTest.php` (6) — void an issued invoice (cancelled +
  balance 0 + ledger reversed), block a void with captured payments, block a void of an ETA-filed invoice,
  void a captured payment (AR re-opens), and both edit-page actions with a reason. Review verdict: core
  mechanics sound (the credit-reversal + double-recompute verified correct). Full suite green.

---

## 5. Effort / sequencing

| Phase | Theme | Rough size | Ship independently? |
|-------|-------|-----------|---------------------|
| 0 | Structural / silent-drift | S (1–2 days) | Yes — do first |
| 1 | Document immutability | M | Yes |
| 2 | Near-real-time + freshness | M | Yes (needs a queue worker) |
| 3 | Closed-period guard + alerting | S–M | Yes |
| 4 | Tie-out + close gate | M | Yes |
| 5 | AR void/cancel actions | S–M | Yes (best after Phase 1) |

Phases 0, 1 and 3 remove the **silent-corruption** paths and are the highest priority for a live,
audited, ETA-filing deployment. Phase 2 removes the staleness window. Phase 4 makes the safety net
actually catch what it currently misses. Phase 5 is the ergonomic correction UX.

---

## 6. Test checklist (per fix)

- Regression test drives the **real `accounting:sync-ledger`** command (windowed path), not
  `LedgerPoster::sync()` directly.
- Invoice item **`type`** edit → after a windowed sweep, revenue account is corrected (F3).
- Payment reallocation → per-asset AR buckets corrected after a windowed sweep (F8).
- Warehouse / vendor-bill `asset_id` re-point → movement/payment GL dimension corrected (F9).
- `VendorBillPayment` delete → GL entry voided, no orphan (F7).
- Editing a locked/posted invoice → refused at the model layer (F2/Phase 1).
- Editing/deleting a closed-period document → refused with "reopen period" (F4/Phase 3).
- Period close with a pending unswept edit → refused until synced + tie-out green (F5).
- Expanded tie-out flags a deliberately mis-mapped revenue/VAT/cash posting (F6).

---

*Audited 2026-07-10. Sources: `LedgerPoster`, `JournalPostingService`, `SyncLedgerCommand`, all 17
journalizers, `BooksReconciliationService`, `PeriodService`, `YearEndCloseService`, and the Filament
resources/forms for every posting source.*
