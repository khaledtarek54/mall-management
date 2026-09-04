<?php

namespace App\Filament\Exports;

use App\Models\Vendor;
use App\Support\DataTransferNotice;
use App\Support\Exports;
use App\Support\Filament\CustomFieldsTable;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * The supplier register as a spreadsheet.
 *
 * Vendors and properties were the two resources an operator could import into and never export out
 * of — a one-way door, and the reason a custom field on a vendor could be recorded and not taken
 * away. Whoever may read the list may take it away; the gate is the resource's own `canViewAny()`
 * through {@see Exports}, exactly as it is for the other seven.
 *
 * `code` leads, for the same reason it does on the tenant export: the point of the file is to be
 * re-imported or reconciled against another system, and the code is the key both sides join on —
 * it is also what `VendorImporter` dedups against.
 */
class VendorExporter extends Exporter
{
    protected static ?string $model = Vendor::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('code')->label(__('admin.fields.vendor_code')),
            ExportColumn::make('name')->label(__('admin.fields.name')),
            ExportColumn::make('legal_name')->label(__('admin.fields.legal_name')),
            ExportColumn::make('tax_id')->label(__('admin.fields.tax_id')),
            ExportColumn::make('email')->label(__('admin.fields.email')),
            ExportColumn::make('phone')->label(__('admin.fields.phone')),
            ExportColumn::make('city')->label(__('admin.fields.city')),
            ExportColumn::make('status')->label(__('admin.tables.common.status')),
            // The operator's own fields (D-7), LAST so the shipped column positions a colleague's
            // import template depends on never move.
            ...CustomFieldsTable::exportColumns('vendor'),
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
