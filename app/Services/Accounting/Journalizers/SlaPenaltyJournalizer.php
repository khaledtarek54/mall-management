<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\SlaPenalty;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\Journalizers\Concerns\MapsExpenseCategory;
use Illuminate\Database\Eloquent\Model;

/**
 * SLA penalty charged to a vendor's bill (غرامة مستوى الخدمة) — FR-CM-08:
 *   Dr Accounts Payable   (we owe the vendor less)
 *   Cr Expense, by the bill's category (the service cost us less)
 *
 * **Why a cost reduction rather than income.** Money received from a supplier is presumed
 * to adjust the price paid to them, not to be separate revenue, unless it buys a distinct
 * good or service. An SLA penalty is a price adjustment on the maintenance service — so it
 * credits the SAME expense the bill debited (`VendorBillJournalizer` uses the identical
 * `MapsExpenseCategory` role). The penalty follows the cost.
 *
 * **No VAT line.** Liquidated damages are compensation, not a supply, so they sit outside
 * the scope of VAT — the input VAT the bill recovered is unaffected. Recorded as an
 * assumption in docs/BUSINESS-RULES.md for sign-off rather than buried here.
 *
 * ⚠️ **CAM does not follow automatically.** `CamExpensePool.total_actual_expense` is typed
 * in by an operator, not derived from the GL. So reducing the expense here does NOT reduce
 * what tenants are recharged: whoever records the year's actual CAM spend must use the
 * figure NET of penalties, or tenants over-pay CAM while the operator keeps the penalty.
 * See docs/BUSINESS-RULES.md.
 *
 * Posts only once the penalty is APPLIED to a bill. `pending` (still accruing) and `final`
 * (assessed and owed, not yet deducted) are estimates, and an estimate has no place in the
 * ledger; `waived` never had a claim.
 */
class SlaPenaltyJournalizer implements Journalizer
{
    use MapsExpenseCategory;

    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var SlaPenalty $penalty */
        $penalty = $source;

        if (! $penalty->isApplied() || $penalty->vendor_bill_id === null) {
            return null;
        }

        $bill = $penalty->bill;

        if ($bill === null || ! $bill->isPostable()) {
            return null;
        }

        $amount = round((float) $penalty->amount, 2);

        if ($amount <= 0) {
            return null;
        }

        // The bill's property, not the work order's: this entry adjusts THAT payable, and
        // the two must land in the same books to net off.
        $assetId = $bill->asset_id;
        // Credited back to whichever account the bill DEBITED, so a penalty cannot reduce a
        // different line from the one the service cost. See MapsExpenseCategory.
        $expenseAccountId = $this->expenseAccountIdFor($bill->category, $assetId, $this->accounts, "SLA penalty on bill {$bill->number}");
        $reference = $penalty->workOrder?->reference ?? "penalty #{$penalty->id}";

        return [
            'entry_date' => ($penalty->applied_at ?? $penalty->created_at)->toDateString(),
            'description_en' => "SLA penalty {$reference} — {$bill->number}",
            'description_ar' => "غرامة مستوى الخدمة {$reference} — {$bill->number}",
            'asset_id' => $assetId,
            'lines' => [
                [
                    'ledger_account_id' => $this->accounts->id('accounts_payable', $assetId),
                    'debit' => $amount,
                    'credit' => 0,
                    'asset_id' => $assetId,
                ],
                [
                    'ledger_account_id' => $expenseAccountId,
                    'debit' => 0,
                    'credit' => $amount,
                    'asset_id' => $assetId,
                ],
            ],
        ];
    }
}
