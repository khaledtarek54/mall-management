<?php

namespace App\Filament\Exports;

use App\Models\Expense;
use App\Support\DataTransferNotice;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * The expense register in a spreadsheet (the reports audit, 2026-09-12) — the direct and petty-cash
 * costs, with the rail and the bank each one moved through (EG-12), so a filtered export is the
 * cash-box or bank-statement reconciliation sheet.
 */
class ExpenseExporter extends Exporter
{
    protected static ?string $model = Expense::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('number')->label(__('admin.fields.expense_number')),
            ExportColumn::make('asset.name')->label(__('admin.fields.property')),
            ExportColumn::make('category')->label(__('admin.fields.category')),
            // Not a column: derived from the category through `CostNature`, exactly as the list
            // derives it. A bare name here rendered a blank cell under a "Cost nature" header on
            // every row, and neither cell-value gate can see a blank (found by review).
            ExportColumn::make('cost_nature')->label(__('admin.fields.cost_nature'))
                ->state(fn (Expense $record): string => $record->costNature()),
            ExportColumn::make('paid_from')->label(__('admin.fields.paid_from')),
            ExportColumn::make('bankAccount.name')->label(__('admin.resources.bank_account.singular')),
            ExportColumn::make('expense_date')->label(__('admin.fields.expense_date')),
            ExportColumn::make('amount')->label(__('admin.fields.amount')),
            ExportColumn::make('vat_amount')->label(__('admin.fields.vat_amount')),
            ExportColumn::make('total')->label(__('admin.fields.total')),
            ExportColumn::make('status')->label(__('admin.tables.common.status')),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return DataTransferNotice::forExport($export);
    }

    public function getJobConnection(): ?string
    {
        return config('exports.connection', 'sync');
    }
}
