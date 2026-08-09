<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceWriteOff;
use App\Support\PostingDate;
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
                throw new DomainException(
                    "Invoice {$locked->number} is '{$locked->status}' — only a live receivable can be written off."
                );
            }

            $outstanding = round((float) $locked->balance, 2);

            if ($outstanding <= 0) {
                throw new DomainException(
                    "Invoice {$locked->number} has nothing outstanding — there is no debt to write off."
                );
            }

            $amount = isset($data['amount']) && $data['amount'] !== null
                ? round((float) $data['amount'], 2)
                : $outstanding;

            if ($amount <= 0) {
                throw new DomainException('A write-off amount must be greater than zero.');
            }

            // Never write off more than is owed — that would credit AR below the debt and put the
            // ledger out of step with the invoice it came from.
            if ($amount > $outstanding + 0.01) {
                throw new DomainException(
                    "Cannot write off {$amount} against invoice {$locked->number}: only {$outstanding} is outstanding."
                );
            }

            $entryDate = isset($data['entry_date']) && $data['entry_date']
                ? CarbonImmutable::parse($data['entry_date'])->startOfDay()
                : CarbonImmutable::now()->startOfDay();

            // Operator-typed date that becomes a journal entry's entry_date → it must be refused
            // when its period is closed, in the SERVICE (the project's posting-date invariant).
            // Without this the row would commit, the operator would see "Saved", and the entry
            // would be refused inside the best-effort sync job that only logs.
            PostingDate::assertOpen($entryDate);

            $locked->loadMissing('lease.unit');

            $writeOff = InvoiceWriteOff::create([
                'invoice_id' => $locked->id,
                'tenant_id' => $locked->tenant_id,
                'asset_id' => $locked->lease?->unit?->asset_id,
                'amount' => $amount,
                'entry_date' => $entryDate->toDateString(),
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            // Only a FULL write-off retires the invoice. A partial one leaves it live and still
            // collectable for the rest — writing off 5,000 of a 20,000 debt does not mean the
            // other 15,000 stopped being owed.
            if ($amount >= $outstanding - 0.01) {
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
    public function reverse(InvoiceWriteOff $writeOff): void
    {
        DB::transaction(function () use ($writeOff) {
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
        });
    }
}
