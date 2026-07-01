<?php

namespace App\Services\Accounting\Journalizers\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Shared mapping from a document's expense `category` to a semantic expense role,
 * used by both VendorBillJournalizer and ExpenseJournalizer so the map can't drift
 * between them. 'other' books to admin_expense by design; any other unmapped
 * category is flagged loudly (a new/typo'd category silently misclassifying a P&L
 * line would otherwise hide behind a green tie-out).
 */
trait MapsExpenseCategory
{
    /** category → semantic expense role. */
    private const EXPENSE_ROLE = [
        'maintenance' => 'maintenance_expense',
        'utilities' => 'utilities_expense',
        'cleaning_security' => 'cleaning_security_expense',
        'marketing' => 'marketing_expense',
        'admin' => 'admin_expense',
    ];

    protected function expenseRoleFor(string $category, string $documentRef): string
    {
        if (isset(self::EXPENSE_ROLE[$category])) {
            return self::EXPENSE_ROLE[$category];
        }

        if ($category !== 'other') {
            Log::warning(
                static::class.": {$documentRef} has unmapped category '{$category}'; booking to admin_expense."
            );
        }

        return 'admin_expense';
    }
}
