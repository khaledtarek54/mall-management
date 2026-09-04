<?php

namespace App\Filament\Exports;

use App\Models\Payment;
use App\Support\DataTransferNotice;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class PaymentExporter extends Exporter
{
    protected static ?string $model = Payment::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('reference')->label(__('admin.tables.payment.reference')),
            ExportColumn::make('tenant.name')->label(__('admin.tables.payment.tenant')),
            ExportColumn::make('payment_date')->label(__('admin.tables.payment.date')),
            ExportColumn::make('amount')->label(__('admin.tables.payment.amount')),
            ExportColumn::make('method')->label(__('admin.tables.payment.method')),
            // Which bank the money actually landed in. The list gained a column and a filter for
            // this (EG-12) and the export did not, so narrowing to CIB and clicking Export produced
            // a CSV that could not be told from an NBE one — in the tool where a bank
            // reconciliation is actually done. Filament's export path applies filters but renders
            // `ExportColumn`s, so a table column alone reaches none of it.
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
