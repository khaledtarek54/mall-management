<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\MarketingSpend;
use App\Models\PaymentMethod;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Marketing spend (مصروف تسويق) — the operator draws from the marketing fund to
 * pay for offers / promotions / events / printed work, settled immediately from
 * cash or bank:
 *   Dr Marketing Expense (amount)
 *   Cr Cash / Bank (amount)
 *
 * The marketing LEVY is already recognised as revenue on the tenant invoice
 * (marketing_revenue); this books the matching spend side so the fund's net
 * position shows on the P&L and reconciles. No VAT split — the spend model
 * carries a single gross amount (input VAT on marketing spend is a future
 * enhancement, tracked in docs/modules/13-marketing.md).
 *
 * A soft-deleted spend has no ledger effect (LedgerPoster::sync voids its entry).
 */
class MarketingSpendJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var MarketingSpend $spend */
        $spend = $source;

        $amount = round((float) $spend->amount, 2);
        if ($amount <= 0) {
            return null; // a zero spend has no GL effect
        }

        // The spend inherits its property from its budget (marketing_budgets.asset_id).
        // Resolve it withTrashed: a live spend is real money out, so an archived
        // (soft-deleted) budget must NOT strip its expense off the books — only the
        // spend's own deletion voids the entry (handled by LedgerPoster::sync).
        $assetId = $spend->budget()->withTrashed()->value('asset_id');
        if (! $assetId) {
            return null; // a property-less spend has no place in the per-property books
        }

        return [
            'entry_date' => $spend->spent_on,
            'description_en' => 'Marketing spend — '.$spend->category,
            'description_ar' => 'مصروف تسويق — '.$spend->category,
            'asset_id' => $assetId,
            'lines' => [
                [
                    'ledger_account_id' => $this->accounts->id('marketing_expense', $assetId),
                    'debit' => $amount,
                    'credit' => 0,
                    'asset_id' => $assetId,
                ],
                [
                    'ledger_account_id' => PaymentMethod::accountIdOrFloor($spend->paid_from, $assetId, $this->accounts),
                    'debit' => 0,
                    'credit' => $amount,
                    'asset_id' => $assetId,
                ],
            ],
        ];
    }
}
