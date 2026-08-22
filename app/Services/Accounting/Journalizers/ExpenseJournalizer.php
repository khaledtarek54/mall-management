<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\Expense;
use App\Models\TaxCode;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\Journalizers\Concerns\MapsExpenseCategory;
use App\Support\MoneyAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * Direct / petty-cash expense (مصروف مباشر) — paid immediately from cash/bank:
 *   Dr Expense (net, by category)  +  Dr VAT Recoverable (input VAT)
 *   Cr Cash / Bank (total)
 *
 * Posts a `recorded` expense; a cancelled one is skipped (sync voids any entry).
 */
class ExpenseJournalizer implements Journalizer
{
    use MapsExpenseCategory;

    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var Expense $expense */
        $expense = $source;

        if (! $expense->isPostable()) {
            return null;
        }

        $assetId = $expense->asset_id;
        $vat = round((float) $expense->vat_amount, 2);
        $total = round((float) $expense->total, 2);
        $net = round($total - $vat, 2);

        if ($total <= 0) {
            return null;
        }

        // The category's own account, falling back to the role map. See MapsExpenseCategory.
        $expenseAccountId = $this->expenseAccountIdFor($expense->category, $assetId, $this->accounts, "expense {$expense->number}");

        // Through the rail, like the others. This mirror ternary was CORRECT while the column
        // held only cash|bank — but the catalogue widens `expenses.paid_from`, and `bank_transfer`
        // fell to the else branch and credited CASH for money that left the bank.
        $cashAccountId = MoneyAccount::for($expense->bank_account_id, $expense->paid_from, $assetId, $this->accounts);

        $lines = [];
        // Guard net > 0 — a pure-VAT expense (net 0) would otherwise emit a
        // debit-0/credit-0 line that the posting engine rejects.
        if ($net > 0) {
            $lines[] = [
                'ledger_account_id' => $expenseAccountId,
                'debit' => $net,
                'credit' => 0,
                'asset_id' => $assetId,
            ];
        }

        if ($vat > 0) {
            // The input-tax account comes from the expense's own `tax_code`, with `vat_recoverable`
            // as the floor — the same rule as `VendorBillJournalizer`, and it has to be the same
            // rule: stamp duty settled from petty cash is no more recoverable than stamp duty on a
            // supplier's bill, and leaving this one hard-coded would have made the account depend on
            // which door the cost came through.
            $taxRole = ($expense->tax_code ? TaxCode::postingRoleOf((string) $expense->tax_code) : null)
                ?? 'vat_recoverable';

            $lines[] = [
                'ledger_account_id' => $this->accounts->id($taxRole, $assetId),
                'debit' => $vat,
                'credit' => 0,
                'asset_id' => $assetId,
            ];
        }

        $lines[] = [
            'ledger_account_id' => $cashAccountId,
            'debit' => 0,
            'credit' => $total,
            'asset_id' => $assetId,
        ];

        return [
            'entry_date' => $expense->expense_date,
            'description_en' => 'Expense '.$expense->number,
            'description_ar' => 'مصروف '.$expense->number,
            // The narrative is a KEY resolved at READ time (EG-36); the prose above stays as
            // the snapshot and the floor for anything that does not go through the resolver.
            'description_key' => 'expense.posted',
            'description_data' => ['number' => $expense->number],
            'asset_id' => $assetId,
            'lines' => $lines,
        ];
    }
}
