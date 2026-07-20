<?php

namespace App\Filament\Exports;

use App\Models\CreditNote;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class CreditNoteExporter extends Exporter
{
    protected static ?string $model = CreditNote::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('number')->label(__('admin.tables.credit_note.number')),
            ExportColumn::make('tenant.name')->label(__('admin.tables.credit_note.tenant')),
            ExportColumn::make('invoice.number')->label(__('admin.fields.invoice')),
            ExportColumn::make('reason')->label(__('admin.fields.credit_note_reason')),
            ExportColumn::make('issue_date')->label(__('admin.fields.issue_date')),
            ExportColumn::make('subtotal')->label(__('admin.pdf.subtotal')),
            ExportColumn::make('vat_amount')->label(__('admin.pdf.vat')),
            ExportColumn::make('total')->label(__('admin.tables.credit_note.total')),
            ExportColumn::make('applied_amount')->label(__('admin.tables.credit_note.applied')),
            ExportColumn::make('balance')->label(__('admin.tables.credit_note.balance')),
            ExportColumn::make('status')->label(__('admin.tables.common.status')),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your credit note export has completed and ' . number_format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';
    }

    public function getJobConnection(): ?string
    {
        return config('exports.connection', 'sync');
    }
}
