# Change impact — spacing · leasing · receivables · payables → the ledger

**Status:** written 2026-08-11. **Phases 0–3 shipped the same day** — the payables hole, the
change-impact registry and its gate, the document ↔ ledger trail, and the restatement control. Phase 4
(the per-area sweeps) is open. Findings §9 carries the per-item state.

> **The question this answers.** An operator changes something — a unit's area, a lease's rent, an
> issued invoice, a vendor bill already paid. What happens to the general ledger, and *should that
> change have been allowed to move the ledger at all*? What does "void" actually do at each layer,
> what does Yardi Voyager do instead, and what does the operator see while it happens?
>
> **What this is not.** It is not a rebuild. The posting engine, the registry-and-gate design and the
> document immutability work are done and sound (see
> [GL-INTEGRITY-HARDENING-PLAN](GL-INTEGRITY-HARDENING-PLAN.md), all five phases shipped 2026-07-10).
> What is missing is a *stated contract* for change, a mechanical gate behind it, and an operator-facing
> trail — plus one live hole in payables.
>
> **Relationship to the other plan.** [VALIDATION-SWEEP-PLAN](../gap-analysis/VALIDATION-SWEEP-PLAN.md)
> sweeps the same four areas along the *validation* axis (is this rule enforced, and where). This is the
> *accounting-impact* axis (may this change reach the books, and what does it do when it does). Same
> areas, same sequencing, one program — §8 says how they compose. Do not run them as two.

---

## 1. How posting works today, in one page

**Documents are the sub-ledger; the ledger is derived from them.** Nothing calls the posting engine
directly:

