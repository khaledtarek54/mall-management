<?php

namespace App\Filament\Exports;

use App\Models\TenantRequest;
use App\Support\DataTransferNotice;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * Exports the tenant-request / work-order queue (FR-REQ-12). Filament runs the export through the
 * resource's own query, so BOTH scoping primitives already apply: property scoping and
 * AssignmentScope (a restricted user only ever exports rows they can see). The header action is
 * additionally gated on `requests.view_all` — export is an oversight capability, not something a
 * technician who sees only their own work needs.
 */
class TenantRequestExporter extends Exporter
{
    protected static ?string $model = TenantRequest::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('reference')->label(__('admin.tables.requests.reference')),
            ExportColumn::make('title')->label(__('admin.tables.requests.title')),
            ExportColumn::make('request_type')->label(__('admin.fields.request_type')),
            ExportColumn::make('category')->label(__('admin.fields.subcategory')),
            ExportColumn::make('tenant.name')->label(__('admin.tables.requests.tenant')),
            // Who reported it when there is no registered tenant (FR-REQ intake).
            ExportColumn::make('caller_name')->label(__('admin.tenant_requests.caller.name')),
            ExportColumn::make('caller_phone')->label(__('admin.tenant_requests.caller.phone')),
            ExportColumn::make('unit.code')->label(__('admin.tables.requests.unit')),
            ExportColumn::make('priority')->label(__('admin.tables.requests.priority')),
            ExportColumn::make('status')->label(__('admin.tables.common.status')),
            ExportColumn::make('assignee.name')->label(__('admin.tables.requests.assigned_to')),
            ExportColumn::make('department.name')->label(__('admin.resources.department.singular')),
            ExportColumn::make('submitted_at')->label(__('admin.tables.requests.submitted')),
            ExportColumn::make('target_resolution_at')->label(__('admin.tables.requests.target')),
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
