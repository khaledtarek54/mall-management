<?php

namespace App\Filament\Exports;

use App\Models\Payment;
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
            ExportColumn::make('status')->label(__('admin.tables.common.status')),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your payment export has completed and ' . number_format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';
    }

    public function getJobConnection(): ?string
    {
        return config('exports.connection', 'sync');
    }
}