1. **The registry.** `LedgerPoster::JOURNALIZERS` ([LedgerPoster.php:90](../../app/Services/Accounting/LedgerPoster.php#L90))
   maps 24 source models to a journalizer. It is the single list; all four dispatch paths derive from it
   via `sources()` — real-time hooks, the nightly sweep, the close gate, `billing:reconcile`.
2. **The journalizer** turns one document into a balanced payload. `InvoiceJournalizer` returns `null`
   for a `draft` or `cancelled` invoice — that is how "no GL effect" is expressed
   ([InvoiceJournalizer.php:78](../../app/Services/Accounting/Journalizers/InvoiceJournalizer.php#L78)).
3. **The date.** `entry_date` comes from the document's own date column
   (`LedgerRealtimeSync::SOURCE_DATE_COLUMNS`), then `PostMonth::resolve()` moves it if an operator set a
   post-month override — the second time coordinate, applied in one place before change detection
   ([LedgerPoster.php:166](../../app/Services/Accounting/LedgerPoster.php#L166)).
4. **The engine** refuses anything unbalanced, one-sided, non-postable, or dated into a closed period
   ([JournalPostingService.php:267](../../app/Services/Accounting/JournalPostingService.php#L267)), and is
   idempotent per source under a row lock.
5. **`sync()` is an upsert against the document's *current* state**
   ([LedgerPoster.php:184](../../app/Services/Accounting/LedgerPoster.php#L184)):

   | Document now | Posted entry | What happens |
   |---|---|---|
   | has an effect | none | **post** |
   | has an effect | identical | **no-op** |
   | has an effect | different accounts, amounts, property **or date** | **void the old + post a new one** |
   | no effect (cancelled / soft-deleted / draft) | exists | **void** |

   Identity includes `asset_id` and `entry_date`, not just the line signature
   ([LedgerPoster.php:303-320](../../app/Services/Accounting/LedgerPoster.php#L303)) — a date-only
   correction used to match stale and leave the entry in the wrong month forever.
6. **Void is never an erase.** `JournalPostingService::void()` posts a *balanced reversing entry*, links
   it by `reversal_of_id`, and marks the original `void`. Both stay on the books and net to zero
   ([JournalPostingService.php:177](../../app/Services/Accounting/JournalPostingService.php#L177)).

---

## 2. The idea that governs everything below

**Atriom's ledger is a projection. Yardi's is a journal.**

Voyager posts at transaction time and never looks back: a posted charge writes its GL detail once, and
the only way to change the books is to post something else. Atriom re-derives each entry from the
document whenever the document moves — which is *arithmetically stronger* (the books cannot drift; the
sweep self-heals; `billing:reconcile --deep` can prove every entry equals its re-derivation) and
*evidentially weaker* (an entry is a fact about the document's state right now, not a record of what was
booked in March).

Three consequences, none of them theoretical:

- **A correction can be invisible.** A void-and-repost is performed by a queued job. The audit trail
  exists (`reversal_of_id`, description "Superseded by an updated document") and no operator-facing
  surface mentions it.
- **A correction can restate a reported month.** `reversalPeriod()` reverses into the *original* entry's
  period when that period is still open
  ([JournalPostingService.php:339](../../app/Services/Accounting/JournalPostingService.php#L339)). Open
  is not the same as unreported: an owner statement can be issued and a VAT return filed weeks before the
  period is closed.
- **Nothing enumerates which changes may do this.** There is a registry for what posts, for posting-date
  guards, for deletion, for isolation, for search, for dashboards, for action authz — and none for *which
  fields of a posted document may change, and what happens when they do*. That is the gap.

**The rule this plan proposes, and the answer to "does the change have the right to affect the ledger":**

> **A change may re-derive a posted entry only while that entry is not yet evidence.**
> Evidence = filed with ETA · delivered to the counterparty · inside a closed period · inside an issued
> owner statement or a filed VAT return. Past that line the document is immutable and the correction is a
> **new document** — void and re-issue, credit note, debit memo, write-off, adjusting journal.

That is Yardi's rule generalized. Yardi enforces it with the post-month close and by never allowing a
posted charge to be edited. Atriom already enforces the first half (finalize locks, ETA guard, close gate)
and has no name for the second half.

---

## 3. The change-impact contract

Every field of every money-touching model gets exactly one of four verdicts:

| Verdict | Meaning | Enforced by |
|---|---|---|
| **R — Refused** | immutable once the document is committed; correct via a new document | model `updating` guard + form lock, the refusal naming the correction path |
| **D — Derived** | editable; the posted entry is voided and re-posted to match | nothing to add — but the operator must be *told* (§6) |
| **P — Prospective** | editable; affects future documents only, never a posted one | an effective-dated register (charge schedule, unit area) |
| **N — Neutral** | no GL consequence at all | nothing |

**A fifth verdict emerged while building it — DESCRIPTIVE.** `LedgerPoster::matches()` compares the
line signature, the `entry_date` and the `asset_id`, and *not* the description. So a field that only
reaches the entry's description text (an invoice number, a payment reference) will be written into a
fresh post but can never cause a re-derive: the ledger can hold a description naming a value the
document no longer has. Folding those into N would have been wrong (they do reach the entry) and
folding them into D would have been wrong (they cannot move the books). Naming them is what makes
check 3 below exact.

**The registry:** `App\Support\ChangeImpact::POLICY` — `Model::class => ['committed' => <when>,
<verdict> => [field => why]]`. The decided verdicts are `field => why` maps because each is a decision
someone made; NEUTRAL is a flat list, because its reason is definitional and uniform and 150 copies of
the same sentence is noise, not documentation.

**The gate:** `ChangeImpactConformanceTest` — four checks, each mutation-proven by breaking it and
watching the build go red, per the house rule that a guard nobody has seen fail is a guard nobody knows
works:

1. **Completeness** — every fillable of every `LedgerPoster::sources()` model is classified exactly
   once, every classified field still exists (catches a rename leaving a rule guarding nothing), and
   every decided field carries a reason. *Proven: dropping one field → "Invoice.notes is unclassified".*
2. **The refusals are real** — every **R** field is dirtied on a **committed fixture** and must throw.
   Not "a guard exists": the guard fires. *Proven: classifying an unguarded field R → "the write
   succeeded".* Paired with a control that changes a non-refused field and must succeed, or a broken
   fixture would read exactly like a working guard.
3. **Nothing a journalizer reads is neutral** — derived textually from the journalizer sources, so a
   mis-classification is caught by the code rather than by a reviewer; and the reverse, that a field
   claimed DESCRIPTIVE is actually read. *Proven: moving `Payment.method` to N → "classified NEUTRAL
   but PaymentJournalizer reads it".*
4. **A posting-date column is never neutral or prospective** — cross-checked against
   `LedgerRealtimeSync::SOURCE_DATE_COLUMNS`. The column that decides an entry's period is the one
   field that cannot be GL-irrelevant. *Proven independently of 3: making `issue_date` P → red.*

Check 3 is deliberately one-directional: it says "this IS read, so it cannot be neutral", never "this
is not read, so it must be". A field read through a helper (`VendorBill::isPostable()`) or a relation
is invisible to a textual scan, and a check that assumed otherwise would produce false failures.

Fixtures are required only for the sources that declare refusals — four today, not twenty-four — and
the gate enforces that correspondence in both directions.

---

### What classifying all 276 fields exposed

The point of a register is that writing it down changes what you can see. Four things, none of which
were visible while the policy lived in scattered `updating` hooks:

1. **Twenty of the twenty-four posting sources declare no refusals at all.** Every field of a recorded
   expense, a vendor payment, a marketing spend, a stock movement, a deposit transaction, a payroll
   run is freely editable while posted, and each edit silently void-and-reposts. That is not
   necessarily wrong — but it was never a decision, and now it is visible as one.
2. **`Payment.tenant_id` is not refused while `Invoice.tenant_id` is.** Re-pointing a captured receipt
   re-books the AR credit against another tenant. Nothing legitimate does it and the allocation guards
   make it hard to reach, but the asymmetry is arbitrary. **First promotion candidate.**
3. **`VendorBillPayment` now has somewhere to be promoted to.** Its money fields were unguarded
   because there was no reversal path — locking them would have left an operator with a wrong cheque
   and no way out. Phase 0 built the void, so `amount` / `payment_date` / `withholding_amount` can now
   become R with a real correction behind the refusal. **Second promotion candidate.**
4. **`FixedAsset`'s depreciation inputs are the clearest PROSPECTIVE case in the system** —
   `useful_life_months`, `salvage_value` and `method` change future depreciation entries and never
   rewrite posted ones, which is exactly the effective-dating pattern spacing and leasing use for
   area and rent.

Promotions are deliberately *not* bundled into Phase 1: the register's job was to make the decisions
visible, and each promotion is a behaviour change that deserves its own test and its own commit.

---

## 4. The four areas — current verdict vs target

Grounded in the code as it stands today, not guessed. **Bold = changes proposed.**

### Spacing — properties, units, floors, areas

| Change | Today | Target | Note |
|---|---|---|---|
| Unit `area_sqm` | **P** — `RemeasureUnitService` closes the row in force and opens the next; `units.area_sqm` is the denormalised current value and only this service moves it | P | Shipped 2026-08-10 (d0047d0). This is the model the rest should follow |
| Unit `asset_id` / property re-point | D (invoice/credit-note GL dimension re-derives) | **R** | A unit does not move between malls. Re-pointing it silently re-books history to another property's books |
| `Asset.leasable_area_sqm` | N today — but it is the **CAM GLA denominator**, read at current value | **P** | See finding F6: a first reconciliation of a *past* year divides by today's GLA |
| Zone (`area_id`) membership of a unit | N today — same denominator path | **P** | Same mechanism, same fix |
| Floor / code / name | N | N | uniqueness only, per the validation sweep |

### Leasing — leases, charges, options, events

| Change | Today | Target | Note |
|---|---|---|---|
| Rent / service charge amount | **P** — `ChargeScheduleService` closes the current row at `effectiveFrom − 1` and opens the next | P | Yardi's model, already built |
| Rate re-derivation on an area change | **P** — `LeaseSpaceChangeService::applyRentChange` re-rates from the rate at the effective date | P | LS-04 |
| Escalation | P (the sweep writes a schedule row) | P | |
| Termination / move-out | **P** — a new document (final account, trailing credit) rather than an edit | P | `monthsCovered()` is the one rule for bill *and* credit |
| `commencement_date` after billing has run | D-ish — no guard found; the charge rows carry the dates that matter | **R once any invoice exists** | Verify before building: does anything already refuse it? |
| Terminal lease (ended / cancelled) | R (model-level immutability) | R | |

### Receivables — invoices, payments, credit notes, deposits, PDCs

| Change | Today | Target | Note |
|---|---|---|---|
| Invoice `issue_date`, `tenant_id`, `lease_id` after issue | **R** — model `updating` guard, "void and re-issue instead" ([Invoice.php:410](../../app/Models/Invoice.php#L410)) | R | |
| Invoice back to `draft` | **R** — both layers (option removed *and* model guard) | R | |
| Invoice items / totals after issue | UI-locked, deliberately **not** model-guarded — `LateFeeService`/CAM legitimately rewrite them via `saveQuietly` | D, and **declared as such** | The reasoning is right; it lives in a plan doc rather than a registry |
| Cancel an invoice with captured cash | **R** — on every path, in `updating` so the write is refused, not merely reported | R | |
| Void an ETA-filed invoice | **R** — steers to a credit note | R | The evidence rule, already implemented once |
| Payment `amount` / `payment_date` after capture | **R** | R | |
| Payment allocations after capture | **D** — deliberately left open | D | Re-buckets AR per property; must be visible (§6) |
| Write-off | new document (`InvoiceWriteOff` → `bad_debt_expense`) | — | Correct treatment: revenue stays, AR clears |

### Payables — vendor bills, vendor payments, expenses, procurement

| Change | Today | Target | Note |
|---|---|---|---|
| Vendor bill money/counterparty fields after `draft` | **R** — "cancel and re-enter it instead" ([VendorBill.php:306](../../app/Models/VendorBill.php#L306)) | R | The AP mirror of the invoice guard |
| Cancel a bill that has payments | **R** — "Reverse the payments first" | R | …but see F1: there is no way to reverse them |
| **Vendor bill payment — any reversal** | ✅ **`VoidVendorBillPaymentService`** (2026-08-11) | — | Was F1, the one live hole. `voided_at` is not fillable: only the service may set it, so a void always carries its reason and its reversal |
| Direct expense (`recorded`) amount / date / category | **D** — the form only locks once *cancelled* ([ExpenseForm.php:19](../../app/Filament/Admin/Resources/Expenses/Schemas/ExpenseForm.php#L19)) | **decision** — D-with-visibility, or R | An expense is posted the moment it is recorded, and then freely editable. AR's equivalent is locked. §7 |
| Marketing spend, fixed asset cost | **D** — deliberately unlocked, reasons recorded | D | Keep; move the reasons into the registry |

---

## 5. What "void" means at each layer

The word is used for four different things. The plan should make the vocabulary explicit, in the UI too:

| Layer | Operation | What it does | Reversible? |
|---|---|---|---|
| **Journal entry** | `void()` | posts a balanced reversing entry, links `reversal_of_id`, marks the original `void`. Both remain | no — post another entry |
| **Invoice** | `VoidInvoiceService::void()` | `status = cancelled` → journalizer returns no effect → the entry is voided. Applied credit notes, tenant credit and netted deposits all return automatically; captured **cash** blocks it; an ETA-filed invoice blocks it | the document stays, cancelled |
| **Payment** | `VoidPaymentService::void()` | `status = refunded` → AR re-opens on every invoice it settled → the GL leg voids | |
| **Vendor bill** | `VendorBillService::cancel()` | `status = cancelled` → AP entry voids. Refuses if payments exist | |
| **Vendor payment** | `VoidVendorBillPaymentService::void()` | sets `voided_at` → excluded from `paid_amount` (the payable re-opens) → journalizer returns no effect → the AP/bank/withholding legs all reverse. The row stays on the register with its reason | the row stays, voided |

**Where the reversal lands.** Original period if open, else today's open period, else refuse. This is
*stricter than Yardi*, which would put it in the current post month by default — and stricter is a
deviation worth stating. Keeping each month self-consistent is right for a single-entity operator who
reports monthly; the risk it creates is restating a month already reported, which §6 closes rather than
undoing the behaviour.

---

## 6. UX — make the ledger visible where the money is

Every mechanism above is invisible to the operator. Five concrete surfaces, in order of value:

1. ✅ **A "Ledger" panel on every money document** — `App\Filament\Actions\LedgerEntryAction`, a factory
   like `PostMonthAction` rather than a panel copied per resource, wired to invoices, payments, credit
   notes, vendor bills and expenses. Shows the state (posted / not posted yet / reversed / *does not
   reach the ledger*), the entry number and date, the post month when overridden, the property
   dimension, the debit/credit lines, **the reversal chain**, every entry the document has ever had,
   and a pending note when it has drifted since posting. Read-only, gated on `general_ledger.view` —
   a leasing user sees their invoice without seeing which accounts it moved.
   The read model is `App\Support\LedgerTrail`, side-effect free (`wouldChange()` is `sync()`'s dry run
   — no lock, no write), so rendering the panel can never move the books.
2. ✅ **A source column + drill-through on the journal-entries table.** The resource is resolved through
   `Filament::getModelResource()` — derived from Filament's own registry rather than a hand-kept
   model→resource map, which would be one more list to drift — and a reversing entry reads as
   *"Reversal of JE-…"* instead of showing a source it does not have. `source` and `reversalOf` are
   eager-loaded, so the column costs no query per row.
3. ✅ **The drift note**, which is item 3 in its useful form: the panel says *"This document has changed
   since it was posted. The ledger is corrected automatically within a few seconds"*. A per-action
   before/after preview (*"this will reverse EGP 12,400 and re-post EGP 13,050"*) is still worth having
   and is deliberately **not** built — `wouldChange()` returns a boolean, and a sibling returning the
   diff is a separate, larger piece.
4. ✅ **A restatement warning** — `App\Support\ReportedPeriod`. A month is reported once a **finalised
   owner statement covers it**, derived rather than stored: no `reported_at` column to set, forget or
   drift out of step with the statements themselves. Surfaced in three places — on the document (a
   danger note above the drift note, because the books will be corrected either way but a figure the
   owner is holding will stop matching them), in the month-end checklist (a non-blocking step, since
   the close is the remedy it steers to), and as a **notification to the GL managers when a re-derive
   actually restates a reported month**, raised in `LedgerPoster::sync()` — the one place a re-derive
   happens, so the one place that can see it. Scoped per property: an owner statement for Mall A says
   nothing about Mall B's March.

   **It warns rather than refuses, and that is a Yardi decision.** Voyager has no "reported" state; its
   control is the post month, and the discipline is that you *close* the month when you report it. A
   reported-but-open month is a process gap, not a transaction to refuse — refusing would be stricter
   than the benchmark and would block the case where the correction is exactly what the owner is
   waiting for. §7.4 is answered by that, and can be revisited if the operator wants a harder stop.
5. **Refusal messages that name the path.** Already the house style — "void and re-issue instead",
   "cancel and re-enter it" — extend it to every R field the new registry adds, EN + AR in the same change.

The freshness affordances already shipped (the "Post to GL now" button and "Ledger last synced …"
subheading on all five statement pages, the sweep-failure alert, the month-end readiness checklist) are
the right pattern. This extends it from the *reports* to the *documents*.

---

## 7. Decisions for the operator

Engineering should not guess these:

1. **Should a recorded expense lock?** Today an invoice locks at issue and an expense never does. Either
   is defensible — an expense has no counterparty document to contradict — but the asymmetry should be a
   decision, not an accident.
2. **Auto-apply open credit** to the next charge, as Voyager does? Still open from the money-flow
   benchmark; a credit raised in dispute silently disappearing into next month's rent is a support call.
3. **Reversal period** — keep the current "original period if open" (stricter than Yardi), or move to
   Yardi's "current post month"? Recommendation: keep, and add the reported-month guard (§6.4).
4. ~~**How much of a "reported" month is locked**~~ — **decided 2026-08-11: warn, and steer to the
   close.** Yardi has no reported state; the close *is* the control, so anything harder would be a
   deviation from the benchmark. Revisit if the operator wants a refusal.

---

## 8. Phases

Each phase ships independently, with a regression test that drives the **real** `accounting:sync-ledger`
path (not `LedgerPoster::sync()` directly) and a same-commit docs update, per the house convention.

| Phase | Work | Size | Why this order |
|---|---|---|---|
| **0 — the live holes** ✅ **DONE 2026-08-11** | `VoidVendorBillPaymentService` + a gated action + the AP-side refusal wording; fix the stale docs (F7, F8) | S | F1 is a money operation with no path; the docs are read by the team |
| **1 — the contract** ✅ **DONE 2026-08-11** | `App\Support\ChangeImpact` + `ChangeImpactConformanceTest`; classify all 24 sources; promote whatever the classification exposes | M | Everything else hangs off it, and it prevents the next source shipping undecided |
| **2 — the trail** ✅ **DONE 2026-08-11** | UX items 1–3 and 5 (document ↔ entry, both directions; re-derive preview; refusal wording) | M | Turns the derived ledger into something visible |
| **3 — restatement** ✅ **DONE 2026-08-11** | the reported-month concept + UX item 4 | S–M | Needs owner-statement finalisation as its trigger, so it follows the trail work |
| **4 — the area sweeps** | spacing → leasing → receivables → payables, each verifying its row in §4 with an *exploit-first* test | M per area | Merge with the validation sweep: one pass per area covering both axes |

**Sequencing note.** §4's rows are claims to be *verified*, not defects to be assumed. The expected
outcome, stated up front because this codebase has repeatedly found the opposite of what a document
promised: most rows already hold. Where they do, keep the test as the guard's witness and say so, rather
than manufacturing findings.

---

## 9. Findings from this investigation

Verified against the source on 2026-08-11, ranked. Everything below was checked from more than one angle.

| # | Sev | Finding | Evidence |
|---|---|---|---|
| **F1** ✅ **FIXED 2026-08-11** | **High** | **A vendor-bill payment cannot be reversed by any path.** Three dead ends that all point at each other: `DeletionPolicy` names the correction as "void the payment — money left the bank" ([DeletionPolicy.php:61](../../app/Support/DeletionPolicy.php#L61)) and no void exists; `VendorBillService::cancel` refuses with "Reverse the payments first" ([VendorBillService.php:98](../../app/Services/VendorBillService.php#L98)) and there is nothing to reverse them with; the payments relation manager has empty `headerActions`/`recordActions`/`toolbarActions` ([VendorBillPaymentsRelationManager.php:55](../../app/Filament/Admin/Resources/VendorBills/RelationManagers/VendorBillPaymentsRelationManager.php#L55)); and the model has no `isCommittedForDeletionPurposes()` override, so the trait's `true` default refuses the soft-delete that would otherwise self-heal the GL. A cheque keyed against the wrong bill is permanent. **Voiding a check is an everyday Voyager operation.** | ← |
| **F2** ✅ **FIXED 2026-08-11** | **High** | **No document ↔ journal-entry surface, in either direction.** No relation manager or ledger panel on Invoice / VendorBill / Payment; no source column or drill-through on the journal-entries table. The data is all there (`source_type`, `source_id`, `reversal_of_id`) | §6.1–6.2 |
| **F3** 🟡 **HALF-FIXED 2026-08-11** — the trail now shows the reversal chain and a drift note on the document; there is still no push notification to the operator who caused it | **Med** | **A re-derive is silent.** A change to a posted document voids and re-posts via a queued job, with a generic description and no notification to the operator who caused it | [LedgerPoster.php:236](../../app/Services/Accounting/LedgerPoster.php#L236) |
| **F4** ✅ **FIXED 2026-08-11** | **Med** | **A correction can restate a reported month.** The reversal lands in the original period whenever it is open; "reported" (owner statement issued, VAT filed) has no representation | [JournalPostingService.php:339](../../app/Services/Accounting/JournalPostingService.php#L339) |
| **F5** | **Med** | **The edit policy is asymmetric and undocumented.** Invoice / Payment / CreditNote / VendorBill lock at finalize; Expense, MarketingSpend and FixedAsset deliberately do not. The reasons exist in a plan doc, not a registry, and nothing fails when a new source ships with no policy at all | §3, §4 |
| **F6** | **Low** | **A first CAM reconciliation of a past year divides by today's area.** The *occupied* denominator is time-weighted and every **re-run freezes the shares** ([CamReconciliationService.php:34-56](../../app/Services/CamReconciliationService.php#L34)) — that half is correct and well-guarded. The **GLA** basis reads `Asset.leasable_area_sqm` / a live `sum('area_sqm')` over the zone ([:322](../../app/Services/CamReconciliationService.php#L322)), neither of which is dated. Narrow: it bites only the first reconciliation of a year that ended before a remeasure | ← |
| **F7** ✅ **FIXED 2026-08-11** | **Low** | **`docs/MONEY-PATHS.md` states the two-channel AR formula** (`captured + credit_applied_amount`) — the invariant has been four channels since the deposit-netting and tenant-credit work. The team's most-read money doc is wrong about the one rule it calls "the one rule" | [MONEY-PATHS.md:16](../MONEY-PATHS.md) |
| **F8** ✅ **FIXED 2026-08-11** | **Low** | **The Yardi money-flow benchmark contradicts itself.** §3 says post month is missing and §5 says write-off is "❌ absent"; §10, re-verified today, marks both **BUILT**. Reconcile the earlier sections or mark them as-written-at-the-time | [02-yardi-money-flow.md](../benchmarks/yardi/02-yardi-money-flow.md) |

**Deliberately *not* findings** — checked and correct: the CAM re-run share freeze (F6's other half); the
atomicity of void-then-repost (a failed re-post rolls the void back, so a closed-period edit leaves the
entry intact and raises an alert rather than a half-reversal); `matches()` treating date and property as
part of entry identity; the ETA-filed void refusal; the captured-cash cancel refusal firing in `updating`
rather than `updated`.

---

## 10. How this compares to Voyager, in one table

| Concern | Yardi Voyager | Atriom today | After this plan |
|---|---|---|---|
| GL model | posted at transaction time; append-only | derived; re-posted on change | derived, **with a stated contract for when it may re-derive** |
| Editing a posted document | impossible | refused on AR/AP documents, open on expenses | classified per field, gated mechanically |
| Correcting one | reverse / credit charge / adjustment | void + re-issue, credit note, write-off, tenant credit | same, plus a vendor-payment void (F1) |
| Two time coordinates | date + post month | date + post-month override | unchanged — already built |
| Closing a period | close post month; reopen deliberately | `AccountingPeriod` + close gate + readiness checklist | plus a **reported** state between open and closed |
| Seeing the GL effect | transaction drills to its GL detail | reports only; documents say nothing | ledger panel on the document, source column on the entry |
| Enforcement of all this | convention and training | conformance gates on 8 registries | a 9th: `ChangeImpact` |

The accounting core is ahead of the benchmark on control and behind it on visibility. This plan closes
the visibility gap and writes down the control that is currently carried in people's heads.
