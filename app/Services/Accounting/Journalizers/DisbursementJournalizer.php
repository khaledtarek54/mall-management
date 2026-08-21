<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\Disbursement;
use App\Models\PaymentMethod;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Owner disbursement (صرف مستحقات المالك) → clears the Due-to-Owner liability against cash:
 *   Dr Due to Owner / Cr Bank|Cash
 * Posts ONLY once PAID; scheduled/approved/cancelled → null (cancelling before payment voids
 * nothing, since nothing posted). Reads its own denormalized `asset_id` — never walks the
 * (possibly soft-deleted) parent statement — so it is safe under the windowed sweep. When
 * every owner is fully paid, Σ disbursements == the run's net, so Due to Owner nets to zero.
 */
class DisbursementJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var Disbursement $disbursement */
        $disbursement = $source;

        if ($disbursement->status !== Disbursement::STATUS_PAID) {
            return null; // only a paid disbursement moves money
        }

        $amount = round((float) $disbursement->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $assetId = $disbursement->asset_id;
        $dueToOwner = $this->accounts->id('due_to_owner', $assetId);
        // Through the rail: `disbursements.method` is catalogue-widened too.
        $cash = PaymentMethod::accountIdOrFloor($disbursement->method, $assetId, $this->accounts);

        return [
            'entry_date' => $disbursement->paid_on,
            'description_en' => 'Owner disbursement '.$disbursement->reference,
            'description_ar' => 'صرف مستحقات المالك '.$disbursement->reference,
            'asset_id' => $assetId,
            'lines' => [
                ['ledger_account_id' => $dueToOwner, 'debit' => $amount, 'credit' => 0, 'asset_id' => $assetId, 'tenant_id' => null],
                ['ledger_account_id' => $cash, 'debit' => 0, 'credit' => $amount, 'asset_id' => $assetId, 'tenant_id' => null],
            ],
        ];
    }
}
