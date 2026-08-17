<?php

namespace App\Filament\Exports;

use App\Models\Unit;
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
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your unit export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';
    }

    public function getJobConnection(): ?string
    {
        return config('exports.connection', 'sync');
    }
}
