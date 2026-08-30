<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceWriteOff;
use App\Support\PostingDate;
use App\Support\ReversalReason;
use App\Support\Translate;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Accept that a receivable will not be paid — the instrument Atriom did not have.
 *
 * The two things an operator could do before were both wrong. **Cancelling** reverses the revenue
 * in the CURRENT period, including revenue earned and recognised in a prior year, so the year it
 * was actually earned is understated, this year is overstated, and the bad debt never appears as
 * bad debt. **Leaving it** means AR aging carries fiction forever and every collections figure
 * lies.
 *
 * A write-off keeps the revenue where it was earned, credits AR, and debits bad-debt expense —
 * dated at the decision. See `InvoiceWriteOffJournalizer`.
 */
class WriteOffInvoiceService
{
    /**
     * @param  array{amount?:float|null, entry_date?:string|\DateTimeInterface|null, reason:string, notes?:string|null}  $data
     */
    public function write(Invoice $invoice, array $data): InvoiceWriteOff
    {
        return DB::transaction(function () use ($invoice, $data) {
            // Lock and re-read: two operators writing off the same invoice must not each book the
            // full balance to bad debt. The same lock-and-recheck the payment allocation guard uses.
            /** @var Invoice $locked */
            $locked = Invoice::whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['cancelled', 'written_off', 'draft'], true)) {
                throw new DomainException(__('admin.refusals.write_off_not_live', [
                    'number' => $locked->number,
                    // The STATUS the operator reads on the screen, not the stored code — the same
                    // rule the lease immutability refusal follows.
                    'status' => Translate::orHumanized("admin.statuses.invoice.{$locked->status}", $locked->status),
                ]));
            }

            $outstanding = round((float) $locked->balance, 2);

            if ($outstanding <= 0) {
                throw new DomainException(__('admin.refusals.write_off_nothing_outstanding', [
                    'number' => $locked->number,
                ]));
            }

            // What is left to write off, NOT what is outstanding.
            //
            // A partial write-off deliberately leaves `balance` standing (see below), so `balance`
            // alone is not a cap — it is the same number on the second pass as the first. Write off
            // 5,000 of 20,000, then accept the modal's default of 20,000, and the invoice takes
            // 25,000 of bad debt against a 20,000 receivable: AR is credited 5,000 below the debt,
            // the invoice flips to `written_off` and is then EXCLUDED from the tie-out, leaving a
            // permanent −5,000 delta with no document behind it. Netting prior write-offs is what
            // makes the cap mean anything.
            $alreadyWrittenOff = $locked->writtenOffAmount();
            $remaining = round($outstanding - $alreadyWrittenOff, 2);

            if ($remaining <= 0) {
                throw new DomainException(__('admin.refusals.write_off_already_full', [
                    'number' => $locked->number,
                    'written' => number_format($alreadyWrittenOff, 2),
                    'outstanding' => number_format($outstanding, 2),
                ]));
            }

            $amount = filled($data['amount'] ?? null)
                ? round((float) $data['amount'], 2)
                : $remaining;

            if ($amount <= 0) {
                throw new DomainException(__('admin.refusals.write_off_positive'));
            }

            // Never write off more than is left — that would credit AR below the debt and put the
            // ledger out of step with the invoice it came from.
            if ($amount > $remaining + 0.01) {
                // Two sentences, two keys. A trailing clause appended with `.` cannot be worded
                // in a language whose sentence order differs — the note has to be part of the
                // sentence the translator writes, not glued onto the end of it.
                throw new DomainException(__(
                    $alreadyWrittenOff > 0
                        ? 'admin.refusals.write_off_exceeds_remaining_partial'
                        : 'admin.refusals.write_off_exceeds_remaining',
                    [
                        'amount' => number_format($amount, 2),
                        'number' => $locked->number,
                        'remaining' => number_format($remaining, 2),
                        'written' => number_format($alreadyWrittenOff, 2),
                        'outstanding' => number_format($outstanding, 2),
                    ],
                ));
            }

            $entryDate = isset($data['entry_date']) && $data['entry_date']
                ? CarbonImmutable::parse($data['entry_date'])->startOfDay()
                : CarbonImmutable::now()->startOfDay();

            // Operator-typed date that becomes a journal entry's entry_date → it must be refused
            // when its period is closed, in the SERVICE (the project's posting-date invariant).
            // Without this the row would commit, the operator would see "Saved", and the entry
            // would be refused inside the best-effort sync job that only logs.
            PostingDate::assertOpen($entryDate);

            $writeOff = InvoiceWriteOff::create([
                'invoice_id' => $locked->id,
                'tenant_id' => $locked->tenant_id,
                // The invoice's own column. Via the lease chain this stored NULL for an
                // owner invoice, which drops the row from every property-scoped read AND makes
                // InvoiceWriteOffJournalizer resolve bad_debt_expense against no property.
                'asset_id' => $locked->asset_id,
                'amount' => $amount,
                'entry_date' => $entryDate->toDateString(),
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            // Only a FULL write-off retires the invoice. A partial one leaves it live and still
            // collectable for the rest — writing off 5,000 of a 20,000 debt does not mean the
            // other 15,000 stopped being owed.
            // `$remaining`, not `$outstanding`: two partials that together clear the debt must
            // retire the invoice, exactly as one full write-off would. Comparing against the raw
            // balance left such an invoice `overdue` forever with nothing left to write off.
            if ($amount >= $remaining - 0.01) {
                // Deliberately NOT touching balance/paid_amount: those are derived by
                // recomputeTotals() from payments and credit, and a write-off is neither. The
                // balance stays as the record of what was written off; the STATUS is what takes
                // the invoice out of AR.
                $locked->status = 'written_off';
                $locked->saveQuietly();
            }

            return $writeOff;
        });
    }

    /**
     * A written-off debt that was recovered after all.
     *
     * Soft-delete, not edit or hard-delete: the ledger sweep voids the entry, and both decisions
     * stay on the record — an auditor can see the debt was written off and later recovered, which
     * a deleted row would hide.
     */
    public function reverse(InvoiceWriteOff $writeOff, ?string $reason = null): void
    {
        DB::transaction(function () use ($writeOff, $reason) {
            /** @var Invoice|null $invoice */
            $invoice = $writeOff->invoice;

            $writeOff->delete();

            if ($invoice && $invoice->status === 'written_off') {
                // Hand the invoice back to the single source of truth rather than guessing a
                // status here: recomputeTotals() re-derives paid/overdue/issued from the payments
                // and credit that actually exist.
                $invoice->status = 'issued';
                $invoice->saveQuietly();
                $invoice->recomputeTotals();
            }

            // Filed against the WRITE-OFF, which is the document being undone and is only
            // soft-deleted — so unlike the credit and deposit reversals the subject survives and
            // the trail row stays reachable from it.
            ReversalReason::record($writeOff, 'reversed', $reason);
        });
    }
}
