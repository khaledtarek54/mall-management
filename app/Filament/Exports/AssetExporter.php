<?php

namespace App\Filament\Exports;

use App\Models\Asset;
use App\Support\DataTransferNotice;
use App\Support\Filament\CustomFieldsTable;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * The property portfolio as a spreadsheet — the other half of the export gap.
 *
 * Small by row count and not by usefulness: it is the list an owner, a valuer or an insurer asks
 * for, and it was the one register with no way out of the system at all.
 *
 * Areas are exported as the raw numbers rather than formatted, so a spreadsheet reads them as
 * numbers — the same rule `ReportCsvExporter` follows for money.
 */
class AssetExporter extends Exporter
{
    protected static ?string $model = Asset::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('code')->label(__('admin.fields.code')),
            ExportColumn::make('name')->label(__('admin.fields.name')),
            ExportColumn::make('type')->label(__('admin.fields.type')),
            ExportColumn::make('city')->label(__('admin.fields.city')),
            ExportColumn::make('address')->label(__('admin.fields.address')),
            ExportColumn::make('total_area_sqm')->label(__('admin.fields.total_area_sqm')),
            ExportColumn::make('leasable_area_sqm')->label(__('admin.fields.leasable_area_sqm')),
            ExportColumn::make('is_active')->label(__('admin.fields.is_active')),
            // The operator's own fields (D-7), LAST — see VendorExporter.
            ...CustomFieldsTable::exportColumns('asset'),
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
