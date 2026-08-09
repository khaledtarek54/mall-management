<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\InvoiceWriteOff;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Write off a bad debt (إعدام دين):
 *   Dr Bad Debt Expense   (the amount accepted as uncollectible)
 *   Cr Accounts Receivable
 *
 * Dated at the write-off DECISION, not at the invoice — the loss belongs to the period the
 * operator recognised it, while the revenue stays in the period it was earned. That split is the
 * entire reason this is a write-off and not a cancellation: cancelling reverses revenue in the
 * current period, understating the year it was actually earned and hiding the bad debt as a
 * revenue reduction.
 */
class InvoiceWriteOffJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var InvoiceWriteOff $writeOff */
        $writeOff = $source;

        $amount = round((float) $writeOff->amount, 2);

        if ($amount <= 0) {
            return null;
        }

        $writeOff->loadMissing('invoice');
        $assetId = $writeOff->asset_id;

        return [
            'entry_date' => $writeOff->entry_date,
            'description_en' => 'Bad debt write-off — invoice '.($writeOff->invoice?->number ?? "#{$writeOff->invoice_id}"),
            'description_ar' => 'إعدام دين — فاتورة '.($writeOff->invoice?->number ?? "#{$writeOff->invoice_id}"),
            'asset_id' => $assetId,
            'lines' => [
                [
                    'ledger_account_id' => $this->accounts->id('bad_debt_expense', $assetId),
                    'debit' => $amount,
                    'credit' => 0,
                    'tenant_id' => $writeOff->tenant_id,
                ],
                [
                    'ledger_account_id' => $this->accounts->id('accounts_receivable', $assetId),
                    'debit' => 0,
                    'credit' => $amount,
                    'tenant_id' => $writeOff->tenant_id,
                    'lease_id' => $writeOff->invoice?->lease_id,
                ],
            ],
        ];
    }
}
