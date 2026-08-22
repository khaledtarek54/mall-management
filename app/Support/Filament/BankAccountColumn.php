<?php

namespace App\Support\Filament;

use Filament\Tables\Columns\TextColumn;

/**
 * The read-back half of {@see BankAccountField} — "which bank did this one go through?" on a list.
 *
 * The field shipped on six money forms with no column, no infolist entry and no filter anywhere, so
 * an operator could set the account and then never see it again: the one question the feature exists
 * to answer — *which documents went through CIB?* — was unanswerable from any register. A write-only
 * field reads as a field that does nothing.
 *
 * No `with('bankAccount')` is needed at any call site, and adding one would be a query wasted on
 * every page view: Filament eager-loads relationship columns itself, and only the VISIBLE ones
 * (`HasRecords::getFilteredTableQuery()` → `Column::applyEagerLoading()`), so a column toggled off
 * costs nothing at all.
 *
 * Toggled hidden by default. It is a second-order fact on every one of these lists (a payment is
 * about who paid and how much), and every list here is already wide; an operator reconciling turns
 * it on once and Filament remembers. Pair it with {@see BankAccountFilter}, which is the faster
 * answer to the same question.
 */
final class BankAccountColumn
{
    public static function make(string $name = 'bankAccount.name'): TextColumn
    {
        return TextColumn::make($name)
            ->label(__('admin.resources.bank_account.singular'))
            // The rail already decides where unnamed money posts, and that is the normal state —
            // so an empty cell is "the rail decides", not a gap someone forgot to fill.
            ->placeholder(__('admin.placeholders.bank_account_by_rail'))
            ->description(fn ($record) => $record->bankAccount?->bank_name)
            ->toggleable(isToggledHiddenByDefault: true)
            ->sortable();
    }
}
