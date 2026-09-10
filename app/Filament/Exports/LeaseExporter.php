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
            // The four columns `LeaseImporter` marks `requiredMapping()` all appear here, under the
            // labels IT guesses on — Filament writes an export's header row from these labels and
            // `ImportColumn::getGuesses()` unshifts its own, so a matching label maps itself and a
            // differing one is a door the operator has to guess at. Two were missing outright
            // (`asset_code`, `tenant_email`) and two were the SAME column under a second label
            // (`base_rent_monthly` as *Rent*, `commencement_date` as *Start*), which is the
            // `admin.fields.*` rule this codebase already states for the audit trail: the same word
            // for the same field. Found by `ExportedCellsAreValuesConformanceTest` on its first run.
            // `unit.asset.code`, not `asset.code`: a Lease has no `asset` relation — it reaches its
            // property through the unit, which is why `PropertyIsolation` registers it
            // `via: 'unit'`. Caught by the sibling gate in this same run, which is the pair working.
            ExportColumn::make('unit.asset.code')->label(__('admin.tables.asset.code')),
            ExportColumn::make('tenant.name')->label(__('admin.tables.lease.tenant')),
            ExportColumn::make('tenant.email')->label(__('admin.tables.tenant.email')),
            ExportColumn::make('unit.code')->label(__('admin.tables.lease.unit')),
            ExportColumn::make('base_rent_monthly')->label(__('admin.fields.base_rent_monthly')),
            ExportColumn::make('service_charge_monthly')->label(__('admin.fields.service_charge_monthly')),
            ExportColumn::make('commencement_date')->label(__('admin.fields.commencement_date')),
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
