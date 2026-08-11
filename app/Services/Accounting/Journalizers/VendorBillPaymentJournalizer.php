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

        // A voided payment has no ledger effect, so the sweep reverses its entry — the same
        // mechanism a cancelled invoice or a refunded receipt uses. Checked before the amount so a
        // void reads as the reason, and reading the SAME predicate the bill's recompute reads, so
        // the document and the books can never disagree about whether the cash moved.
        if ($payment->isVoided()) {
            return null;
        }

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

        // Egyptian withholding tax (خصم وإضافة). The payable is discharged in FULL by $amount —
        // part in cash, part by tax the operator now owes the ETA on the vendor's behalf. So the
        // debit stays gross and the credit splits:
        //   Dr Accounts Payable      (gross)
        //   Cr Cash / Bank           (gross − withheld)   ← what actually left the account
        //   Cr Withholding Tax Payable (withheld)         ← held for the ETA, not the operator's
        // Withholding it and NOT booking the liability would understate what is owed to the tax
        // authority while flattering cash — the exact failure this closes.
        $withheld = round((float) $payment->withholding_amount, 2);
        $net = round($amount - $withheld, 2);

        $lines = [
            [
                'ledger_account_id' => $this->accounts->id('accounts_payable', $assetId),
                'debit' => $amount,
                'credit' => 0,
                'asset_id' => $assetId,
            ],
            [
                'ledger_account_id' => $this->accounts->id($cashRole, $assetId),
                'debit' => 0,
                'credit' => $net,
                'asset_id' => $assetId,
            ],
        ];

        if ($withheld > 0) {
            $lines[] = [
                'ledger_account_id' => $this->accounts->id('withholding_tax_payable', $assetId),
                'debit' => 0,
                'credit' => $withheld,
                'asset_id' => $assetId,
            ];
        }

        return [
            'entry_date' => $payment->payment_date,
            'description_en' => 'Vendor payment '.($payment->reference ?: '#'.$payment->id).' — bill '.$payment->bill?->number,
            'description_ar' => 'سداد مورد — فاتورة '.$payment->bill?->number,
            'asset_id' => $assetId,
            'lines' => $lines,
        ];
    }
}
