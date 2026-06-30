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

        // A payment can pay invoices across DIFFERENT properties (the form scopes by
        // tenant, not asset). Reduce each property's receivables on its own asset so
        // per-property books stay correct. Bucket the allocations by invoice asset.
        $allocByAsset = [];
        foreach ($payment->invoices as $invoice) {
            $alloc = round((float) $invoice->pivot->allocated_amount, 2);
            if ($alloc <= 0) {
                continue;
            }
            $assetId = $invoice->lease?->unit?->asset_id; // null = no-asset bucket
            $key = $assetId ?? 0;
            $allocByAsset[$key] = round(($allocByAsset[$key] ?? 0) + $alloc, 2);
        }

        // Entry-level books dimension: the single asset if all allocations share one,
        // else null (a genuinely cross-property / consolidated receipt).
        $distinctAssets = array_values(array_filter(array_keys($allocByAsset), fn ($k) => $k !== 0));
        $entryAsset = count($distinctAssets) === 1 ? $distinctAssets[0] : null;

        // cash for physical cash, otherwise the bank (card / transfer / instapay / cheque…).
        $cashRole = $payment->method === 'cash' ? 'cash' : 'bank';

        $lines = [[
            'ledger_account_id' => $this->accounts->id($cashRole, $entryAsset),
            'debit' => $amount,
            'credit' => 0,
            'asset_id' => $entryAsset,
        ]];

        // Credit each property's receivables, capped to the cash actually received
        // (guards the over-allocation edge so the entry always balances to `amount`).
        $remaining = $amount;
        foreach ($allocByAsset as $key => $alloc) {
            if ($remaining <= 0) {
                break;
            }
            $credit = round(min($alloc, $remaining), 2);
            if ($credit <= 0) {
                continue;
            }
            $assetId = $key === 0 ? null : $key;
            $lines[] = [
                'ledger_account_id' => $this->accounts->id('accounts_receivable', $assetId),
                'debit' => 0,
                'credit' => $credit,
                'asset_id' => $assetId,
                'tenant_id' => $payment->tenant_id,
            ];
            $remaining = round($remaining - $credit, 2);
        }

        // Any unallocated remainder is a customer advance (unearned revenue).
        if ($remaining > 0) {
            $lines[] = [
                'ledger_account_id' => $this->accounts->id('unearned_revenue', $entryAsset),
                'debit' => 0,
                'credit' => $remaining,
                'asset_id' => $entryAsset,
                'tenant_id' => $payment->tenant_id,
            ];
        }

        return [
            'entry_date' => $payment->payment_date,
            'description_en' => 'Payment '.$payment->reference,
            'description_ar' => 'دفعة '.$payment->reference,
            'asset_id' => $entryAsset,
            'lines' => $lines,
        ];
    }
}
