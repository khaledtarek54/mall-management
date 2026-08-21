<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\DepositTransaction;
use App\Models\PaymentMethod;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Security-deposit event (حركة تأمين):
 *   receipt → Dr Cash|Bank / Cr Deposits Held (a liability — owed back to the tenant)
 *   refund  → Dr Deposits Held / Cr Cash|Bank
 *   forfeit → Dr Deposits Held / Cr Misc Income (kept deposit becomes income)
 *
 * Posts a `recorded` transaction; cancelled ones are skipped (sync voids the entry).
 */
class DepositTransactionJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var DepositTransaction $deposit */
        $deposit = $source;

        if (! $deposit->isPostable()) {
            return null;
        }

        $amount = round((float) $deposit->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $assetId = $deposit->asset_id;
        // The rail decides the account; null takes the floor. See PaymentJournalizer.
        $cash = PaymentMethod::accountIdOrFloor($deposit->method, $assetId, $this->accounts);
        $held = $this->accounts->id('deposits_held', $assetId);

        // [debit account, credit account] per transaction type. An unknown type
        // (e.g. a future enum value not yet mapped) is skipped, not thrown on.
        $pair = match ($deposit->type) {
            'receipt' => [$cash, $held],
            'refund' => [$held, $cash],
            'forfeit' => [$held, $this->accounts->id('misc_income', $assetId)],
            default => null,
        };
        if ($pair === null) {
            // Reached only past the postable + non-zero guards, so real money moved but its type
            // isn't mapped — post nothing rather than throw, but surface it: a silent no-op here is
            // the shape of a GL leak (a new deposit type shipped without a journalizer branch).
            Log::warning('DepositTransactionJournalizer: unmapped deposit type — nothing posted to the GL.', [
                'deposit_transaction_id' => $deposit->getKey(),
                'type' => $deposit->type,
                'amount' => $amount,
            ]);

            return null;
        }
        [$debit, $credit] = $pair;

        return [
            'entry_date' => $deposit->transaction_date,
            'description_en' => 'Deposit '.$deposit->type.' '.$deposit->number,
            'description_ar' => 'تأمين ('.$deposit->type.') '.$deposit->number,
            'asset_id' => $assetId,
            'lines' => [
                ['ledger_account_id' => $debit, 'debit' => $amount, 'credit' => 0, 'asset_id' => $assetId, 'tenant_id' => $deposit->tenant_id, 'lease_id' => $deposit->lease_id],
                ['ledger_account_id' => $credit, 'debit' => 0, 'credit' => $amount, 'asset_id' => $assetId, 'tenant_id' => $deposit->tenant_id, 'lease_id' => $deposit->lease_id],
            ],
        ];
    }
}
