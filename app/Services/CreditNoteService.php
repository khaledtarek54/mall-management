<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\Invoice;
use App\Support\InvoiceSettlement;
use App\Support\PostingDate;
use App\Support\ReversalReason;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreditNoteService
{
    /**
     * Issue a draft credit note (status -> issued). Sets balance = total - applied_amount.
     * Idempotent on issued/applied status.
     */
    public function issue(CreditNote $note): CreditNote
    {
        if ($note->status === 'issued' || $note->status === 'applied') {
            return $note;
        }

        return DB::transaction(function () use ($note) {
            // Issuing is a GL event (the journalizer posts Dr Sales Returns / Cr AR dated issue_date):
            // refuse a date whose accounting period is closed, else the AR effect commits while the
            // ledger post is silently rejected — the divergence every money service guards against.
            // (The totals are already item-derived: a form note is recomputed from its items on save;
            // a programmatically-created note sets its totals directly and has no items to derive from.)
            PostingDate::assertOpen($note->issue_date, __('admin.fields.issue_date'));

            $note->balance = (float) $note->total - (float) $note->applied_amount;
            $note->status = $note->balance > 0 ? 'issued' : 'applied';
            $note->save();

            return $note->refresh();
        });
    }

    /**
     * Apply (some of) a credit note's remaining balance to one invoice. Updates both sides
     * atomically and records a reversible CreditNoteApplication. Caps at min(note.balance,
     * invoice.balance, requestedAmount). Returns the actual amount applied (0 = nothing applied).
     *
     * @throws \DomainException on a cross-tenant or cross-property target (fail-closed backstop).
     */
    public function applyToInvoice(CreditNote $note, Invoice $invoice, ?float $requestedAmount = null): float
    {
        return DB::transaction(function () use ($note, $invoice, $requestedAmount) {
            // Lock BOTH rows and re-read their state INSIDE the txn. Two concurrent applies would
            // otherwise each observe the same pre-state and both commit their full amount —
            // over-applying the credit. Mirrors the payment path's lock-safe guard.
            //
            // THE INVOICE FIRST, THEN THE NOTE (SW-009c/d/e canon, deadlock proven on MySQL
            // 2026-09-05). Every REVERSE path here runs invoice→…→note (`reverseAppliedCredit`
            // from `Invoice::updated` holds the invoice, then locks applications then notes;
            // `reverseAllApplications`/`reverseApplication` lock the invoice before the note), so
            // this was the ONE path acquiring note→invoice — a void racing an apply on the same
            // pair met it crosswise and MySQL killed one with 1213. Invoice-first everywhere now.
            $invoice = Invoice::query()->lockForUpdate()->find($invoice->id);
            $note = CreditNote::query()->lockForUpdate()->find($note->id);

            // hasBalance() = balance > 0 AND status in [issued, applied] — blocks draft / void /
            // fully-applied notes (re-checked under the lock).
            if (! $note || ! $invoice || ! $note->hasBalance()) {
                return 0.0;
            }

            // A credit note settles ONE tenant's AR — never pay down another tenant's invoice with
            // it. The apply picker filters by tenant, but a crafted dispatch can submit any id, so
            // this is the real (fail-closed) gate. Mirrors Payment::assertInvoicesShareTenant().
            if ((int) $note->tenant_id !== (int) $invoice->tenant_id) {
                throw new \DomainException(__('admin.notifications.credit_note_apply_cross_tenant'));
            }

            // Property binding: a note's single GL entry (Dr Sales Returns) is attributed to ONE
            // property. Bind an unscoped (standalone) note to the target invoice's property on first
            // apply so the contra-revenue lands in that property's books (else its owner is paid a
            // share on revenue that was credited back). Once bound, it can only settle that property's
            // invoices — keeping the note's returns single-property and its owner statement honest.
            // Read from the DENORMALIZED columns, which is what the binding twenty lines below has
            // always read. Until 2026-08-18 this guard resolved both sides through `lease->unit`
            // instead, and a unit owner's assessment has no lease (module 37 bills the ownership) —
            // so both sides came back null, the `!== null` preconditions were false, and the check
            // was SKIPPED. A fail-closed guard that failed open for exactly the documents it could
            // not see: mall A's note settled mall B's owner assessment, putting the contra-revenue
            // in the wrong property's books and paying its owner a share on revenue credited back.
            //
            // The note's null case is still honoured because it is real — a standalone note with no
            // invoice and no lease has nothing to derive from and binds on first apply, below. The
            // invoice's is not: `invoices.asset_id` has been required since phase 2a.
            $invoiceAssetId = $invoice->asset_id;
            $noteAssetId = $note->asset_id;
            if ($noteAssetId !== null && (int) $noteAssetId !== (int) $invoiceAssetId) {
                throw new \DomainException(__('admin.notifications.credit_note_apply_cross_property'));
            }

            $available = (float) $note->balance;
            // `settleableAmount()`, not the raw balance: on a PARTIAL write-off the balance still
            // stands for the forgiven part, and a credit note applied there would relieve AR the
            // bad-debt entry already relieved — the same double-relief the cash channel was fixed
            // for, through the one door that still capped at `balance`. `glTieOut()` would not
            // catch it, because it subtracts the written-off amount either way.
            $owed = InvoiceSettlement::settleableAmount($invoice);

            if ($available <= 0 || $owed <= 0) {
                return 0.0;
            }

            // The shared floor first: an invoice whose AR has already been relieved takes nothing
            // from any channel. That is what was missing — `written_off` and `draft` reached this
            // through the old allowlist's edges.
            if (! InvoiceSettlement::accepts($invoice)) {
                return 0.0;
            }

            // …and then this channel's OWN, narrower rules, which are deliberate and are NOT
            // disagreements to be flattened.
            //
            // DISPUTED: a tenant may PAY a disputed invoice — that is their choice, and `PaymentForm`
            // has always allowed it. An operator applying CREDIT to one is a different act: it spends
            // a credit note against an amount still being argued about, and if the dispute resolves
            // downward that credit went on money never owed. Resolve the dispute, then apply.
            //
            // PAID: a credit note reduces what is OWED, and nothing is. Money already received comes
            // back by voiding or refunding the RECEIPT, not by consuming a credit note against it.
            // The floor calls `paid` live because a reversal re-opens it and every channel caps at
            // `settleableAmount()` — but that cap only protects a row whose `balance` agrees with its
            // status, and this one does not have to: measured, an invoice can carry `status = paid`
            // with a standing balance, and dropping this clause let a 3,000 note apply to it.
            if (in_array($invoice->status, ['disputed', 'paid'], true)) {
                return 0.0;
            }

            $amount = $requestedAmount === null
                ? min($available, $owed)
                : min($available, $owed, (float) $requestedAmount);

            if ($amount <= 0) {
                return 0.0;
            }

            // Adopt the invoice's property (and its lease, when it has one) if the note is still
            // unscoped — a standalone note is raised before anyone knows what it will credit.
            //
            // The property is copied SEPARATELY from the lease, and that is the whole correction
            // here: this used to copy only `lease_id`, justified by "an invoice always has a lease —
            // lease_id is NOT NULL". That stopped being true when unit owners became billable, so a
            // note against an owner assessment inherited a NULL lease and, through it, no property
            // at all. `asset_id` is populated on every invoice by construction, so it is the answer
            // that always exists.
            if ($note->asset_id === null) {
                $note->asset_id = $invoice->asset_id;
            }

            if ($note->lease_id === null) {
                $note->lease_id = $invoice->lease_id;
            }

            // Adjust the credit note side (capped at $available → never negative).
            $note->applied_amount = (float) $note->applied_amount + $amount;
            $note->balance = (float) $note->total - (float) $note->applied_amount;
            $note->status = $note->balance > 0 ? 'issued' : 'applied';
            $note->applied_at = $note->applied_at ?? now();
            // Record the application BEFORE saving the note. The application rows are the TRUTH
            // about how much of a note is spent, and `CreditNote::updating` now holds
            // `applied_amount` to their sum — so the row has to exist by the time the note is
            // written or the guard would correct a legitimate application back down. Same
            // transaction and the note is already locked, so nothing can read the in-between.
            CreditNoteApplication::create([
                'credit_note_id' => $note->id,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'applied_at' => now(),
                'created_by' => Auth::id(),
            ]);

            $note->save(); // a newly-bound lease re-syncs the note's GL entry to the invoice's asset

            // Record the applied credit durably (credit_applied_amount) so Invoice::recomputeTotals —
            // which otherwise sums only the payments pivot — folds it into paid_amount/balance/status,
            // keeping a later payment recompute from erasing the credit.
            $invoice->credit_applied_amount = (float) $invoice->credit_applied_amount + $amount;
            $invoice->recomputeTotals();

            return $amount;
        }, 3); // retry on deadlock: apply locks note→invoice, cancel locks invoice→note (opposite order)
    }

    /**
     * Un-apply every credit note applied to an invoice that is being cancelled / credited. The
     * invoice no longer collects, so its applied credit must return to the tenant as available.
     *
     * We RESTORE each note's balance (soft-deleting the application row) rather than issuing a new
     * offsetting note: the note's original Dr Sales Returns / Cr AR entry already sits in the GL and
     * now correctly represents the returned, available credit. Issuing a second note (the old
     * behaviour) posted a SECOND sales-return — double-counting the return and driving AR negative.
     */
    public function reverseAppliedCredit(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            foreach (CreditNoteApplication::where('invoice_id', $invoice->id)->lockForUpdate()->get() as $app) {
                $note = CreditNote::query()->lockForUpdate()->find($app->credit_note_id);
                if ($note) {
                    $note->applied_amount = max(0, round((float) $note->applied_amount - (float) $app->amount, 2));
                    $note->balance = round((float) $note->total - (float) $note->applied_amount, 2);
                    if ($note->balance > 0 && $note->status === 'applied') {
                        $note->status = 'issued'; // available again
                    }
                    if ($note->applied_amount <= 0) {
                        $note->applied_at = null;
                    }
                }

                // Deleted BEFORE the note is written, for the same reason the create moved above
                // it: the rows are what `CreditNote::updating` measures `applied_amount` against.
                $app->delete();

                if ($note) {
                    $note->save();
                }
            }

            $invoice->credit_applied_amount = 0;
            $invoice->recomputeTotals(); // saveQuietly inside — keeps 'cancelled' / 'credited' status
        });
    }

    /**
     * Guided reversal of an APPLIED note (the operator "un-applies" it): restore the note to
     * available and re-open every invoice it had settled. The note's own GL entry is untouched
     * (it stays a valid, now-unapplied, sales return). This is the supported way to undo an
     * application — voiding an applied note is refused; you reverse it here or issue an offsetting one.
     *
     * Returns the total amount un-applied.
     */
    public function reverseAllApplications(CreditNote $note, ?string $reason = null): float
    {
        return DB::transaction(function () use ($note, $reason) {
            // Canon: invoices → note → applications (SW-009d) — never note-first, which is what
            // this was and what crossed `applyToInvoice`/`reverseAppliedCredit` into a deadlock.
            // The invoice ids come from an UNLOCKED read so they can be locked FIRST, ascending, so
            // two whole-note reversals over overlapping invoice sets cannot deadlock each other.
            $invoiceIds = CreditNoteApplication::where('credit_note_id', $note->id)
                ->orderBy('invoice_id')->pluck('invoice_id')->unique()->values()->all();

            $invoices = Invoice::query()->whereIn('id', $invoiceIds)->orderBy('id')
                ->lockForUpdate()->get()->keyBy('id');

            $note = CreditNote::query()->lockForUpdate()->find($note->id);
            if (! $note) {
                return 0.0;
            }

            $reversed = 0.0;
            foreach (CreditNoteApplication::where('credit_note_id', $note->id)->lockForUpdate()->get() as $app) {
                $invoice = $invoices->get($app->invoice_id)
                    ?? Invoice::query()->lockForUpdate()->find($app->invoice_id);
                if ($invoice) {
                    $invoice->credit_applied_amount = max(0, round((float) $invoice->credit_applied_amount - (float) $app->amount, 2));
                    $invoice->recomputeTotals(); // re-opens the invoice's AR
                }
                $reversed += (float) $app->amount;
                $app->delete();
            }

            // DERIVE the reset from the rows actually reversed — never blindly zero. A note with
            // applied credit but NO application rows (e.g. applied before this table existed, or a
            // factory ->applied() fixture) would otherwise be made fully available while its invoices
            // keep the credit → the same credit re-applies elsewhere = double-count. With no rows this
            // is a safe no-op; the backfill migration gives legacy applied notes their rows.
            $note->applied_amount = max(0, round((float) $note->applied_amount - $reversed, 2));
            $note->balance = round((float) $note->total - (float) $note->applied_amount, 2);
            $note->status = $note->balance > 0 ? 'issued' : 'applied';
            if ((float) $note->applied_amount <= 0) {
                $note->applied_at = null;
            }
            $note->save();

            // Un-applying a credit re-opens somebody's receivable. Recorded even when nothing moved,
            // because "the operator pressed reverse and it turned out there was nothing applied" is
            // itself the answer to a question someone will ask.
            ReversalReason::record($note, 'reversed', $reason);

            return round($reversed, 2);
        }, 3); // retry on deadlock (locks note→invoice vs the cancel path's invoice→note)
    }

    /**
     * Un-apply ONE application (a single note→invoice link) — the granular counterpart to
     * reverseAllApplications, so an operator can undo one invoice's credit without reversing the whole
     * note. Restores that invoice's AR and decrements the note's applied balance; the note's own GL
     * entry (the sales return) is untouched. Returns the amount un-applied.
     */
    public function reverseApplication(CreditNoteApplication $application): float
    {
        return DB::transaction(function () use ($application) {
            // Canon: invoice → application → note (SW-009d). Every path in this file locks these
            // three tables in this order — `reverseAppliedCredit` must, because it runs from
            // `Invoice::updated` already holding the invoice, and a total order over all three is
            // the only thing that stops any two crossing. Read the ids off the passed row (unlocked)
            // to lock the invoice FIRST, then re-read the application under its own lock.
            $invoice = Invoice::query()->lockForUpdate()->find($application->invoice_id);

            $application = CreditNoteApplication::query()->lockForUpdate()->find($application->id);
            if (! $application) {
                return 0.0;
            }

            $amount = (float) $application->amount;

            if ($invoice) {
                $invoice->credit_applied_amount = max(0, round((float) $invoice->credit_applied_amount - $amount, 2));
                $invoice->recomputeTotals(); // re-opens this invoice's AR
            }

            $note = CreditNote::query()->lockForUpdate()->find($application->credit_note_id);
            if ($note) {
                $note->applied_amount = max(0, round((float) $note->applied_amount - $amount, 2));
                $note->balance = round((float) $note->total - (float) $note->applied_amount, 2);
                $note->status = $note->balance > 0 ? 'issued' : 'applied';
                if ((float) $note->applied_amount <= 0) {
                    $note->applied_at = null;
                }
            }

            // Before the note is written — see the note on the create in applyToInvoice().
            $application->delete();

            if ($note) {
                $note->save();
            }

            return round($amount, 2);
        }, 3);
    }

    /**
     * Void a credit note. Cannot void an APPLIED one (reverse it first, or issue an offsetting note).
     */
    public function void(CreditNote $note, ?string $reason = null): CreditNote
    {
        if ($note->status === 'void') {
            return $note;
        }

        return DB::transaction(function () use ($note, $reason) {
            // Lock + re-read the note INSIDE the txn so a concurrent applyToInvoice() (which also
            // locks this row) can't slip an application in between the applied_amount guard and the
            // void — which would strand applied credit against a now-void note.
            $locked = CreditNote::query()->lockForUpdate()->find($note->id);
            if (! $locked || $locked->status === 'void') {
                return $locked ?? $note;
            }
            if ((float) $locked->applied_amount > 0) {
                throw new \DomainException(__('admin.notifications.credit_note_void_applied'));
            }

            $locked->status = 'void';
            $locked->voided_at = now();
            // `Voided:` rather than the shared `[VOID]` marker — this wording is already on every
            // credit note voided to date, and changing it would make the same act read two ways
            // across the register.
            $locked->notes = ReversalReason::stamp($locked->notes, $reason, 'Voided:');
            $locked->balance = 0;
            $locked->save();

            // The reason was stamped into `notes` alone until 2026-08-28, and `notes` is editable by
            // whoever can edit the note — so the record of why a credit note was voided could be
            // erased by the person who voided it. The trail is the copy that cannot.
            ReversalReason::record($locked, 'voided', $reason);

            return $locked->refresh();
        });
    }
}
