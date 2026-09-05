# Change impact — spacing · leasing · receivables · payables → the ledger

**Status:** written 2026-08-11, complete the same day, **REOPENED 2026-08-28**, and closed again the
same day. Phases 0–3 shipped originally — the payables hole, the change-impact registry and its gate,
the document ↔ ledger trail, and the restatement control. Phases 5–9 shipped on the reopening.

**What the reopening was for.** §3 answered *may a change reach the books*, and the registry holds.
Nothing had swept the other axis: **what an operator is offered, told and refused at the moment they
change money.** §11–§14 are that sweep and the decisions taken from it; **§15 is what shipped —
including the two places the sweep itself was wrong**, which is the part worth reading.

**How to read it.** §1–§10 describe the mechanism and were **corrected in place on 2026-08-28** where
they had gone stale (the payment void status, the ledger panel's reach, the before/after preview,
F5's missing tick, and the whole Voyager comparison in §10). A document that contradicts itself across
two sections is worse than one merely out of date, because the reader cannot tell which half is
current. Where a claim CHANGED rather than merely aged, the old wording is kept beside it in a *was*
note — the count corrections in §15.1 are recorded rather than edited away for the same reason.

**Every finding in §9 is closed**, and all four §7 decisions are taken on the Yardi standard. Phase 4
(the per-area sweeps) belongs to the validation axis and is run separately from the registry Phase 1
built.

**Three things were measured and declined rather than built**, and each is recorded where the next
person will look: dating `Asset.leasable_area_sqm` (§9 F6 / module 08 — no pool uses that basis, and
the denominator is already frozen and recorded); notifying on every ledger re-derive (§9 F3 — it would
fire on each late fee and each CAM run, and D4's save toast reaches the one person who caused it
instead); and a per-lease `auto_apply_credit` flag (§7.2 — worth doing only if the operator meets the
case). Saying so is the point: this plan opened by promising to report honestly when the answer is
"nothing to do here".


> **The question this answers.** An operator changes something — a unit's area, a lease's rent, an
> issued invoice, a vendor bill already paid. What happens to the general ledger, and *should that
> change have been allowed to move the ledger at all*? What does "void" actually do at each layer,
> what does Yardi Voyager do instead, and what does the operator see while it happens?
>
> **What this is not.** It is not a rebuild. The posting engine, the registry-and-gate design and the
> document immutability work are done and sound (see
> [GL-INTEGRITY-HARDENING-PLAN](../modules/21-general-ledger.md), all five phases shipped 2026-07-10).
> What is missing is a *stated contract* for change, a mechanical gate behind it, and an operator-facing
> trail — plus one live hole in payables.
>
> **Relationship to the other plan.** [VALIDATION-SWEEP-PLAN](../gap-analysis/README.md)
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
3. ✅ **`VendorBillPayment` — promoted 2026-08-11.** Its money fields were unguarded because there was
   no reversal path; locking them would have left an operator holding a wrong cheque with no way out,
   which is worse than a mutable row. Phase 0 built the void first, deliberately, and `amount` /
   `withholding_amount` / `payment_date` / `vendor_bill_id` are now **R**, with the refusal naming the
   void as the correction. `voided_at` is outside the frozen set, so a void still saves. Proved by
   removing the guard and watching the gate name all four fields.
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
| Invoice items / totals after issue | UI-locked, deliberately **not** model-guarded — the CAM true-up legitimately rewrites them via `saveQuietly` | D, and **declared as such** | ✅ **Done** — declared DERIVED in `ChangeImpact::POLICY` with the reasoning inline, where the next reader looks |
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
| **Vendor bill payment — money fields** | ✅ **R** (2026-08-11) | R | Promoted once the void existed. A refusal is only as good as the path it names, so the correction had to ship first |
| Direct expense — amount / VAT / category / funding source | ✅ **R** (2026-08-11) | R | `recorded` IS posted (there is no draft), so it is immutable from birth; correction is cancel + re-enter. Decided on the Yardi standard, §7.1. Locked at both layers off one predicate |
| Direct expense — date / property | **D** | D | Each carries its own guard (posting-date, `assertAssetInScope`); re-dating or re-homing a correctly-keyed expense does not restate what was spent — the same split VendorBill makes |
| Marketing spend, fixed asset cost | **D** — deliberately unlocked | D | ✅ **Done.** The reasons are inline in `ChangeImpact::POLICY`, and §15.2 records the 2026-08-28 attempt to freeze them both — refused by the code, correctly |

---

## 5. What "void" means at each layer

The word is used for four different things. The plan should make the vocabulary explicit, in the UI too:

| Layer | Operation | What it does | Reversible? |
|---|---|---|---|
| **Journal entry** | `void()` | posts a balanced reversing entry, links `reversal_of_id`, marks the original `void`. Both remain | no — post another entry |
| **Invoice** | `VoidInvoiceService::void()` | `status = cancelled` → journalizer returns no effect → the entry is voided. Applied credit notes, tenant credit and netted deposits all return automatically; captured **cash** blocks it; an ETA-filed invoice blocks it | the document stays, cancelled |
| **Payment** | `VoidPaymentService::void()` | `status = voided` → AR re-opens on every invoice it settled → the GL leg voids. **`voided`, not `refunded`, since 2026-08-28** — voiding a receipt and refunding one are different acts and the old wording told a tenant their money had gone back (§11) | the row stays, voided |
| **Vendor bill** | `VendorBillService::cancel()` | `status = cancelled` → AP entry voids. Refuses if payments exist | |
| **Vendor payment** | `VoidVendorBillPaymentService::void()` | sets `voided_at` → excluded from `paid_amount` (the payable re-opens) → journalizer returns no effect → the AP/bank/withholding legs all reverse. The row stays on the register with its reason | the row stays, voided |

| **Marketing spend · fixed asset · عهدة · advance** | `ReverseMoneyDocumentService::reverse()` | soft-delete → the journalizer reads it as having no effect → the entry is reversed. Offered as **Reverse**, never Delete, and it records a reason | the row stays, trashed |

> **The word is used for four things and §11 names them**: *correct* (تصحيح), *reverse/void* (إلغاء),
> *refund* (استرداد) — and, unique to a derived ledger, *restate*. Yardi keeps the first three apart;
> the fourth has no Voyager equivalent, which is why it needed a word and a screen of its own.

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
   **Extended 2026-08-28 (D4):** also mounted on the nine money **Edit page headers** — a list is
   where you audit, the Edit page is where you act — and on the six remaining sources that have a
   screen. `ChangeImpactConformanceTest` now derives the requirement from Filament's own model→
   resource registry, so a new money resource cannot ship without it.
2. ✅ **A source column + drill-through on the journal-entries table.** The resource is resolved through
   `Filament::getModelResource()` — derived from Filament's own registry rather than a hand-kept
   model→resource map, which would be one more list to drift — and a reversing entry reads as
   *"Reversal of JE-…"* instead of showing a source it does not have. `source` and `reversalOf` are
   eager-loaded, so the column costs no query per row.
3. ✅ **The drift note** — and, since 2026-08-28, **the figures**. The note began as *"This document
   has changed since it was posted"*; `LedgerPoster::pendingRestatement()` is the sibling
   `wouldChange()` never had, so the panel and the save toast both say *"reversed EGP 12,400,
   re-posted at EGP 13,050"* from ONE call and cannot tell two stories about one pending change.
   **Four shapes, four sentences** — post · reverse · reverse-and-re-post · **move to another month**,
   the last because a date-only correction leaves the figure identical on both sides and *"reversed
   EGP 1,000 and re-posted at EGP 1,000"* reads as a no-op while hiding the only thing that moved.
   *(This item said "deliberately not built" until the day it was; see §15.4.)*
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

1. ~~**Should a recorded expense lock?**~~ — **decided 2026-08-11 on the Yardi standard: YES, and it
   is now built.** Voyager does not let a posted payable be edited; you reverse and re-enter. `recorded`
   IS posted here (there is no draft), so `amount` / `vat_amount` / `category` / `paid_from` /
   `bank_account_id` are REFUSED and the correction is cancel + re-enter — the same sentence the
   vendor-bill guard already used.
   `expense_date` and `asset_id` stay editable on purpose, exactly as on VendorBill: each carries its own
   guard, and re-dating or re-homing a correctly-keyed expense does not restate what was spent. Locked at
   both layers, off one predicate.

   `bank_account_id` joined the refused list on 2026-08-22 (EG-12): it chooses the very same account the
   credit leg lands in, only more precisely, so leaving it open while `paid_from` is shut would guard one
   decision and wave its stricter twin through. It carries **one exception, and it is a requirement
   rather than a loophole**: a bank account is `#[PropertyOwned]`, so re-homing an expense to another
   mall leaves it naming an account that mall does not hold, which `RecordsBankAccount` refuses. Without
   the exception the two guards lock the door between them — the move throws, and so does the only edit
   that would let the move through — and a recorded expense naming a bank account could never be
   re-homed at all. So when `asset_id` moves, `bank_account_id` may move with it (or be cleared, handing
   the choice back to the rail); the cross-property guard still validates the new pairing on the same
   save. What stays refused is moving the credit between banks on a document that is standing still.
2. ~~**Auto-apply open credit** to the next charge, as Voyager does?~~ — **decided AND already built,
   2026-08-11 (`4792adb`).** Voyager's application order runs existing open credits first; waiting for
   an operator was the deviation, not the reverse.

   **Correction:** an earlier revision of this section said "specified, not yet built" and set out a
   design. That was wrong — a parallel session had already shipped it hours earlier, acting on the same
   standing instruction, and I did not check before writing. The design sketch has been deleted rather
   than left to read as outstanding work.

   **What shipped:** a `saved` hook on `Invoice` with a re-entrancy guard (applying a credit re-saves
   the invoice, which fires the same hook — the guard is what stops both the loop and a double
   draw-down), gated by `BillingSettings::auto_apply_tenant_credit`, an operator-editable setting
   rather than a deploy-time config. It reuses `ApplyTenantCreditService` unchanged, so the draw-down
   stays ONE dated GL source (`Dr Unearned / Cr AR`) with one over-allocation guard, and remains the
   third of the four settlement channels rather than becoming a fifth. Hooked on the model rather than
   in each of the six services that raise an invoice, for the same reason every registry here exists.

   **The one refinement not taken:** the switch is global, where Yardi's is per property. A per-lease
   `auto_apply_credit` flag would let a single disputed balance be held while the default stays on.
   Worth doing only if the operator meets that case — recorded, not built.

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
| **F3** ✅ **CLOSED 2026-08-11** — the trail shows the reversal chain and a drift note on the document, and a reported-month restatement raises a real alert. Going further is a **deliberate non-goal**: a notification on EVERY re-derive would fire on each late fee and each CAM run, and an alert that arrives dozens of times a month is one nobody reads. The cases that carry consequence are covered | **Med** | **A re-derive is silent.** A change to a posted document voids and re-posts via a queued job, with a generic description and no notification to the operator who caused it | [LedgerPoster.php:236](../../app/Services/Accounting/LedgerPoster.php#L236) |
| **F4** ✅ **FIXED 2026-08-11** | **Med** | **A correction can restate a reported month.** The reversal lands in the original period whenever it is open; "reported" (owner statement issued, VAT filed) has no representation | [JournalPostingService.php:339](../../app/Services/Accounting/JournalPostingService.php#L339) |
| **F5** ✅ **FIXED 2026-08-11** — `App\Support\ChangeImpact` is the registry it asks for, and `ChangeImpactConformanceTest` fails the build on an unclassified field, so a new source cannot ship undecided. Marked here 2026-08-28: it was the one finding left without a tick while the header above claimed all of §9 was closed | **Med** | **The edit policy is asymmetric and undocumented.** Invoice / Payment / CreditNote / VendorBill lock at finalize; Expense, MarketingSpend and FixedAsset deliberately do not. The reasons exist in a plan doc, not a registry, and nothing fails when a new source ships with no policy at all | §3, §4 |
| **F6** ✅ **FIXED 2026-08-11 — and it was worse than filed** | **Low→Med** | **A first CAM reconciliation of a past year divides by today's area.** The *occupied* denominator is time-weighted and every **re-run freezes the shares** ([CamReconciliationService.php:34-56](../../app/Services/CamReconciliationService.php#L34)) — that half is correct and well-guarded. The **GLA** basis reads `Asset.leasable_area_sqm` / a live `sum('area_sqm')` over the zone ([:322](../../app/Services/CamReconciliationService.php#L322)), neither of which is dated. Narrow: it bites only the first reconciliation of a year that ended before a remeasure. **On fixing it, the NUMERATOR turned out to be undated too** — `Lease::totalAreaSqmForPeriod()` time-weighted tenure while reading the current `area_sqm`, so the unit-area register never reached CAM at all. Both now compose through `Unit::areaSqmDaysBetween()` (m²·days). `Asset.leasable_area_sqm` remains undated — and **dating it was measured and declined 2026-08-11**: no pool uses the GLA basis, a property's GLA moves every few years rather than every fit-out, and `denominator_used_sqm` already freezes and records what each pool divided by. See [module 08](../modules/08-cam.md) | ← |
| **F7** ✅ **FIXED 2026-08-11** | **Low** | **`docs/MONEY-PATHS.md` states the two-channel AR formula** (`captured + credit_applied_amount`) — the invariant has been four channels since the deposit-netting and tenant-credit work. The team's most-read money doc is wrong about the one rule it calls "the one rule" | the retired `MONEY-PATHS.md`; the doc was deleted on 2026-08-19 rather than corrected, because [modules/05–07](../modules/README.md) already state the four-channel rule |
| **F8** ✅ **FIXED 2026-08-11** | **Low** | **The Yardi money-flow benchmark contradicts itself.** §3 says post month is missing and §5 says write-off is "❌ absent"; §10, re-verified today, marks both **BUILT**. Reconcile the earlier sections or mark them as-written-at-the-time | [02-yardi-money-flow.md](../benchmarks/yardi/02-yardi-money-flow.md) |

**Deliberately *not* findings** — checked and correct: the CAM re-run share freeze (F6's other half); the
atomicity of void-then-repost (a failed re-post rolls the void back, so a closed-period edit leaves the
entry intact and raises an alert rather than a half-reversal); `matches()` treating date and property as
part of entry identity; the ETA-filed void refusal; the captured-cash cancel refusal firing in `updating`
rather than `updated`.

---

## 10. How this compares to Voyager, in one table

> **Re-verified against the code 2026-08-28.** The "Atriom today" column describes the state as it
> now stands, not as it stood when this plan opened — the old column is preserved in the *was* notes,
> because a benchmark table that quietly updates itself teaches nothing about what moved.

| Concern | Yardi Voyager | Atriom today | Deviation, stated |
|---|---|---|---|
| GL model | posted at transaction time; append-only | **derived**; re-posted on change, under `ChangeImpact` | ours is arithmetically stronger (the books cannot drift) and evidentially weaker (§2) |
| Editing a posted document | impossible | refused per field on **12 of 24** sources; the rest are service-managed with no operator form *(was: "open on expenses")* | matched, where a form exists |
| Correcting one | reverse / credit charge / adjustment | void + re-issue · credit note · write-off **and its reversal** · tenant credit · vendor-payment void · `ReverseMoneyDocumentService` | matched |
| Void vs refund vs NSF | three separate acts | three separate statuses since 2026-08-28 *(was: void and refund shared `refunded`)* | matched |
| A reason on every reversal | required, by reason code | required on all **18** sources that have a named act (14 distinct acts), into the audit trail; the other 6 are undone by reversing their cause | matched; ours is free text, not a code list |
| Two time coordinates | date + post month | date + post-month override | matched |
| Closing a period | close post month; reopen deliberately | `AccountingPeriod` + close gate + readiness checklist + a **reported** state | ours adds a tier Voyager has no equivalent for |
| Editing into a closed period | accepted; correction books to the current post month | **refused**, naming the period and both ways out (`SealedPeriod`) | **stricter than Yardi** — deliberate, and consistent with our reversal-period choice (§5) |
| Seeing the GL effect | transaction drills to its GL detail | ledger panel on every source that has a screen, both directions, with the pending figures *(was: "reports only; documents say nothing")* | matched |
| Enforcement of all this | convention and training | conformance gates on 10 registries | ours is mechanical where Voyager's is procedural |

**Where we deliberately differ from Voyager, and why** — the three rows above marked as deviations:

1. **A derived ledger** (§2). Kept: the sweep can prove every entry equals its re-derivation, which
   Voyager cannot. Everything in §11–§15 exists to make that derivation *visible and consented to*.
2. **Refusing a closed-period edit** rather than booking the correction forward. Voyager's control is
   the post month; ours is the period, and we already chose "original period if open" for reversals
   (§5). Booking August's cost silently into October would contradict a decision already taken.
3. **A "reported" month** between open and closed. Voyager has no such state — its control is that
   you *close* the month when you report it. Ours warns and steers to the close (§6.4, §7.4).

The accounting core was ahead of the benchmark on control and behind it on visibility. That gap is
now closed; what remains different is listed above, and each line is a choice rather than a shortfall.

---

# Part two — the operating model (2026-08-28)

> **Why this part exists.** §1–§10 answered *may this change reach the books*. The registry holds and
> the engine is sound. What was never swept is the other half: **for each of the 24 posting sources,
> what is the operator actually offered — an edit, a reversal, a delete, or nothing — and is what they
> are offered the same thing this plan says the contract is?** The answer is no, and the unevenness is
> the whole finding. Nothing below asks to rebuild the money core. It asks for one vocabulary, applied
> to twenty-four screens that currently speak nine.

## 11. The concept, before the findings

Three words get used interchangeably in this codebase's UI and they are three different accounting
acts. Yardi keeps them apart, and so should we, because a tenant and an auditor read them differently.

| Act | What actually happened | What the books do | What the counterparty sees |
|---|---|---|---|
| **Correct** — تصحيح | nothing was wrong with the money; the record was wrong | the entry re-derives, or nothing has posted yet | ideally nothing — the document was never sent |
| **Reverse / void** — إلغاء | the document should never have existed | a balanced reversing entry; both stay, netting to zero | the document stays on the register, marked void, with a reason |
| **Refund / return** — استرداد | the document was right; money is going back | a **new, second** movement out of the bank | a real payment they receive |

**Atriom currently collapses the second and third for receipts.** `VoidPaymentService` sets
`status = 'refunded'`. A cashier who keys 69,674.50 against the wrong tenant and voids it leaves a
receipt that the tenant statement, the books and the audit trail all call **refunded** — a claim that
money went back to a tenant who never received any. `bounced` is correctly separate; void and refund
are not. (`payments.status` already carries eight values; a ninth, `voided`, is the fix.)

**And one more distinction the plan needs.** Atriom's ledger is *derived* (§2), so there is a fourth
act with no Yardi equivalent: **restate** — an edit to a posted document that silently voids its entry
and posts a fresh one. Yardi cannot do this at all. It is arithmetically stronger and it is the act
with **no word for it on any screen**.

---

## 12. The sweep — 24 sources, four questions each

Read the table this way: **Lock** = does the model itself refuse a change to the money once the
document is committed (not just the form). **Reversal** = is there a named, operator-reachable act
that undoes it. **Reason** = does that act record *why*. **Ledger panel** = can the operator see what
the document did to the books, from the document.

### Tier 1 — the contract is fully delivered (2 of 24)

| Source | Lock (model) | Reversal | Reason | Ledger panel |
|---|---|---|---|---|
| `Invoice` | ✅ `issue_date`, `tenant_id`, `asset_id`, `lease_id`, `unit_ownership_id` | ✅ `void_invoice` | ✅ | ✅ list only |
| `Payment` | ✅ `amount`, `payment_date`, `tenant_id` | ✅ `void_payment` | ✅ | ✅ list only |

### Tier 2 — locked and reversible, but the reversal records no reason (5)

| Source | Lock (model) | Reversal | Reason | Ledger panel |
|---|---|---|---|---|
| `VendorBill` | ✅ 5 fields | ✅ `cancel_bill` | ❌ | ✅ list only |
| `VendorBillPayment` | ✅ 4 fields | ✅ `void_payment` (RM) | ✅ | ❌ |
| `Expense` | ✅ 5 fields | ✅ `cancel_expense` | ❌ | ✅ list only |
| `CreditNote` | ✅ 5 fields | ✅ `void` + `reverse` | ❌ | ✅ list only |
| `Disbursement` | ✅ terminal-state guard | ✅ `cancel` (table) | ❌ | ❌ |

### Tier 3 — reversible, but **every money field is freely typed over** (4)

`ChangeImpact` classifies all of these `DERIVED`, i.e. *"editable; the posted entry is voided and
re-posted to match"* — and there is **no model-level guard**, so the only thing between an operator
and a restated month is a `disabled()` on a form. An import, the API, a console command or a second
screen reaches straight past it.

| Source | Fields left open | Reversal | Reason |
|---|---|---|---|
| `Payroll` | 12, incl. `gross_salaries`, `net_paid`, `salary_tax`, `social_insurance` | ✅ `cancel_payroll` | ❌ |
| `DepositTransaction` | 10, incl. `amount`, `type`, `transaction_date` | ✅ `cancel_deposit` | ❌ |
| `CustodyTransaction` | 6, incl. `amount`, `type`, `transaction_date` | ✅ `reverse` (RM) | ✅ |
| `EmployeeAdvanceRepayment` | 4, incl. `amount`, `repaid_on` | ✅ `reverse_repayment` (RM) | ✅ |

### Tier 4 — no named reversal at all; correction is edit-in-place or delete (6)

| Source | What the operator has | The problem |
|---|---|---|
| `FixedAsset` | Edit form with **zero `disabled()` calls** | `acquisition_cost` and `acquisition_date` retype freely on a capitalised, depreciating asset. The acquisition entry re-derives; the 141 `DepreciationEntry` rows already posted keep the **old** cost basis. Two truths about one asset |
| `MarketingSpend` | plain `EditAction` + `DeleteAction` | A GL document with a delete button. Delete → soft-delete → entry voided, **no reason recorded anywhere** |
| `EmployeeAdvance` | grant only | An advance keyed at the wrong amount has no undo |
| `Custody` | settled via service | Same |
| `DepreciationEntry` | no UI | Re-run only |
| `FixedAssetDisposal` | no UI | — |

### Tier 5 — service-managed, and right in shape (7)

`TenantCreditApplication`, `DepositApplication`, `InvoiceWriteOff`, `StraightLineRentAdjustment`,
`SlaPenalty`, `StockMovement`, `OwnerStatementRun`. No operator form, reversal through their own named
action (`reverse_credit`, `reverse_deposit_application`, the write-off reversal, a superseding run).
**These are the model the rest should follow** — nothing typed, one act, one reason. One of the seven
has its reversal built and unreachable, which §12.4 covers.

### 12.1 — What the sweep totals

| Question | Answer |
|---|---|
| Sources posting to the GL | **24** |
| Enforce their own money immutability at the model | **7** |
| Have a named, operator-reachable reversal | **13** (the other 11 are corrected by editing, deleting, or not at all) |
| Record *why* on that reversal | **5** — `void_invoice`, `void_payment`, the vendor-payment void, the custody reverse, the advance-repayment reverse. The other 8 record nothing |
| Show the operator what the document did to the books | **5**, and only as a row action on the **list** — never on the Edit page, which is the screen where the operator is about to change something |
| Refusal messages that are English-only | **62 of 259** (24%) across models + services, concentrated in exactly the money-immutability guards the Arabic-speaking accountant hits, and they interpolate the raw column name (`A captured payment's payment_date is immutable`, never «تاريخ الدفع») |

### 12.2 — The specific answer to "can I update a payment?"

Measured against the live database on receipt `RCT-0001` (captured, 69,674.50, dated 2026-01-03):

```
amount        -> REFUSED   "A captured payment's amount is immutable — void and re-record instead."
payment_date  -> REFUSED   "…payment_date is immutable — void and re-record instead."
tenant_id     -> REFUSED   "…tenant_id is immutable — void and re-record instead."
method        -> ACCEPTED  ← moves the GL cash leg to a different chart account, silently
notes         -> ACCEPTED  ← neutral, correct
allocations   -> ACCEPTED  ← re-settles different invoices; AR recomputes; GL re-derives only if
                             the property split changed. No warning, and the re-derive is queued
```

**So: the refusals are right and Yardi-shaped, and the accepted ones are the gap.** Changing `method`
or `bank_account_id` on a captured receipt moves which bank account the money is recorded in — a real
books change — under a plain "Saved" toast. Re-allocating a receipt across invoices is a legitimate
everyday act and is also silent. Neither tells the operator anything happened to the ledger.

The same probe on an issued invoice: `issue_date` and `tenant_id` refused; **`total` and `subtotal`
accepted at the model layer.** That is deliberate (the CAM true-up rewrites issued totals) and it means
an issued invoice's revenue immutability is a **form lock only** — the API, an importer and tinker all
walk past it.

### 12.3 — Live defect 1: a closed-period edit creates permanent drift (proved, not argued)

> **Reproduced end to end against the running MySQL database.** Marketing spend #1, 1,590.00, dated
> 2026-08-18, posted as `JE-0519`. Its accounting period was closed (an ordinary month-end). An
> operator then retyped the amount to 2,824.00:
>
> ```
> SAVE ACCEPTED. new amount = 2824.00
> SYNC THREW: Cannot void: neither the original entry's period nor the current period is open.
> GL now: JE-0519 total_debit = 1590   ← the books
> document says 2824.00                ← the register
> wouldChange() = true                 ← permanent, un-clearable drift
> ```
>
> The operator saw **"Saved ✓"** and nothing else.

**Everything here behaves exactly as designed, and the outcome is still wrong.** The void-then-repost
is atomic, so the books did not half-move (§9 already records that as correct, and it is). The
posting-date guard is `isDirty`-only and deliberately so — its docblock says the rule is *"nobody MOVES
an entry into a sealed period"*, not *"old records are read-only"*, and it names the immutability
guards as the owner of the second rule. **But the immutability guards cover 7 of 24 sources, and
`MarketingSpend` is not one of them.** The rule has an owner named in a docblock and no owner in code.

What follows from that state:

- `wouldChange()` is true for ever, so `billing:reconcile --deep` fails permanently, `books_tie_out`
  on `/health` is red permanently, and `atriom:preflight` — which `deploy.sh` runs every release —
  **blocks the next deploy** for a reason that has nothing to do with the deploy.
- `LedgerSyncFailedNotification` does fire, but it alerts **once** (by design — a persistent failure
  alerts on change, not every run), a day later, carrying a **count**, to the GL managers. It does not
  name the document and it never reaches the operator who caused it.
- There is **no operator-facing path back**. Reverting the amount by hand clears it; nothing on any
  screen says so.

The clean books measured today (705 documents sampled, **1** drifted, and that one an unfinalised
owner-statement run) are evidence the mechanism works — not that the door is shut.

### 12.4 — Live defect 2: a bad debt that gets paid cannot be recovered

`WriteOffInvoiceService::reverse()` exists, is 40 lines, voids the write-off's GL entry, re-opens the
invoice's AR, and is **called by two test files and nothing else.** There is a `write_off` action on
the invoice; there is no un-write-off anywhere in `app/`.

**Bad-debt recovery is an ordinary event.** A tenant leaves owing 48,000, the operator writes it off at
year-end, and eighteen months later the tenant's lawyer settles. In Voyager you reverse the write-off
and receipt the money against the re-opened charge. Here the write-off is permanent: the only paths are
to raise a *new* invoice for money that was already billed once — which double-counts the revenue — or
to book the receipt as miscellaneous income, which loses the tenant's AR history.

This is the shape [`ServiceReachability`](../../app/Support/ServiceReachability.php) exists to catch and
cannot see: the **class** is reachable (the `write_off` action calls it), so the gate is satisfied while
one of its two public methods has no caller. **Its green tests are what hide it** — a reversal with two
passing test files reads as maintained. Same finding as `BillUnitOwnershipsService` in the 2026-08-18
sweep, one level down: reachability is currently a property of classes and needs to be a property of
**public methods on money services**.

---

## 13. The options — Yardi-shaped, with a recommendation on each

Each is a real decision with a real alternative. The recommendation is the Yardi/market default unless
the deviation is stated.

### D1 — What may an operator do to a posted money document?

| | Option | Cost |
|---|---|---|
| **A** ⭐ | **Yardi standard: nothing that moves money.** Every source gets the treatment `Invoice` and `Payment` already have — a model-level guard on the fields that reach a journal line, and a named reversal beside it. Descriptive fields (notes, references, dimensions carrying their own guard) stay open | M — 17 sources × one `updating` guard, most of them four lines |
| **B** | Keep `DERIVED`, but require a **confirmation that states the restatement** — *"this will reverse EGP 12,400 and re-post EGP 13,050 in August 2026"* — before the save commits | M–L — needs the `wouldChange()` sibling that returns the diff, which §6.3 declined once |
| **C** | Status quo: form locks only, model open | 0, and it is what produced §12.3 |

**Recommendation: A, with B for the handful where re-deriving is genuinely the right correction**
(`asset_id` re-homing, `bank_account_id`, `tax_code`). Yardi does not let you edit a posted payable;
you reverse and re-enter. `Expense` was already decided this way on 2026-08-11 (§7.1) — this applies
the same sentence to the other sixteen. **B alone is not enough**: a modal is a rendering decision, and
the Livewire payload, the API and the importer never see it.

### D2 — Void, refund and NSF are three acts

| | Option | |
|---|---|---|
| **A** ⭐ | Add **`voided`** to `payments.status` beside `refunded` and `bounced`. `VoidPaymentService::void()` writes `voided`; a genuine refund becomes its own outbound movement | S — one `ValueSets` entry, one journalizer branch check, both lang files, a migration to reclassify nothing (existing rows stay `refunded`, correctly, until someone says otherwise) |
| **B** | Keep one word, document it | 0 — and the tenant's statement keeps saying money went back when it did not |

**Recommendation: A.** Voyager, MRI and Entrata all separate reverse-a-receipt from refund-a-tenant,
because they are different money and different evidence. This is the cheapest item in the plan and the
one an auditor will ask about first.

### D3 — Editing a document whose entry is in a closed period

| | Option | |
|---|---|---|
| **A** ⭐ | **Refuse the save**, naming the period and the remedy: *"August 2026 is closed. Reverse this document into an open period, or ask an administrator to reopen August."* One guard, on every GL source, derived from `SOURCE_DATE_COLUMNS` — the registry already knows each source's date column | S–M |
| **B** | Accept the save, then **auto-reverse into the current open period and re-post there** — Yardi's actual behaviour, since Voyager's control is the post month | M, and it moves money between months without asking |
| **C** | Accept, and raise a blocking alert naming the document | S, and the drift still exists |

**Recommendation: A.** It is the same shape as `GuardsPostingDate` — which already refuses *moving* a
date into a closed period — extended from the date to the amount. B is closer to Voyager but Atriom
deliberately chose "original period if open" (§5), and this operator reports monthly; silently moving
August's cost into October would contradict the choice already made. **A is stricter than Yardi and
that is the deviation, stated.**

### D4 — Does the operator ever get told the books moved?

| | Option | |
|---|---|---|
| **A** ⭐ | The **save toast says it**: *"Saved. This document's ledger entry will be re-posted (August 2026)."* — on any save where `wouldChange()` was true before the write. One seam on the money Edit pages, not per resource | S |
| **B** | Mount the existing `LedgerEntryAction` panel on **every source that has a screen** and on the **Edit page** header, not only the list row | S — the factory exists; this is call sites. *(Written as "all 24" and corrected: twelve of the 24 have no admin screen at all, so the reachable set is twelve — see §15.3.)* |
| **C** | Both | S–M |

**Recommendation: C.** §6.1 built the panel and §6.3 declined the preview; the missing piece is not new
machinery, it is putting the machinery on the screen where the decision is made. A row action on a list
is where you *audit*; the Edit header is where you *act*.

### D5 — Every reversal records why

**Recommendation:** make `reason` a **required** field on all thirteen reversal actions (eight lack it
today: `cancel_bill`, `cancel_expense`, `cancel_payroll`, `cancel_deposit`, the disbursement cancel,
both credit-note acts, and the two invoice application-reversals),
recorded to the activity trail the way `VoidInvoiceService` and `VoidPaymentService` already do —
`activity()->event('voided')->withProperties(['reason' => …])`, never only into `notes`, which is
editable. No option table: this is an audit control, not a preference. Yardi requires a reason code on
every reversal.

### D6 — A GL document should not have a Delete button

**Recommendation:** `MarketingSpend` is `#[DeletionAllowed(reason: 'operational: a spend line')]` and
carries a plain `DeleteAction` on its relation manager. It posts to the ledger. Either it becomes
`#[NeverDeletable]` with a `cancel_spend` reversal beside it (consistent with `Expense`), or the reason
is rewritten to say why a posting source is different from the other eight money documents. The same
question applies to `FixedAsset`, `EmployeeAdvance`, `Custody` and `OwnerStatementRun` — all four have
stated reasons, all four should be re-read against "does it post to the GL", which was not the question
asked when they were classified.

### D7 — Refusals speak Arabic

**Recommendation:** the 62 raw-string `DomainException`s become `__()` keys with the **column label**
resolved through `admin.fields.*` — the catalogue `ActivityVocabulary` and the forms already use — so
the accountant reads *«لا يمكن تعديل تاريخ الدفع لإيصال مُحصَّل — قم بإلغائه وإعادة تسجيله»* rather
than an English sentence with a snake_case column in the middle of it. No option: the panel is
bilingual and these are the messages that matter most.

---

## 14. Phases

Same convention as §8 — each ships independently, with a regression test that drives the **real** path
(the Filament page or `accounting:sync-ledger`, never `LedgerPoster::sync()` directly), and a
same-commit docs update. Ordered so the cheapest audit-visible wins land first and the wide sweep last.

| Phase | Work | Decisions | Size | Why this order |
|---|---|---|---|---|
| **5 — the live defect** | D3: one closed-period guard over all 24 sources, derived from `SOURCE_DATE_COLUMNS`. A regression test that reproduces §12.3 exactly and goes red without the guard | D3 | S–M | It is the only thing here that can permanently red a `deploy.sh` preflight |
| **6 — vocabulary** | D2 (`voided` ≠ `refunded`) + D5 (reason required on all **thirteen** reversals — the sweep first counted ten) + D7 (the 62 refusals in both languages) | D2, D5, D7 | S–M | Cheap, audit-visible, no behaviour change to the books |
| **7 — visibility** | D4: `LedgerEntryAction` on every source that HAS a screen **and** on Edit page headers; the save toast that names the re-post | D4 | S–M | Turns the machinery §6 built into something an operator meets. Must land before Phase 8 so the refusals it adds are legible |
| **8 — the lock sweep** | D1(A): promote the 17 unguarded sources' money fields from `DERIVED` to `REFUSED` **where a reversal path already exists**, model-level, off one predicate per source. `Payroll`, `DepositTransaction`, `FixedAsset` first — they are true accounting documents | D1 | M–L | The widest change, and it needs its reversal paths (Phase 9) and its wording (Phase 6) in place first, or it traps operators |
| **9 — the missing reversals** | Wire `WriteOffInvoiceService::reverse()` to an action (§12.4 — it is built and tested, it needs a button). A named reversal for `FixedAsset`, `MarketingSpend`, `EmployeeAdvance`, `Custody` (Tier 4). Then D6. Extend `ServiceReachability` from classes to the **public methods of money services** | D6 | M | A refusal is only as good as the path it names — the same lesson `VendorBillPayment` taught in §9 F1 |

**Two things this plan will not do, stated up front** so nobody adds them later thinking they were
forgotten:

- **It will not make the ledger append-only.** §2's derived model is arithmetically stronger and the
  gates depend on it. Everything above makes the derivation *visible and consented to*, not absent.
- **It will not add a notification per re-derive.** §9 F3 measured and declined that, and the reason
  still holds — it would fire on every late fee and every CAM run. D4's toast reaches the one person
  who caused it, at the moment they caused it, which is the case F3 said was uncovered.

**The gate this needs.** Every registry in this project has one, and this part adds the twenty-fifth
question a new money source must answer: *does it have a reversal, does that reversal record a reason,
and is its ledger panel mounted?* `ChangeImpactConformanceTest` already walks all 24 sources — the
three assertions belong there rather than in a new file.


---

## 15. What shipped, 2026-08-28 — and where §12–§13 were wrong

Phases 5–9 were built the same day the sweep was written. Everything below is in the code; the
corrections are recorded here rather than edited into §12 silently, because **the sweep's central
count was wrong and the way it was wrong is the most useful thing in this document.**

### 15.1 — The correction: "7 of 24 enforce it at the model" was undercounted

§12 counted guarded sources by grepping for `static::updating`. **`Payroll`, `Custody` and
`DepositTransaction` all guard in `saving`**, and all three had complete, better-reasoned freezes
already — written during their own module close-outs and pinned by their own tests:

| Source | The guard that already existed | Its predicate — better than the one this sweep proposed |
|---|---|---|
| `Payroll` | eleven fields frozen in `booted()` | `getOriginal('status') === 'approved'` |
| `Custody` | `amount`/`custody_date`/`paid_from` | **once settled against**, not on grant — a عهدة keyed wrong must stay fixable until it is spent |
| `DepositTransaction` | `hasBeenDrawnOn()` | asked of the **lease**, because the deposit is one pot per lease |

**Count a property by asking for it, not by grepping one spelling of it.** This is the same class of
error as the gates that went blind in `LoggedValuesResolveConformanceTest` and
`MorphMapConformanceTest` — a sweep reporting confidently on a set it was not collecting.

### 15.2 — Three of D1's promotions were refused by the code, correctly

`FixedAsset` and `MarketingSpend` were classified DERIVED **deliberately**, and both said so in
writing where the next person would look. Promoting them turned tests red within the hour:

- **`FixedAsset`** — re-costing an ACTIVE asset is a *supported* operation with its own guard
  (`DepreciationService::assertRecostValid()`, gap-analysis F-86). Freezing the cost turned a
  guarded correction into a dead end: the exact trap CLAUDE.md states for `#[NeverDeletable]` —
  *guarding a row a service legitimately writes breaks the workflow instead of protecting it.*
- **`MarketingSpend`** — `MarketingSpendStaysDerivedTest` carries a paragraph headed *"Why a posted
  spend is deliberately NOT frozen"*, distinguishing it from the disposed asset, the settled عهدة,
  the approved payroll and the drawn-on deposit. Editing one leaves no wrong number on the books.
- **`DepositTransaction`** — re-pointing an **undrawn** receipt to another lease re-derives its
  tenant and property and is pinned by `DepositTransactionIntegrityTest`.

All three reverted, each with the reason inline in `ChangeImpact::POLICY` so the next sweep does not
reach for them again.

**What D1 was RIGHT about:** the registry and the code disagreed — but in the opposite direction
from the one §12 assumed. `Payroll` and `Custody` were *guarded in code and classified DERIVED in the
registry*. Aligned now, which is what makes `ChangeImpactConformanceTest` a witness for them.

### 15.3 — What is in the code

| | Delivered | Where |
|---|---|---|
| **D3** ✅ | `App\Support\SealedPeriod` — one wildcard `eloquent.updating` seam over all 24 sources, refusing a change that would strand an entry in a closed period. `LedgerPoster::sealedPeriodBlocking()` is the authoritative predicate, built from the same `effectivePayload()` + `matches()` pair `sync()` uses, so it cannot drift from the engine. Mutation-proved: 5 controls hold, 2 refusals go red | `AClosedPeriodDocumentCannotBeRestatedTest` |
| **D2** ✅ | `Payment::REVERSED_STATUSES` and a real `voided` status. Voiding a receipt no longer tells the tenant their money was refunded. Existing `refunded` rows are deliberately not migrated — nobody can now say which were genuine | 6 assertions updated |
| **D5** ✅ | `App\Support\ReversalReason` + `ReversalReasonField` — every reversal records why, into the activity trail rather than an editable `notes`. **18 of the 24 sources have a named act** (14 distinct, since `reverse_document` serves four); the other 6 carry a reasoned `NO_REVERSAL` entry. Log name read off the model's own `getActivitylogOptions()`. *The sweep first counted 13 acts — it was counting only the ones that already existed.* | 24 new EN+AR keys |
| **D7** ✅ | All **62** raw-English refusals are `admin.refusals.*` keys in both languages, with field names resolved through `admin.fields.*` — so the refusal names the field the way the form does | `lang/{en,ar}/admin/refusals.php` |
| **D4** ✅ | `AnnouncesLedgerRestatement` on nine money Edit pages: the save toast says the entry will be re-posted, **with the figures**, and only when it will. `LedgerEntryAction` on those nine Edit **headers** — where the operator acts, not only on the list where they audit — plus the six remaining sources that have a screen (stock movements, owner-statement runs, disbursements, custody transactions, marketing spends, employee advances). **Twelve of the 24 have no admin screen at all**, so "all 24" in D4(B) was never reachable; the gate derives the requirement from Filament's own model→resource registry instead of a count | `AMoneyDocumentSaysWhatItDidToTheBooksTest` |
| **D6 / §12.4** ✅ | Bad-debt **recovery** now has a button. `ReverseDocumentAction` + `ReverseMoneyDocumentService` give `MarketingSpend`, `FixedAsset`, `Custody` and `EmployeeAdvance` a named reversal with a reason; the marketing spend's bare `DeleteAction` is gone | — |
| **D1** ◐ | Four genuinely unguarded sources locked via `RefusesRestatementOfCommittedMoney`, which reads `ChangeImpact::refusedFields()` so **the promotion IS the lock**. Three reverted (§15.2); two aligned to guards that already existed (§15.1) | `ChangeImpactConformanceTest`, green |

**The honest end state: 12 of 24 sources refuse a field, and every one of those refusals is proved**
by the gate dirtying it on a committed fixture. The remaining twelve are service-managed documents
with no operator form to type over — `SlaPenalty`, `DepreciationEntry`, `FixedAssetDisposal`,
`OwnerStatementRun`, `Disbursement`, `TenantCreditApplication`, `DepositApplication`,
`InvoiceWriteOff`, `StraightLineRentAdjustment`, plus the three of §15.2. Several are written by
services *after* commit (an SLA penalty is applied and detached; an owner-statement run is revised
into a superseding version), so locking them would break the workflow rather than protect it — the
same lesson §15.2 records, and the reason they were not swept in with the rest.

### 15.4 — Still open

~~**A `Reversals` registry with gate teeth**~~ ✅ — `App\Support\Reversals` (`ACTS` + `NO_REVERSAL`,
  each with a reason), gated on `ChangeImpactConformanceTest`: every source classified, every act
  named must exist in `app/Filament`, the shared reason field must stay required and capped, and
  every source with a screen must mount the ledger panel (derived from Filament's own model→resource
  registry, so it cannot miss what a hand-kept list omits).

  **One tooth is a threshold and the gate says so**: "every reversal reaches
  `ReversalReason::record()`" is asserted as a floor on call sites, not per act — an act NAME cannot
  be tied to the service method it calls by reading text, which the four drafts of the method-level
  reachability gate demonstrate. What holds per act is that each asks for the reason through the one
  factory. Recorded because a gate whose name overstates what it checks is how the next person comes
  to trust it too far.

~~**`ServiceReachability` at method level**~~ ✅ — and **it took four drafts, which is the finding.**
  Draft 1 searched for `->reverse(` anywhere: matched `SettleCustodyService`'s. Draft 2 narrowed to
  files NAMING the service: `EditInvoice` names four money services and calls `->reverse(` on two.
  Draft 3 bound the call through `app(X::class)`: both call sites held the service in a variable
  called `$svc`, so a call on either read as a call on both. Only draft 4 — requiring the variable to
  belong to ONE service in the file — goes red when the bad-debt recovery is unwired. **Every earlier
  draft was a green gate measuring nothing, on its own motivating example.** Mutation-proved, and it
  carries a vacuity assertion (21 methods across 13 money services when written).

~~**The before/after preview**~~ ✅ — `LedgerPoster::pendingRestatement()` is the sibling
  `wouldChange()` never had: it returns the FIGURES, from the same `effectivePayload()` + `matches()`
  decision, and returns null exactly when `wouldChange()` is false so the save toast and the ledger
  panel derive from ONE call and cannot tell two stories about one pending change.

  **Four shapes, four sentences**, because an operator acts differently on each: *post* (nothing
  posted yet), *reverse* (the document lost its effect), *reverse-and-re-post at a new figure*, and —
  the one worth having — **move to another month**. A date-only correction produces the same figure
  on both sides, so *"reversed EGP 1,000 and re-posted at EGP 1,000"* reads as a no-op and hides the
  only thing that moved. That case is exactly the one `matches()` was taught about `entry_date` for:
  one month's P&L understates and another overstates, no control account moves, and the AR/AP tie-out
  cannot see it. (`AMoneyDocumentSaysWhatItDidToTheBooksTest`, mutation-proved.)

**Nothing from §13 is now outstanding.** D1's scope is the one thing that ended smaller than proposed,
and §15.2 says why — three of its promotions were wrong and the code was right.

---

## 16. The UI sweep, 2026-09-05 — a status is the outcome of an act, and an act is on the record

§15 closed the question at the **model** layer: which fields a committed document refuses, which
re-derive, and what every reversal records. This section closes the half it did not ask — **what the
panel actually offers**. All 24 sources were swept for three things: can an operator type over a
posted document, is any state offered as a *value* rather than as the outcome of an act, and is the
act that corrects it where they are standing when they decide.

**What was already right, and is worth stating so nobody re-derives it.** 9 of the 24 have a
free-form Edit page; the other 15 are act-only. `Reversals::ACTS` gives 18 a named reversal and the
other 6 a written reason. All nine Edit pages announce a restatement. **No money document anywhere in
the panel has an inline-editable table column.** Five of the nine — vendor bill, expense, payroll,
deposit transaction, custody — keep `status` off the form entirely. That is Yardi's shape already.

### 16.1 — Two statuses were still pickable, and neither had an act behind it

| | What one click did | The act that should have done it |
|---|---|---|
| `invoices.status = 'disputed'` | Stopped collections on the **whole** document — `Invoice::NOT_CHASEABLE` is `['disputed','paid']`, so the overdue scan, the dunning sweep and the late-fee sweep all skip it — with no reason stored and no audit event, after which `CreditNoteService` refuses to credit it at all | `DisputeInvoiceItemService` — flags one **line**, requires a reason, records who and when, removes only that line's outstanding from the late-fee base. Already on the record page as `disputeLine` / `resolveDispute` |
| `payments.status = 'reconciled' \| 'settled'` | Nothing. The column documents them as *"matched in accounting"* and *"final"*, and `MatchBankStatementLineService` writes a `BankMatch` row and never touches this column | Bank reconciliation, when it is given a status to write |

**`disputed` is the fourth time this reasoning has been applied to `InvoiceForm`** — after
`cancelled`, `written_off` and `credited`, two of whose own comments record that the rule *"was never
generalised"* to the next one. `DisputeInvoiceItemService`'s docblock had already stated in writing
that the header status is the wrong tool, *because an invoice is rarely disputed in full*: the
argument is over the service charge while the rent on the same document is undisputed and
collectable. **Nothing in `app/` or `database/` ever wrote either value** — the dropdown was the only
door onto both.

**The vocabulary stays in `ValueSets` and in both lang files.** `RECEIVED_STATUSES` must go on
counting a `reconciled` receipt as money in, `NOT_CHASEABLE` must go on honouring a legacy
`disputed` row, and Filament validates a Select by resolving the submitted value's label — so a
record already carrying one has to stay renderable and saveable, with a way back beside it. Removing
an option without that fallback refuses every save of such a record on a field the operator never
touched. (`APostedDocumentsStatusIsNotAPickerTest`, mutation-proved.)

### 16.2 — A factory hides its `->action()` in another file, and the gate could not see it

`RowActionPolicy` derives a write verb from `->action(` appearing in the row action's chain — but
this app's two shared money-action factories are one-line call sites with the closure in
`app/Filament/Actions/`. Measured before the fix:

```
InvoicesTable.php       verbs=[]  reads=[View, Edit, downloadPdf, PostMonth, LedgerEntry, paymentLink]
VendorBillsTable.php    verbs=[]  reads=[PostMonth, LedgerEntry, View, Edit]
CustodiesTable.php      verbs=[]  reads=[View, Edit, ReverseDocument]
FixedAssetsTable.php    verbs=[]  reads=[View, Edit, ReverseDocument]
```

`InvoicesTable` reported **zero write verbs** while carrying *Post to month* — the act that re-posts
a live AR document into a different accounting period, the most consequential thing on that screen
after the void. `FixedAssetsTable` carried a banner reading *"The list FINDS; the record ACTS"*
directly above the reversal it kept in the row. Four tables passed a gate that could not see them.

A factory is now resolved to its own file and classified by **its** source. Two things about that:

- **Comments are stripped first.** `LedgerEntryAction`'s docblock says *"Read-only —
  `modalSubmitAction(false)` and no `->action()`"*, so a raw `str_contains` classified the one
  read-only affordance in that folder as a destructive verb on four tables at once. Two conformance
  gates here have already been weakened by firing on a sentence.
- **`handsBackAFile()` is deliberately not applied** to a whole file the way it is to one chain: a
  factory that both acts and mentions a download anywhere would be waved through, which is the false
  *negative* direction. No factory streams a file today; one that did would be flagged and get an
  `IN_ROW_EXCEPTIONS` entry — noisy and safe rather than quiet and wrong.

All four verbs moved onto their record pages, and **reachability was measured, not assumed**: each
gates on the same `{module}.edit` permission `canEdit()` requires to open the page, so no role loses
the act — the check that kept four other resources' verbs in the row (§`RowActionPolicy`).
`postToMonth` also joined `EditInvoice::HEADER_GROUPS`, because an act missing from a grouped
header's map is defined and rendered **nowhere**.

### 16.3 — A guarded field must LOOK guarded, and a DERIVED one must say so

- **`DepositTransactionForm` locked on `status !== 'recorded'`** — i.e. it froze a *cancelled*
  deposit and left a live one wide open — while the model refuses on `hasBeenDrawnOn()` and
  `finalAccountIsSettled()`. A receipt already netted against arrears rendered every field enabled;
  the operator retyped the amount and the model answered with a refusal toast on submit. The form now
  mirrors both model freezes on exactly the columns each one names, memoised per record because both
  predicates are queries and Filament evaluates `disabled()` on every render pass. `ExpenseForm`
  states the house rule beside its own `$moneyLocked`: **the same predicate on both layers.**
- **`FixedAssetForm` had no lock and no notice.** `acquisition_date` *is* the acquisition entry's
  `entry_date` and `funded_from` decides which account its credit leg hits. Both stay editable — the
  model deliberately permits it (§15.2) and a form stricter than its model is the deposit divergence
  in the other direction — but each now states the consequence, because DERIVED's own definition ends
  *"the operator must be told"* and the toast tells them at the wrong end of the decision.
- **`MarketingSpend` was edited from a relation-manager modal, which announced nothing.**
  `AnnouncesLedgerRestatement` hooks `getSavedNotification()`, an `EditRecord` method, so it reaches
  the nine money Edit **pages** and no modal. `App\Support\Filament\LedgerRestatement` is the wording
  extracted so both surfaces read one sentence; a second copy in the relation manager is the drift
  this codebase keeps recording.

(`AnActOnAPostedDocumentIsWhereItCanBeSeenTest`, six mutations, each killing its own tooth.)

### 16.4 — The follow-up, reported from the panel: which VALUES vs whether it is a FIELD

§16.1 removed the two statuses with no act behind them and stopped there. **It did not ask whether
the status should still be a control**, and on a PAID invoice (INV-VP-0002) it still rendered as an
open dropdown with a clear button — so an operator could take a settled invoice back to `issued`, or
blank a required field. With the six derived and act-owned values gone, a raised invoice's list is
down to the one it is already in, so the control offered nothing and risked something. It is
`disabled($locked)` now, mirroring `issue_date` beside it; raising a draft is untouched, and that is
the one status decision a person makes on this form.

The same pass left three date fields typeable on a posted, paid document, and **`ChangeImpact`
marking them NEUTRAL is precisely why they were missed.** NEUTRAL is a statement about the LEDGER —
*"never reaches a journalizer payload and never decides a period"* — and it is correct. It is not a
statement about money:

| Field | What reads it | Consequence of re-dating a paid invoice |
|---|---|---|
| `period_start` | `SyncCamPoolFromLedgerService` narrows the pool's billed side **and** its credited-back twin on it | The invoice moves into or out of a CAM pool, changing the annual true-up billed to **every participant** |
| `period_start` + `period_end` | `CreditUnearnedBillingService` both SELECTS the invoices a move-out credits and APPORTIONS the credit from them | Outbound cash on a move-out |
| both | `Invoice::periodLabel()` on the PDF, `TenantLedger` on the statement, `InvoiceResource` on the API | The tenant's filed document disagrees with the register |

Both are `disabled($locked)` — a wrong period on an issued invoice is a wrong document, and the
remedy is the void or credit that every other money form here already points at. **The registry was
not changed**: NEUTRAL answers the GL question and answers it right. What was wrong was a form
reading "no ledger consequence" as "no consequence".

`due_date` is deliberately the exception and gets its own predicate. Extending a due date as a
one-off concession is an ordinary AR act on an open charge — Yardi allows it, and the field's own
helper says *"override only for a one-off arrangement"* — so it stays editable while the receivable
is live and closes once money has landed **or the document has left the books**, where all it can do
is rewrite the ageing history the owner reads. `$settled` reads `paid_amount > 0` **or**
`! InvoiceSettlement::accepts()`, because a cancelled or written-off invoice has taken no money and a
`paid_amount` test alone would leave it open; the relieved half comes from the register that already
answers it rather than a second list of statuses. Mutation-proved four ways **including the
over-lock** — a draft must stay entirely open, or the fix breaks the only workflow these fields exist
for. (`APostedDocumentsStatusIsNotAPickerTest`.)

**The lesson for the next sweep of this kind:** *which values a control offers* and *whether it
should be a control* are two questions, and answering the first reads as finishing the job.

---

## 17. The closure plan (SW-240) — every money form closed once posted, every state an act

§16 fixed the doors a panel report happened to find. This section is the RULE, planned from a
runtime audit rather than from reading forms: all nine money Edit pages were MOUNTED on a committed
fixture (issued invoice · captured-and-allocated payment · issued credit note · approved bill ·
recorded expense · approved payroll · drawn-on deposit receipt · active fixed asset · settled-against
custody), every form component walked, and its real `disabled`/`readOnly` state read back and
cross-referenced with `ChangeImpact`. Reading source lies here — `->readOnly()` fields report as
enabled to `isDisabled()`, and the first pass of this very audit called Payroll's `net_paid` a hole
on exactly that false positive.

### 17.1 — The measured baseline, 2026-09-05

**Already closed, and worth saying so nobody re-derives it:** Payroll (zero open fields), Custody
(memo fields only), VendorBill (`facility_work_order_id` only — deliberate: re-pointing a cost
between jobs is the cost-object dimension, and `recomputeCosts()` runs on the job it LEFT),
CreditNote (reason/notes only), Invoice (due-date-by-design + notes), Expense (`expense_date`
DERIVED-by-design + memo fields). FixedAsset and the MarketingSpend modal are open BY DESIGN
(§15.2), announced and hinted.

**Genuinely open, with a verdict each:**

| Surface | Field(s) | Why it matters | Disposition |
|---|---|---|---|
| Payment (captured) | `status` | A one-option enabled Select with a clear button — the §16.4 shape on the OTHER AR document. Worse on an `initiated` payment: picking `captured` **posts cash to the GL** through a bare dropdown, with no confirmation and no act | **Phase 1** |
| Payment (captured) | `gateway`, `gateway_transaction_id` | The Paymob callback FINDS the payment by this column and DEDUPES on the `:txn:{id}:` marker inside it (`CallbackController`). Editing it on a live gateway payment can orphan the capture (money taken, never recorded) or strip the marker so a replayed callback **captures twice** — the double-charge class `ConcurrencyPolicy` exists for. GL-NEUTRAL and not money-neutral: §16.4's lesson again | **Phase 1** |
| Payment (captured) | `cheque_number`, `cheque_clearance_date` | The paper instrument's identity — evidence of what was received | **Phase 1** |
| CreditNote (draft) | `status` offering `issued` | **Two doors, unequal.** The `issue` ACT gates on `credit_notes.issue`, confirms, and runs `CreditNoteService::issue()`; the dropdown needs only `credit_notes.edit` — an operator without the issue right can issue by picking a value. The posting-date guard is NOT bypassed (`CreditNote::updating` re-asserts it), so this is an authz gap, not a books gap | **Phase 4** |
| Deposit (drawn-on) | `is_opening_balance` | **A MODEL gap, not a UI one.** The receipt freeze's dirty-list names amount/parties/date/type/status and not this flag — yet flipping it on a drawn-on receipt voids the posted `Cr Deposits Held` while the applications' debits stand, the exact negative-pot class the freeze exists for | **Phase 3** |
| Deposit (drawn-on) | `bank_account_id`, `method` | Defensible: the model deliberately leaves the RAIL correctable (it does not change what the pot is made of), the page announces the re-derive | register open-with-reason |

### 17.2 — The rule, in Yardi's frame

Voyager: posted = closed; corrections are reversals and credits; state is the outcome of a posting
act. Atriom's DERIVED tier (edit → announced void-and-repost, while the entry is not yet evidence)
is deliberately looser and is a RECORDED decision (§13, §15) — the plan does not reopen it. The UI
rule that follows, enforceable per clause:

1. **A REFUSED field renders disabled on a committed record.** Same predicate on both layers — the
   deposit form divergence (§16.3) is the defect class this clause ends.
2. **A DERIVED field renders disabled unless registered open-with-a-reason, and only on a surface
   that announces the restatement.**
3. **`status` is never an enabled control on a committed record, on any source, no exemptions.**
   First-state transitions (draft → issued) are acts or the create-time choice.
4. **Evidence identifiers lock when the money is received even where GL-NEUTRAL** — the gateway
   pair, the cheque pair. This clause cannot be derived structurally, so it is one-off fixes with
   regression tests rather than gate material.

### 17.3 — Phase 1: Payment

`status` → `disabled($locked)`. A new **`capture`** header act for `initiated` payments — the only
manual transition left, and it posts cash: confirmation modal, gated on `payments.edit` in both
`visible()` and `action()`, routed through a small service (assert initiated, capture, sync),
announcing via `LedgerRestatement::noticeFor()`. The audience is real but rare: a gateway session
that died mid-flight whose money genuinely arrived, confirmed against the bank statement. The four
evidence fields → `disabled($locked)`. **Sequencing note: another session is mid-flight in
`Payment.php` and `CallbackController.php` — this phase lands after their commit.**

### 17.4 — Phase 2: the gate, so the next form cannot ship open

The systemic piece — nothing today stops a tenth money form shipping wide open.

- `committedFixtures()` moves from `ChangeImpactConformanceTest`'s file scope to
  `Tests\Support\CommittedMoneyFixtures` (a class, not file-scope helpers — a parallel worker loads
  only its own files, and redeclaration is fatal). The change-impact gate keeps its completeness
  tooth; the new gate reuses the same fixtures so the two cannot disagree about what "committed" is.
- `AMoneyFormIsClosedOnceCommittedConformanceTest`: derives the swept set from
  `LedgerPoster::sources()` × Filament's model→resource registry (never a hand list), MOUNTS each
  Edit page — and each GL source's relation-manager edit modal, today MarketingSpends — on its
  committed fixture, and enforces clauses 1–3. Exemptions live in a registry with a reason each and
  a stale-entry tooth; `readOnly` counts as closed. Mutation-proof: unlock one refused field, gate
  goes red.

### 17.5 — Phase 3: `is_opening_balance` joins the deposit freeze

Model first (join both dirty-lists — the receipt freeze and the settled-account freeze), then the
form (`disabled($frozen)`), then the regression pair: refusal on a drawn-on receipt, control that an
UNDRAWN receipt's flag stays correctable — the over-lock is the trap, exactly as §16.4's draft case.

### 17.6 — Phase 4: one door to an issued credit note

`status` → `disabled()` always; the `issue` act — with its own permission, confirmation and service
— is the door. The Select keeps rendering the record's own translated label, which is all it was
doing correctly anyway.

### 17.7 — Three decisions that are the operator's, stated rather than assumed

- **D-A — invoice draft → issued.** Today the Select IS the sanctioned door (recorded in SW-215:
  "the panel's Select is the other door"). Full Yardi alignment is an explicit **Issue** act, which
  also enables an `invoices.issue` permission split like the credit note's. Recommended — one rule
  across both AR documents — but it reverses a recorded decision, so it is asked, not assumed.
- **D-B — `expense_date` stays editable-with-announcement.** Voyager would refuse and book the
  correction into the current post month; Atriom chose original-period-if-open (§13 D-6). Restated
  here so it is a choice being kept, not a hole being inherited.
- **D-C — `reason` on an issued credit note.** A classification on a delivered document. Yardi keeps
  memo open and classification closed; recommended `disabled($locked)`, low stakes either way.

**Order: 2 → 3 → 4 → 1 → D-decisions.** The gate lands first so every later phase is proved by the
gate going green rather than by hand; Payment last because of the concurrent session.

### 17.8 — What shipped, 2026-09-05, same day

All four phases plus D-A and D-C, in the planned order (gate first, so the later phases were proved
by the gate rather than by hand). D-B stands as the recorded decision it was.

| | Delivered | Proof |
|---|---|---|
| **P2** ✅ | `Tests\Support\CommittedMoneyFixtures` — the refusal fixtures moved out of `ChangeImpactConformanceTest`'s file scope (contract unchanged, its completeness tooth still green) plus a `uiFixtures()` set built under ONE property, because a committed fixture and a MOUNTABLE one are different constraints: the refusal set's bare captured payment is committed and invisible to its own Edit page, which scopes through allocated invoices. `App\Support\MoneyFormPolicy::OPEN_WHILE_DERIVED` is the exemption register — eleven entries, each pointing at the decision it restates. `AMoneyFormIsClosedOnceCommittedTest` enforces §17.2's three clauses on every mounted Edit page, derived from `LedgerPoster::sources()` × the panel's resource registry | five mutations, each red with the message naming the defect |
| **P3** ✅ | `is_opening_balance` joined BOTH deposit freezes' dirty-lists — the receipt freeze and the settled-account freeze — and the form's `$frozen`. The flag decides whether the pot was ever BOOKED, so flipping it on a drawn-on receipt was the amount-edit hole worn as a checkbox | refusal + the undrawn over-lock control, mutation-proved |
| **P4** ✅ | One door to an issued credit note: the Select is a display on any SAVED note (create keeps the born-state choice), and the `issue` act — its own permission, confirmation, service — is the door. The dropdown had let a `credit_notes.edit` holder issue without `credit_notes.issue`; measured first: the only role granted edit also holds issue, so nothing lost reach | driven through the real page |
| **P1** ✅ | Payment: the status Select is a display on any existing payment; `initiated → captured` — the transition that POSTS CASH — is the **Capture** header act through `CapturePaymentService` (posting-date asserted in the service, confirmation states the consequence). The four evidence fields lock on exists: the gateway pair is the Paymob callback's lookup + idempotency key, the cheque pair is the paper's identity (an uncleared cheque's home is the PDC register) | act driven; sweep-before/act/sweep-after proves the books move with the act and only then |
| **D-A** ✅ | Issuing a draft invoice is the **Issue** act (`IssueInvoiceService::raise()` — refuses a non-draft and a no-line draft, asserts the posting date, saves un-quietly so the ladder and the sync fire). Gated on `invoices.edit`, deliberately: exactly what the Select door required, so no role's reach moved; an `invoices.issue` permission split is a roles-matrix decision left open. This REVERSES part of SW-215's "the panel's Select is the other door" — the Select remains the door only at CREATE, where draft-vs-issued is the born state | act → sweep → AR entry exists; the empty-draft and non-draft refusals in the reader's words |
| **D-C** ✅ | `credit_notes.reason` locks once issued — a classification on a delivered document; `reason_notes` and `notes` stay open, because they are the memo | paired with the memo control |

**Two test corrections worth recording.** The acts' GL assertions first read `postedEntryFor()`
straight after `callAction()` and got null — the realtime sync rides the queue and a test
transaction never commits, so the sweep is the honest reader (`accounting:sync-ledger --all` before
as the control, after as the proof; the idiom `AMoneyDocumentSaysWhatItDidToTheBooksTest` already
uses). And the older regression's "a draft is entirely open" control deliberately CHANGED under
D-A: period and due date stay typeable on a draft, `status` does not — its door is the act.

### 17.9 — The adversarial review, and what it caught (the loop earning its keep again)

A review agent was told to read the change set and break it. It found **one FATAL, three
needs-change and five factual corrections** — every one past a fully green targeted suite, which is
the same lesson the 2026-09-01 sweep recorded: *the review is worth more than the hunt.*

- **FATAL — `CapturePaymentService` was a bare status flip.** An initiated allocation is invisible
  to every settlement sum (`RECEIVED_STATUSES` filters it out), so between the gateway session
  dying and the operator capturing, the invoice can be settled by any other channel — and the flip
  then relieved AR a second time, `paid_amount` doubled, negative AR buried: the four-channel
  invariant, verbatim, plus the checked-at-lodging-never-at-clearing shape. The service now takes
  the canonical invoices-then-self locks, re-checks `initiated` UNDER the lock (it also races the
  callback committing `failed`), and runs BOTH payment guards — with a three-step save whose order
  is load-bearing: quiet flip (so the guards' sums see this payment), guards (a refusal rolls back
  with nothing fired), clean save (hooks — recompute, receipt notification, ledger sync — fire
  once, only on success). Refuses rather than clamps, because unlike the gateway no money is in
  hand yet.
- **The tree held THREE standing test files still asserting the removed contract**
  (`FinalizedDocumentLockTest`, `AVoidIsAnActNotACreditNoteStatusTest`,
  `ACreditedIsAnOutcomeNotAStatusYouPickTest`) — two of them red against main since the SW-238
  commits, missed because the neighbour sweep ran the files the fixes named rather than the files
  that named the fixes. All three updated to the acts they now document.
- **The deposit form half was proved by nothing** — reverting `$frozen` left every test green,
  because the model regression drives `update()` and the gate's deposit fixture is deliberately
  undrawn. A form-mount case on a drawn-on receipt closes it.
- **The cheque dead end**: an initiated cheque payment could never record its clearance date. The
  date is the one instrument field that is a fact that ARRIVES rather than identity — open until
  received or reversed now (type the date, then Capture), locked after.
- **`raise()`'s comment claimed a ladder nothing called** — it now calls `recomputeTotals()`, and
  the regression asserts a past-due draft issues straight to `overdue`, which is what proves it.
- Plus: the miscounted roles sentence (four roles hold `credit_notes.edit`, not one — the
  conclusion survived), the "nothing writes the gateway pair" overclaim, the settled-list flag
  honestly labelled unprovable belt-and-braces, and the gate's whole-sweep field floor made
  per-model (109 fields across nine forms clears a global floor with one form gone).

All nine findings fixed and the four behavioural ones mutation-proved (R1–R4). Every suite the
review ran red is green.
