<?php

namespace App\Filament\Exports;

use App\Models\Lease;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class LeaseExporter extends Exporter
{
    protected static ?string $model = Lease::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('reference')->label(__('admin.tables.lease.reference')),
            ExportColumn::make('tenant.name')->label(__('admin.tables.lease.tenant')),
            ExportColumn::make('unit.code')->label(__('admin.tables.lease.unit')),
            ExportColumn::make('base_rent_monthly')->label(__('admin.tables.lease.rent')),
            ExportColumn::make('service_charge_monthly')->label(__('admin.fields.service_charge_monthly')),
            ExportColumn::make('commencement_date')->label(__('admin.tables.lease.start')),
            ExportColumn::make('expiry_date')->label(__('admin.tables.lease.ends')),
            ExportColumn::make('term_months')->label(__('admin.fields.term_months')),
            ExportColumn::make('status')->label(__('admin.tables.common.status')),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your lease export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';
    }

    public function getJobConnection(): ?string
    {
        return config('exports.connection', 'sync');
    }
}
