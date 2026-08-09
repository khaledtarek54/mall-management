<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\StraightLineRentAdjustment;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Straight-line rent recognition (الإيجار المؤجل), story RA-02:
 *
 *   recognising MORE than billed →  Dr Deferred Rent      / Cr Rental Income
 *   recognising LESS than billed →  Dr Rental Income      / Cr Deferred Rent
 *
 * The invoice has already posted Dr AR / Cr Rental Income at the CONTRACTED amount; this posts only
 * the DIFFERENCE, so the two together leave revenue at the straight-line figure while AR still
 * reflects what the tenant actually owes. Nothing here touches AR, VAT or the ETA payload.
 *
 * Over a full term the adjustments sum to zero and Deferred Rent unwinds to nil — that is the check
 * worth running on any doubt about the arithmetic, and `StraightLineRentTest` runs it.
 */
class StraightLineRentAdjustmentJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var StraightLineRentAdjustment $adjustment */
        $adjustment = $source;

        if ($adjustment->trashed()) {
            return null;
        }

        $amount = round((float) $adjustment->adjustment_amount, 2);

        // A month where the contracted rent already equals the straight-line figure needs no entry.
        // Posting a zero-value journal would be noise in every flat lease in the mall.
        if (abs($amount) < 0.005) {
            return null;
        }

        $assetId = $adjustment->asset_id;
        $deferred = $this->accounts->id('deferred_rent', $assetId);
        $revenue = $this->accounts->id('rent_revenue', $assetId);
        $period = $adjustment->period->format('F Y');

        // Debit the account that must GROW. A positive adjustment grows Deferred Rent (an asset)
        // and grows revenue; a negative one does the reverse.
        [$debit, $credit] = $amount > 0 ? [$deferred, $revenue] : [$revenue, $deferred];

        return [
            'entry_date' => $adjustment->entry_date,
            'description_en' => 'Straight-line rent adjustment — '.$period,
            'description_ar' => 'تسوية الإيجار بالقسط الثابت — '.$period,
            'asset_id' => $assetId,
            'lines' => [
                ['ledger_account_id' => $debit, 'debit' => abs($amount), 'credit' => 0, 'lease_id' => $adjustment->lease_id],
                ['ledger_account_id' => $credit, 'debit' => 0, 'credit' => abs($amount), 'lease_id' => $adjustment->lease_id],
            ],
        ];
    }
}
