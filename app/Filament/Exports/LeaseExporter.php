<?php

namespace App\Filament\Exports;

use App\Models\Lease;
use App\Support\DataTransferNotice;
use App\Support\Filament\CustomFieldsTable;
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

            // The operator's own fields (D-7), LAST so the shipped column positions a
            // colleague's import template depends on never move.
            ...CustomFieldsTable::exportColumns('lease'),
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
