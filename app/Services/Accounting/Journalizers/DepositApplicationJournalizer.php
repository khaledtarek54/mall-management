<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\DepositApplication;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Netting a security deposit against a tenant's invoice (خصم من التأمين):
 *   Dr Deposits Held           (the liability to return the deposit shrinks)
 *   Cr Accounts Receivable     (the invoice it now settles)
 *
 * **Not Misc Income.** A `forfeit` credits income because the landlord KEEPS the money; an
 * application settles a receivable the landlord has already recognised as revenue. Posting this to
 * income would recognise the same rent twice — once when the invoice was raised and again when the
 * deposit paid it.
 *
 * Dated at APPLICATION time (entry_date), never the original receipt's date, so a deposit taken
 * three years ago can settle a current invoice without stranding the entry in a closed period. A
 * soft-deleted (reversed) application posts nothing, so `LedgerPoster::sync` voids its entry — the
 * AR re-opens and the deposit balance returns.
 */
class DepositApplicationJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var DepositApplication $app */
        $app = $source;

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
            'description_en' => 'Security deposit applied to '.$ref,
            'description_ar' => 'خصم من التأمين على '.$ref,
            'asset_id' => $assetId,
            'lines' => [
                [
                    'ledger_account_id' => $this->accounts->id('deposits_held', $assetId),
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
