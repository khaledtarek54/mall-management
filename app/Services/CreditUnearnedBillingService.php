<?php

namespace App\Services;

use App\Contracts\BillableAgreement;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\UnitOwnership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Credit back the part of an already-issued invoice a terminating lease will never earn
 * (story MF-02, scenario S8).
 *
 * **The gap this closes.** Rent is billed in advance on the 1st. A tenant terminating on the 18th
 * has already been invoiced for the whole month, and trailing proration cannot help — that invoice
 * exists, it is in AR, it may be paid, and it is very possibly filed with the tax authority. The
 * only correct instrument is a credit note, and until now raising it was a manual act somebody had
 * to remember: S8's "the fix is a manual credit note".
 *
 * **The split uses the same rule the invoice used.** `MonthlyBillingService::monthsCovered()` is
 * shared, so the credit is the exact complement of what was billed rather than an independent
 * day-count that would drift from it by a day or two on every quarter-billed lease.
 *
 * **It credits, it never deletes.** A partially-paid or ETA-filed invoice is untouchable by design
 * (`DeletionPolicy`, and the existing refusal in `LeaseTerminationService`); a credit note leaves
 * both documents standing and an auditor able to follow the money. The note is issued and then
 * applied to its own invoice: what fits reduces the balance, and any excess — the normal case for
 * an invoice already paid — stays as tenant credit to refund or offset, which is exactly what the
 * final account then settles (MF-03).
 */
class CreditUnearnedBillingService
{
    /**
     * @return array<int, CreditNote> one note per over-billed invoice (empty when nothing is owed back)
     */
    public function forTermination(Lease $lease, CarbonImmutable $terminationDate): array
    {
        return $this->forAgreementEnding($lease, $terminationDate, 'termination');
    }

    /**
     * The same rule, for a unit ownership that changes hands mid-period (pre-staging QA, F-02).
     *
     * An assessment is raised on the 1st for the whole month; a sale completing on the 11th leaves
     * the seller billed for twenty-one days they did not own. `UnitOwnershipStatus::Transferred` is
     * not `isBillable()`, so the run will never revisit the seller — measured, the over-billing was
     * permanent and nothing anywhere corrected it. The lease side has had this since MF-02; the
     * ownership side had no equivalent, which is the whole of the defect.
     *
     * @return array<int, CreditNote>
     */
    public function forOwnershipTransfer(UnitOwnership $ownership, CarbonImmutable $transferDate): array
    {
        // The seller's last owned day is the day BEFORE the buyer's tenure opens, which is exactly
        // what `transfer()` writes to `ended_at`. Passing the transfer date itself would credit one
        // day too few and leave the seller paying for a day the buyer also pays for.
        return $this->forAgreementEnding($ownership, $transferDate->subDay(), 'transfer');
    }

