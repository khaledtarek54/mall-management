<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\TenantCreditApplication;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Applying a tenant's on-account credit to an invoice (تسوية دفعة مقدمة):
 *   Dr Unearned Revenue        (the advance the tenant had on account is drawn down)
 *   Cr Accounts Receivable     (the invoice it now settles)
 *
 * Dated at APPLICATION time (entry_date), NEVER the original receipt's date — so it always posts
 * into an open period regardless of when the overpayment was received. A soft-deleted (reversed)
 * application posts nothing, so LedgerPoster::sync voids its entry (the AR re-opens, credit returns).
 */
class TenantCreditApplicationJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var TenantCreditApplication $app */
        $app = $source;

        // Reversed → no ledger effect (the poster voids the existing entry). Belt-and-suspenders:
        // LedgerPoster::sync already skips the journalizer for a trashed source.
        if ($app->trashed()) {
            return null;
        }

        $amount = round((float) $app->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $assetId = $app->asset_id;
        $ref = \App\Models\Invoice::whereKey($app->invoice_id)->value('number') ?? ('#'.$app->invoice_id);

        return [
            'entry_date' => $app->entry_date,
            'description_en' => 'Tenant credit applied to '.$ref,
            'description_ar' => 'تطبيق رصيد المستأجر على '.$ref,
            'asset_id' => $assetId,
            'lines' => [
                [
                    'ledger_account_id' => $this->accounts->id('unearned_revenue', $assetId),
                    'debit' => $amount,
                    'credit' => 0,
                    'tenant_id' => $app->tenant_id,
                ],
                [
                    'ledger_account_id' => $this->accounts->id('accounts_receivable', $assetId),
                    'debit' => 0,
                    'credit' => $amount,
                    'tenant_id' => $app->tenant_id,
                ],
            ],
        ];
    }
}
