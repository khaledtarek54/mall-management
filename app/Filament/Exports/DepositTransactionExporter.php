<?php

namespace App\Filament\Exports;

use App\Models\DepositTransaction;
use App\Support\DataTransferNotice;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * The security-deposit register in a spreadsheet (the reports audit, 2026-09-12) — every receipt,
 * refund and forfeit with its tenant, lease and bank, the schedule behind the `deposits_held`
 * liability an auditor ties to the balance sheet.
 */
class DepositTransactionExporter extends Exporter
{
    protected static ?string $model = DepositTransaction::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('number')->label(__('admin.fields.deposit_number')),
            ExportColumn::make('type')->label(__('admin.filters.type')),
            ExportColumn::make('lease.tenant.name')->label(__('admin.filters.tenant')),
            ExportColumn::make('lease.reference')->label(__('admin.fields.lease')),
            ExportColumn::make('asset.name')->label(__('admin.fields.property')),
            ExportColumn::make('transaction_date')->label(__('admin.fields.transaction_date')),
            ExportColumn::make('amount')->label(__('admin.fields.amount')),
            ExportColumn::make('method')->label(__('admin.fields.method')),
            ExportColumn::make('bankAccount.name')->label(__('admin.resources.bank_account.singular')),
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
