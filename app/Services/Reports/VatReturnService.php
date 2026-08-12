<?php

namespace App\Services\Reports;

use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\TaxCode;
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
 * document lines can answer — and it matters here because base rent is exempt while service charges
 * are not.
 *
 * **The split reads the line's TAX CODE, not its rate.** Until 2026-08-12 it asked `vat_rate > 0`,
 * which cannot tell a **zero-rated** supply from an **exempt** one — both bill nothing, and they are
 * different lines on a filed return. That distinction lives on the tax code and now travels onto the
 * line (`invoice_items.tax_code`), so the return can finally state it. Lines raised before that
 * change carry no code and fall back to the old heuristic, which is honest about what it can know: a
 * document that recorded only a zero cannot be asked, afterwards, which kind of zero it was.
 *
 * **Only VAT-family supplies count.** Stamp duty and schedule tax are separate Egyptian taxes with
 * their own accounts and their own returns; a line billed under one contributes neither base nor
 * output VAT here. Nothing can carry them yet — those codes ship inactive pending their GL wiring —
 * but the return is written so that commissioning them does not silently corrupt it.
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
     *     base_standard: float, base_zero_rated: float, base_exempt: float,
     *     unclassified_lines: int,
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

        // CREDIT NOTES REDUCE THE SUPPLY, and until 2026-08-11 this side did not know they existed.
        //
        // The ledger side above is already net of them — `CreditNoteJournalizer` correctly DEBITS
        // `vat_payable`. Building the documents side from invoices alone therefore guaranteed
        // `difference = −(credit-note VAT)`, so `ties_out` was **false in every period containing a
        // VAT-bearing credit note** — and a control that cries wolf is a control the operator
        // learns to ignore. Worse, `base_standard`/`base_exempt` never netted them either, and
        // those are numbers that go on a filed return.
        //
        // Live, not latent: three paths issue VAT-bearing credit notes routinely — the CAM negative
        // true-up at the pool's `recovery_vat_rate`, the move-out unearned credit, and a manual note
        // inheriting its invoice's rate.
        $creditNotes = CreditNote::query()
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('issue_date', [$start->toDateString(), $end->toDateString()])
            ->when($assetId, fn ($q) => $q->whereHas('invoice.lease.unit', fn ($u) => $u->where('asset_id', $assetId)))
            ->with('items')
            ->get();

        // Summed from the LINES rather than the document headers, which are equal today
        // (`Invoice::syncTotalsFromItems` derives the header) but stop being so the moment a
        // non-VAT tax can sit on a line: a stamp-duty line's tax belongs to a different return.
        $base = ['standard' => 0.0, 'zero_rated' => 0.0, 'exempt' => 0.0];
        $unclassified = 0;
        $outputVatDocuments = 0.0;

        // The treatment is the LINE's, not the invoice's: one invoice carries exempt base rent and
        // standard-rated service charge together, which is the normal case here rather than an edge
        // one. Credit notes are subtracted through the same classification — a note against exempt
        // base rent reduces the exempt supply, one against a service charge reduces the
        // standard-rated supply, and netting them into a single bucket would misstate the split the
        // return is filed on.
        $accumulate = function ($items, int $sign) use (&$base, &$unclassified, &$outputVatDocuments) {
            foreach ($items as $item) {
                $taxCode = $item->tax_code;
                $family = TaxCode::familyOf($taxCode);

                // A supply outside VAT belongs to another tax's return entirely.
                if ($family !== null && $family !== TaxCode::FAMILY_VAT) {
                    continue;
                }

                $treatment = TaxCode::treatmentOf((string) $taxCode);

                if ($treatment === null) {
                    // No code: a line raised before the classification existed. All the document
                    // can still say is whether it was taxed, so zero-rated and exempt collapse —
                    // which is exactly the ambiguity the code was added to remove going forward.
                    $treatment = round((float) $item->vat_rate, 4) > 0 ? TaxCode::STANDARD : TaxCode::EXEMPT;
                    $unclassified++;
                }

                $base[$treatment] += $sign * (float) $item->amount;
                $outputVatDocuments += $sign * (float) $item->vat_amount;
            }
        };

        foreach ($invoices as $invoice) {
            $accumulate($invoice->items, 1);
        }

        foreach ($creditNotes as $note) {
            $accumulate($note->items->filter(fn ($i) => $i instanceof CreditNoteItem), -1);
        }

        $outputVatDocuments = round($outputVatDocuments, 2);

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
            'base_standard' => round($base['standard'], 2),
            // Zero-rated is a TAXABLE supply at 0%; exempt is outside the scope. Both bill nothing
            // and they are separate lines on the return, which is the whole reason the treatment is
            // stored on the tax code rather than inferred from a zero on a line.
            'base_zero_rated' => round($base['zero_rated'], 2),
            'base_exempt' => round($base['exempt'], 2),
            // How many lines in this period could not be classified from a tax code. Surfaced
            // rather than hidden: it is the difference between "no zero-rated supplies this period"
            // and "we cannot tell", and only one of those is safe to file.
            'unclassified_lines' => $unclassified,
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
                // posted AND void ({@see JournalEntry::REPORTABLE_STATUSES}) — a corrected vendor
                // bill otherwise makes the period's input VAT read 0 instead of 14,000, and the
                // operator overpays the tax authority by that much. The output side has a
                // document cross-check that would go amber; the input side has none.
                ->whereIn('status', JournalEntry::REPORTABLE_STATUSES)
                ->whereDate('entry_date', '>=', $start->toDateString())
                ->whereDate('entry_date', '<=', $end->toDateString()))
            ->sum(DB::raw($expression)), 2);
    }
}