    /**
     * Credit the unearned part of every invoice on this agreement that reaches past $lastEarnedDay.
     *
     * Agreement-agnostic on purpose: a lease and an ownership are billed by the same
     * `monthsCovered()` rule, so they must be UN-billed by it too. A second copy for ownerships
     * would let a mid-month move-out and a mid-month resale prorate differently — the exact fork
     * `BillUnitOwnershipsService` avoids by calling the lease run's own proration.
     *
     * @return array<int, CreditNote>
     */
    private function forAgreementEnding(BillableAgreement $agreement, CarbonImmutable $lastEarnedDay, string $reason): array
    {
        $terminationDate = $lastEarnedDay->startOfDay();

        $link = $agreement->invoiceLinkAttributes();

        $invoices = Invoice::query()
            ->where(fn ($q) => collect($link)
                ->reject(fn ($v) => $v === null)
                ->each(fn ($v, $column) => $q->where($column, $v)))
            // Cancelled and written-off invoices claim nothing, so there is nothing to give back.
            ->whereIn('status', ['draft', 'issued', 'partially_paid', 'overdue', 'paid'])
            ->whereNotNull('period_start')
            ->whereNotNull('period_end')
            // Only invoices that reach BEYOND the termination date have an unearned part.
            ->whereDate('period_end', '>', $terminationDate->toDateString())
            ->whereDate('period_start', '<=', $terminationDate->toDateString())
            ->with('items')
            ->get();

        $notes = [];

        foreach ($invoices as $invoice) {
            $note = $this->creditUnearnedPortion($agreement, $invoice, $terminationDate, $reason);

            if ($note !== null) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    private function creditUnearnedPortion(BillableAgreement $agreement, Invoice $invoice, CarbonImmutable $terminationDate, string $reason): ?CreditNote
    {
        $periodStart = CarbonImmutable::instance($invoice->period_start)->startOfDay();
        $periodEnd = CarbonImmutable::instance($invoice->period_end)->startOfDay();

        $cycleMonths = ($periodEnd->year - $periodStart->year) * 12
            + ($periodEnd->month - $periodStart->month) + 1;

        // THE SAME PRORATION METHOD THE INVOICE WAS BILLED ON (EG-29). A credit computed on a
        // different rule than the invoice it credits disagrees with it by days — on exactly the
        // mid-month move-out this service exists for, and by an amount plausible enough that nobody
        // would query it. A `BillableAgreement` answers `prorationMethod()`, so a lease and a unit
        // ownership resolve it their own way and neither is special-cased here.
        $proration = $agreement->prorationMethod();

        $billed = MonthlyBillingService::monthsCovered($periodStart->startOfMonth(), $cycleMonths, $periodStart, $periodEnd, $proration);
        $earned = MonthlyBillingService::monthsCovered($periodStart->startOfMonth(), $cycleMonths, $periodStart, $terminationDate, $proration);

        if ($billed <= 0) {
            return null;
        }

        $unearnedRatio = 1 - ($earned / $billed);

        if ($unearnedRatio <= 0) {
            return null;
        }

        $subtotal = 0.0;
        $vat = 0.0;

        // Accumulated BY VAT RATE, not as one blended figure. A quarter's invoice mixes exempt base
        // rent with standard-rated service charge, so crediting it as a single line would state a
        // rate of 2.69% — an average, not a tax. The tenant reverses input VAT off this document,
        // and their accountant needs to know how much of it was ever taxed.
        $byRate = [];

        /** @var InvoiceItem $item */
        foreach ($invoice->items as $item) {
            // ONE-OFF lines are not time-apportioned and must not be clawed back: a CAM true-up, a
            // percentage-rent overage, a utility recharge or a fine is earned in full for something
            // that already happened. Crediting them pro-rata would refund the tenant for water they
            // used and damage they caused.
            if (! $this->isTimeApportioned($item)) {
                continue;
            }

            $lineAmount = (float) $item->amount * $unearnedRatio;
            $lineVat = (float) $item->vat_amount * $unearnedRatio;

            $subtotal += $lineAmount;
            $vat += $lineVat;

            $rate = (string) round((float) $item->vat_rate, 2);
            $byRate[$rate] ??= ['amount' => 0.0, 'vat' => 0.0];
            $byRate[$rate]['amount'] += $lineAmount;
            $byRate[$rate]['vat'] += $lineVat;
        }

        $subtotal = round($subtotal, 2);
        $vat = round($vat, 2);
        $total = round($subtotal + $vat, 2);

        if ($total <= 0) {
            return null;
        }

        return DB::transaction(function () use ($agreement, $invoice, $terminationDate, $subtotal, $vat, $total, $periodEnd, $byRate, $reason) {
            $note = CreditNote::create([
                'tenant_id' => $agreement->billingTenantId(),
                // `credit_notes` has no `unit_ownership_id`, and does not need one: the note names
                // the INVOICE, and the invoice names the ownership. What it must carry is the
                // property, because that is what binds the contra-revenue to one mall's books —
                // and for an owner assessment `lease->unit` is null, which is the exact hole
                // `CreditNoteService` closed on 2026-08-18 by reading `invoices.asset_id`.
                'lease_id' => $agreement instanceof Lease ? $agreement->getKey() : null,
                'asset_id' => $invoice->asset_id,
                'invoice_id' => $invoice->id,
                'status' => 'draft',
                // The date the tenancy ended is the date the revenue stops being earned, so it is
                // the date the reversal belongs in the books. A closed period refuses here, via
                // CreditNoteService::issue() — correctly, and loudly, rather than committing the
                // termination and losing the credit inside a best-effort job.
                'issue_date' => $terminationDate,
                'reason' => 'adjustment',
                'reason_notes' => __("admin.credit_notes.unearned_on_{$reason}", [
                    'invoice' => $invoice->number,
                    'date' => $terminationDate->format('d/m/Y'),
                    'through' => $periodEnd->format('d/m/Y'),
                ]),
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'total' => $total,
                'applied_amount' => 0,
                'balance' => $total,
                'currency' => $invoice->currency ?? 'EGP',
            ]);

            // The LINES, written BEFORE issue(): a note has to say what it credits by the time it
            // becomes a document, not after. One per VAT rate — see the accumulator above and
            // CreditNote::describeAs().
            $description = __('admin.credit_notes.line_unearned', [
                'invoice' => $invoice->number,
                'through' => $periodEnd->format('d/m/Y'),
            ]);

            foreach ($byRate as $rate => $part) {
                if (round($part['amount'] + $part['vat'], 2) <= 0) {
                    continue;
                }

                $note->describeAs($description, $part['amount'], (float) $rate, $part['vat']);
            }

            $service = app(CreditNoteService::class);
            $service->issue($note);

            // Apply what the invoice can absorb. An unpaid invoice simply owes less; a PAID one
            // absorbs nothing and the whole note stays as tenant credit — which is the honest
            // outcome, because the money is genuinely with the landlord and has to come back.
            $service->applyToInvoice($note->fresh(), $invoice->fresh());

            return $note->fresh();
        });
    }

    /**
     * Is this line rent-like — earned across the period rather than for a discrete event?
     *
     * Read from the CHARGE where there is one, because the charge knows its own frequency. Lines
     * with no charge behind them are the one-offs the billing engine attaches directly (a CAM
     * recovery, a percentage-rent overage), and those are never time-apportioned.
     *
     * **An ARREARS line is not apportionable either, and that is the same reason one notch along.**
     * It covers the period BEFORE the invoice's own (EG-30 / M-2), which has already run in full by
     * the time anyone terminates inside the current one. Prorating it by this invoice's unearned
     * ratio refunds the tenant for a month they had: a lease ending 15 September, on a September
     * invoice carrying September's rent and AUGUST's service charge, would have had half of August
     * credited back. Real money, on a document an auditor reads as a correction.
     *
     * The one edge, stated rather than hidden: on a lease's FINAL invoice an arrears line covers
     * both the arrears window and the current period, so excluding it could under-credit if a
     * termination landed inside that period too. That invoice is only raised once expiry is known,
     * so a separate mid-period termination against it is not a flow this system produces — and
     * under-crediting is the safer error of the two, because the alternative is refunding money
     * that was earned.
     */
    private function isTimeApportioned(InvoiceItem $item): bool
    {
        if (blank($item->charge_id)) {
            return false;
        }

        /** @var Charge|null $charge */
        $charge = $item->charge;

        if ($charge?->billsInArrears()) {
            return false;
        }

        return $charge?->frequency === 'monthly';
    }
}
