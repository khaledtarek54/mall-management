<?php

namespace App\Support;

use App\Models\DepositTransaction;
use App\Models\Expense;
use App\Models\FixedAsset;

/**
 * **Which DERIVED fields a committed money document's form may still offer, and why — the register
 * behind `AMoneyFormIsClosedOnceCommittedTest`.**
 *
 * The rule that gate enforces (CHANGE-IMPACT-PLAN §17.2): on a committed record, a REFUSED field
 * renders disabled, `status` is never an enabled control, and a DERIVED field renders disabled
 * unless it is HERE with a reason — and only on a page that announces the restatement
 * (`AnnouncesLedgerRestatement`), because DERIVED's own definition ends *"the operator must be
 * told"*.
 *
 * This is deliberately a SHORT list. Every entry is a decision someone made and wrote down
 * elsewhere first; an empty reason or a stale entry fails the gate, exactly as `DeletionPolicy`
 * and `Reversals` treat their exemptions. NEUTRAL fields are not registered and not judged — the
 * gate answers the LEDGER question, and the money-without-ledger cases (the invoice's service
 * period, the payment's gateway identity) are one-off locks with their own regression tests,
 * because "evidence" is not a property that can be derived structurally.
 *
 * @see docs/accounting/CHANGE-IMPACT-PLAN.md §17
 */
class MoneyFormPolicy
{
    /**
     * model => [field => why it stays open on a committed record].
     *
     * @var array<class-string, array<string, string>>
     */
    public const OPEN_WHILE_DERIVED = [
        // §15.2 — deliberately NOT frozen, and promoting these was tried and REVERTED: re-costing
        // an ACTIVE asset is a supported operation guarded by DepreciationService::assertRecostValid()
        // (F-86), and freezing it turned a guarded correction into a dead end. The form states the
        // consequence on each field (SW-238) and the page announces the re-post.
        FixedAsset::class => [
            'acquisition_date' => 'The entry date of a document §15.2 keeps correctable; the field carries the re-post hint and the save announces the figures.',
            'acquisition_cost' => 'A re-cost is a SUPPORTED operation (assertRecostValid, F-86); freezing it was tried 2026-08-28 and reverted the same day.',
            'funded_from' => 'The credit leg of a correctable acquisition; hinted on the field, announced on the save.',
        ],

        // §17.7 D-B — a recorded decision, restated so it is chosen rather than inherited: Voyager
        // would refuse and book the correction into the current post month; Atriom chose
        // original-period-if-open (§13 D-6), with SealedPeriod refusing once the month closes and
        // the save toast quoting the month that moved.
        Expense::class => [
            'expense_date' => 'Re-dating a recorded expense is the house §13 D-6 decision — announced, and SealedPeriod-refused into a closed month.',
        ],

        // The deposit model's own design: a receipt keyed wrongly must stay fixable UNTIL the pot
        // is drawn on (the same rule as the عهدة in module 25), so on the gate's committed-but-
        // undrawn fixture these are open by intent. The drawn-on and settled freezes — where every
        // one of these locks — are proved by AnActOnAPostedDocumentIsWhereItCanBeSeenTest and the
        // model guards' own tests, on fixtures built in those states.
        DepositTransaction::class => [
            'lease_id' => 'Re-pointing an UNDRAWN receipt re-derives its tenant and property (§15.2); locks via hasBeenDrawnOn().',
            'type' => 'Correctable until the pot is drawn on; the wasOrIsReceipt guard (SW-017) closes both directions after.',
            'transaction_date' => 'Correctable until drawn on; the receipt freeze names it after.',
            'amount' => 'Correctable until drawn on — the model reads the pot net of the row\'s own persisted contribution.',
            'bank_account_id' => 'The RAIL stays correctable even after a draw — it changes which account the entry debits, not what the pot is made of; announced.',
            'method' => 'Same as bank_account_id: a rail correction, announced, deliberately outside both freezes.',
            'is_opening_balance' => 'Correctable until drawn on; joined BOTH freezes on 2026-09-05 (SW-240), proved on drawn-on fixtures elsewhere.',
        ],
    ];
}
