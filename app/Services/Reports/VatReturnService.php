<?php

namespace App\Services\Reports;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalLine;
use App\Services\Accounting\AccountResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The VAT return for a period — what the operator owes the tax authority, and the proof it ties.
 *
 * **Output VAT and input VAT come from the LEDGER**, not from the documents, because the ledger is
 * the single source of truth and it is what the trial balance and the statements are built on. A
 * return derived from invoices would be a second opinion about the same money — and the two would
 * agree right up until the month they did not, with nothing to say which was wrong.
 *
 * **So the documents are used for one thing: to CHECK it.** Σ of the invoices' own VAT for the
 * period should equal the ledger's output VAT movement. When those disagree the return is not
 * merely wrong, something has gone unposted or been posted twice — which is exactly the class of
 * problem a return is the last chance to catch before it is filed with the ETA and becomes a
 * position the operator has taken.
 *
 * **The taxable base can only come from the documents.** The GL knows revenue by account, not by
 * tax treatment, so which supplies were standard-rated and which exempt is a question only the
 * invoice lines can answer — and it matters here because base rent is exempt while service charges
 * are not (`App\Support\Vat`).
 *
 * Read-only. Nothing here posts, and filing is not modelled at all: this reports the position.
 */
class VatReturnService
{
    public function __construct(private AccountResolver $accounts) {}

    /**
     * @return array{
     *     period_start: string, period_end: string,
     *     output_vat: float, input_vat: float, net_payable: float,
     *     output_vat_documents: float, output_vat_difference: float, ties_out: bool,
     *     base_standard: float, base_exempt: float,
     * }
     */
    public function for(CarbonImmutable $start, CarbonImmutable $end, ?int $assetId = null): array
    {
        $outputVat = $this->accountMovement('vat_payable', $start, $end, $assetId, credits: true);
        $inputVat = $this->accountMovement('vat_recoverable', $start, $end, $assetId, credits: false);

        // The check, from the other side of the system.
        $invoices = Invoice::query()
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('issue_date', [$start->toDateString(), $end->toDateString()])
            ->when($assetId, fn ($q) => $q->whereHas('lease.unit', fn ($u) => $u->where('asset_id', $assetId)))
            ->with('items')
            ->get();

        $outputVatDocuments = round((float) $invoices->sum(fn (Invoice $i) => (float) $i->vat_amount), 2);

        $baseStandard = 0.0;
        $baseExempt = 0.0;

        foreach ($invoices as $invoice) {
            foreach ($invoice->items as $item) {
                if (! $item instanceof InvoiceItem) {
                    continue;
                }

                // The treatment is the LINE's, not the invoice's: one invoice carries exempt base
                // rent and standard-rated service charge together, which is the normal case here
                // rather than an edge one.
                if (round((float) $item->vat_rate, 4) > 0) {
                    $baseStandard += (float) $item->amount;
                } else {
                    $baseExempt += (float) $item->amount;
                }
            }
        }

        $difference = round($outputVat - $outputVatDocuments, 2);

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'output_vat' => $outputVat,
            'input_vat' => $inputVat,
            // What is owed. Negative means the operator is in credit with the authority, which is a
            // real state (a month of heavy purchasing) and not an error.
            'net_payable' => round($outputVat - $inputVat, 2),
            'output_vat_documents' => $outputVatDocuments,
            'output_vat_difference' => $difference,
            'ties_out' => abs($difference) < 0.005,
            'base_standard' => round($baseStandard, 2),
            'base_exempt' => round($baseExempt, 2),
        ];
    }

    /**
     * Net movement on a semantic account role over the period, on its normal side.
     *
     * VAT payable is a liability (credits increase it); VAT recoverable is an asset (debits do). So
     * each is read on its own side rather than by a shared sign, and a refund or a correction posted
     * the other way reduces the figure exactly as it should.
     */
    private function accountMovement(string $role, CarbonImmutable $start, CarbonImmutable $end, ?int $assetId, bool $credits): float
    {
        $accountId = $this->accounts->id($role, $assetId);

        $expression = $credits
            ? 'COALESCE(credit, 0) - COALESCE(debit, 0)'
            : 'COALESCE(debit, 0) - COALESCE(credit, 0)';

        return round((float) JournalLine::query()
            ->where('ledger_account_id', $accountId)
            ->when($assetId, fn ($q) => $q->where('asset_id', $assetId))
            ->whereHas('entry', fn ($q) => $q
                ->where('status', 'posted')
                ->whereDate('entry_date', '>=', $start->toDateString())
                ->whereDate('entry_date', '<=', $end->toDateString()))
            ->sum(DB::raw($expression)), 2);
    }
}
