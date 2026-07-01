<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\VendorBill;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Vendor bill (فاتورة مورد) — recognise the expense + the payable:
 *   Dr Expense (net, by category)  +  Dr VAT Recoverable (input VAT)
 *   Cr Accounts Payable (total)
 *
 * Posts once the bill is past draft (approved+); drafts/cancelled are skipped.
 */
class VendorBillJournalizer implements Journalizer
{
    /** bill.category → semantic expense role. Unknown → admin_expense. */
    private const EXPENSE_ROLE = [
        'maintenance' => 'maintenance_expense',
        'utilities' => 'utilities_expense',
        'cleaning_security' => 'cleaning_security_expense',
        'marketing' => 'marketing_expense',
        'admin' => 'admin_expense',
    ];

    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var VendorBill $bill */
        $bill = $source;

        if (! $bill->isPostable()) {
            return null;
        }

        $assetId = $bill->asset_id;
        $vat = round((float) $bill->vat_amount, 2);
        $total = round((float) $bill->total, 2);
        // Derive the net expense from total − VAT so the entry always balances to
        // the payable, even if a stored subtotal drifts from total − vat.
        $net = round($total - $vat, 2);

        if ($total <= 0) {
            return null;
        }

        // 'other' intentionally books to admin_expense; anything ELSE unmapped is a
        // new/typo'd category silently misclassifying a P&L line — flag it loudly.
        $expenseRole = self::EXPENSE_ROLE[$bill->category] ?? 'admin_expense';
        if (! isset(self::EXPENSE_ROLE[$bill->category]) && $bill->category !== 'other') {
            \Illuminate\Support\Facades\Log::warning(
                "VendorBillJournalizer: bill {$bill->number} has unmapped category '{$bill->category}'; booking to admin_expense."
            );
        }

        $lines = [[
            'ledger_account_id' => $this->accounts->id($expenseRole, $assetId),
            'debit' => $net,
            'credit' => 0,
            'asset_id' => $assetId,
        ]];

        if ($vat > 0) {
            $lines[] = [
                'ledger_account_id' => $this->accounts->id('vat_recoverable', $assetId),
                'debit' => $vat,
                'credit' => 0,
                'asset_id' => $assetId,
            ];
        }

        $lines[] = [
            'ledger_account_id' => $this->accounts->id('accounts_payable', $assetId),
            'debit' => 0,
            'credit' => $total,
            'asset_id' => $assetId,
        ];

        return [
            'entry_date' => $bill->bill_date,
            'description_en' => 'Vendor bill '.$bill->number,
            'description_ar' => 'فاتورة مورد '.$bill->number,
            'asset_id' => $assetId,
            'lines' => $lines,
        ];
    }
}
