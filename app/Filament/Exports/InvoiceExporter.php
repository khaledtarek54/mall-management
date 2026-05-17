<?php

namespace App\Filament\Exports;

use App\Models\Invoice;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class InvoiceExporter extends Exporter
{
    protected static ?string $model = Invoice::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('number')->label(__('admin.tables.invoice.number')),
            ExportColumn::make('tenant.name')->label(__('admin.tables.invoice.tenant')),
            ExportColumn::make('lease.unit.code')->label(__('admin.tables.invoice.unit')),
            ExportColumn::make('period_start')->label(__('admin.filters.period_from')),
            ExportColumn::make('issue_date')->label(__('admin.filters.issued_from')),
            ExportColumn::make('due_date')->label(__('admin.tables.invoice.due_date')),
            ExportColumn::make('subtotal')->label('Subtotal'),
            ExportColumn::make('vat_total')->label('VAT'),
            ExportColumn::make('total')->label(__('admin.tables.invoice.total')),
            ExportColumn::make('paid_amount')->label(__('admin.tables.invoice.paid')),
            ExportColumn::make('balance')->label(__('admin.tables.invoice.balance')),
            ExportColumn::make('status')->label(__('admin.tables.common.status')),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your invoice export has completed and ' . number_format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';
        if ($failed = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failed) . ' ' . str('row')->plural($failed) . ' failed to export.';
        }
        return $body;
    }

    public function getJobConnection(): ?string
    {
        return 'sync';
    }
}
