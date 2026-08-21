<?php

namespace App\Services\Accounting\Journalizers\Concerns;

use App\Models\ExpenseCategory;
use App\Services\Accounting\AccountResolver;
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

    /**
     * The P&L ACCOUNT a cost books to: the category's own row first, this map as the floor.
     *
     * Every journalizer that classifies a cost goes through here, so the catalogue and the const
     * cannot disagree. A category with no row, or a row with no account, resolves exactly as it did
     * before — including the warning above, which is still the right noise for a category nobody
     * has classified.
     */
    protected function expenseAccountIdFor(
        ?string $category,
        ?int $assetId,
        AccountResolver $accounts,
        string $documentRef,
    ): int {
        // The floor role is resolved LAZILY. Passing `expenseRoleFor()` in as an argument evaluates
        // it every time — including for a category that has its own account and never reaches the
        // floor at all — so a correctly-classified `insurance` bill logged "unmapped category
        // 'insurance'; booking to admin_expense" on every posting while booking correctly. A warning
        // that fires when nothing is wrong is how a real one stops being read.
        return ExpenseCategory::accountIdOrFloor(
            $category,
            $assetId,
            $accounts,
            fn (): string => $this->expenseRoleFor((string) ($category ?? 'other'), $documentRef),
        );
    }
}
