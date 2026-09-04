<?php

namespace App\Filament\Exports;

use App\Models\Unit;
use App\Support\DataTransferNotice;
use App\Support\Filament\CustomFieldsTable;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class UnitExporter extends Exporter
{
    protected static ?string $model = Unit::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('code')->label(__('admin.tables.unit.code')),
            ExportColumn::make('asset.name')->label(__('admin.filters.asset')),
            ExportColumn::make('floor')->label(__('admin.pdf.floor')),
            ExportColumn::make('category')->label(__('admin.tables.unit.category')),
            ExportColumn::make('area_sqm')->label(__('admin.tables.unit.area')),
            ExportColumn::make('activeLease.tenant.name')->label(__('admin.tables.unit.tenant')),
            ExportColumn::make('activeLease.base_rent_monthly')->label(__('admin.tables.unit.rent')),
            ExportColumn::make('activeLease.expiry_date')->label(__('admin.widgets.top_tenants.lease_ends')),
            ExportColumn::make('status')->label(__('admin.tables.common.status')),

            // The operator's own fields (D-7), LAST so the shipped column positions a
            // colleague's import template depends on never move.
            ...CustomFieldsTable::exportColumns('unit'),
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
