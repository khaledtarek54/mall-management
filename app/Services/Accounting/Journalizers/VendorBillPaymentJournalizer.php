<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\VendorBillPayment;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Vendor-bill payment (سداد مورد) — settle the payable with cash/bank:
 *   Dr Accounts Payable (amount)
 *   Cr Cash / Bank (amount)
 */
class VendorBillPaymentJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var VendorBillPayment $payment */
        $payment = $source;

        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $payment->loadMissing('bill');

        // A payment only has a GL effect while its parent bill is postable — a payment
        // against a draft/cancelled bill (import/backfill edge) is skipped, so the sweep
        // voids it if the bill is later cancelled. Mirrors every other journalizer's guard.
        if (! $payment->bill?->isPostable()) {
            return null;
        }

        $assetId = $payment->bill->asset_id;

        $cashRole = $payment->method === 'cash' ? 'cash' : 'bank';

        return [
            'entry_date' => $payment->payment_date,
            'description_en' => 'Vendor payment '.($payment->reference ?: '#'.$payment->id).' — bill '.$payment->bill?->number,
            'description_ar' => 'سداد مورد — فاتورة '.$payment->bill?->number,
            'asset_id' => $assetId,
            'lines' => [
                [
                    'ledger_account_id' => $this->accounts->id('accounts_payable', $assetId),
                    'debit' => $amount,
                    'credit' => 0,
                    'asset_id' => $assetId,
                ],
                [
                    'ledger_account_id' => $this->accounts->id($cashRole, $assetId),
                    'debit' => 0,
                    'credit' => $amount,
                    'asset_id' => $assetId,
                ],
            ],
        ];
    }
}
