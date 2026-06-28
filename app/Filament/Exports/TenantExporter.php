<?php

namespace App\Filament\Exports;

use App\Models\Tenant;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class TenantExporter extends Exporter
{
    protected static ?string $model = Tenant::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')->label(__('admin.tables.tenant.name')),
            ExportColumn::make('legal_name')->label(__('admin.fields.legal_name')),
            ExportColumn::make('type')->label(__('admin.fields.type')),
            ExportColumn::make('phone')->label(__('admin.tables.tenant.phone')),
            ExportColumn::make('email')->label(__('admin.tables.tenant.email')),
            ExportColumn::make('contact_person')->label(__('admin.fields.contact_person')),
            ExportColumn::make('status')->label(__('admin.tables.common.status')),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your tenant export has completed and ' . number_format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';
    }

    public function getJobConnection(): ?string
    {
        return config('exports.connection', 'sync');
    }
}
