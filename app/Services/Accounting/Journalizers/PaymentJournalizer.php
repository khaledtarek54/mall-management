<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\Payment;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Capture payment (تحصيل دفعة):
 *   Dr Cash / Bank (amount received)
 *   Cr Accounts Receivable (amount allocated to invoices)
 *   Cr Unearned Revenue (any unallocated remainder — a customer advance)
 *
 * Only `captured` payments post; everything else has no GL effect.
 */
class PaymentJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var Payment $payment */
        $payment = $source;

        if ($payment->status !== 'captured') {
            return null;
        }

        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $payment->loadMissing('invoices.lease.unit');
        $assetId = $payment->invoices->first()?->lease?->unit?->asset_id;

        $allocated = round((float) $payment->invoices->sum(fn ($i) => (float) $i->pivot->allocated_amount), 2);
        $arCredit = min($allocated, $amount);
        $advance = round($amount - $arCredit, 2);

        // cash for physical cash, otherwise the bank (card / transfer / instapay / cheque…).
        $cashRole = $payment->method === 'cash' ? 'cash' : 'bank';

        $lines = [[
            'ledger_account_id' => $this->accounts->id($cashRole, $assetId),
            'debit' => $amount,
            'credit' => 0,
        ]];

        if ($arCredit > 0) {
            $lines[] = [
                'ledger_account_id' => $this->accounts->id('accounts_receivable', $assetId),
                'debit' => 0,
                'credit' => $arCredit,
                'tenant_id' => $payment->tenant_id,
            ];
        }

        if ($advance > 0) {
            $lines[] = [
                'ledger_account_id' => $this->accounts->id('unearned_revenue', $assetId),
                'debit' => 0,
                'credit' => $advance,
                'tenant_id' => $payment->tenant_id,
            ];
        }

        return [
            'entry_date' => $payment->payment_date,
            'description_en' => 'Payment '.$payment->reference,
            'description_ar' => 'دفعة '.$payment->reference,
            'asset_id' => $assetId,
            'lines' => $lines,
        ];
    }
}
