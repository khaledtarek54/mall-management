<?php

namespace App\Filament\Exports;

use App\Models\VendorBill;
use App\Support\DataTransferNotice;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * The supplier-bill register in a spreadsheet (the reports audit, 2026-09-12).
 *
 * The payables ledger an accountant reconciles supplier statements against. Both the bill's own
 * number and the SUPPLIER's reference travel, because a supplier statement quotes theirs; `due_date`
 * because the aged-payables report ages by it and a filtered export (this supplier, unpaid) is what
 * goes into the payment meeting.
 */
class VendorBillExporter extends Exporter
{
    protected static ?string $model = VendorBill::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('number')->label(__('admin.tables.vendor_bill.number')),
            ExportColumn::make('vendor.name')->label(__('admin.fields.vendor')),
            ExportColumn::make('vendor.code')->label(__('admin.fields.vendor_code')),
            ExportColumn::make('asset.name')->label(__('admin.fields.property')),
            ExportColumn::make('category')->label(__('admin.fields.category')),
            ExportColumn::make('bill_date')->label(__('admin.fields.bill_date')),
            ExportColumn::make('due_date')->label(__('admin.fields.due_date')),
            ExportColumn::make('reference')->label(__('admin.fields.reference')),
            ExportColumn::make('subtotal')->label(__('admin.fields.subtotal')),
            ExportColumn::make('vat_amount')->label(__('admin.fields.vat_amount')),
            ExportColumn::make('total')->label(__('admin.fields.total')),
            ExportColumn::make('paid_amount')->label(__('admin.fields.paid_amount')),
            ExportColumn::make('balance')->label(__('admin.fields.balance')),
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
