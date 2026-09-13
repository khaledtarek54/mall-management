<?php

namespace App\Filament\Exports;

use App\Models\PostDatedCheque;
use App\Support\DataTransferNotice;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * The post-dated cheque register in a spreadsheet (the reports audit, 2026-09-12) — كشف الشيكات
 * تحت التحصيل, the sheet an Egyptian accountant keeps by hand when the system has none: the
 * drawer's bank printed on the cheque, the bank it was LODGED with, its maturity and its state.
 */
class PostDatedChequeExporter extends Exporter
{
    protected static ?string $model = PostDatedCheque::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('reference')->label(__('admin.post_dated_cheques.fields.reference')),
            ExportColumn::make('tenant.name')->label(__('admin.post_dated_cheques.fields.tenant')),
            ExportColumn::make('asset.name')->label(__('admin.post_dated_cheques.fields.property')),
            ExportColumn::make('cheque_number')->label(__('admin.post_dated_cheques.fields.cheque_number')),
            ExportColumn::make('bank_name')->label(__('admin.post_dated_cheques.fields.bank_name')),
            ExportColumn::make('amount')->label(__('admin.post_dated_cheques.fields.amount')),
            ExportColumn::make('cheque_date')->label(__('admin.post_dated_cheques.fields.cheque_date')),
            ExportColumn::make('bankAccount.name')->label(__('admin.post_dated_cheques.fields.bank_account')),
            ExportColumn::make('deposited_on')->label(__('admin.post_dated_cheques.fields.deposited_on')),
            ExportColumn::make('status')->label(__('admin.post_dated_cheques.fields.status')),
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
