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
            // The deposit clause, so a round trip carries it (meeting 2026-09-02, point 3): the
            // agreed figure, how it was agreed, and the multiple or percentage behind it — under
            // the same `admin.fields.*` labels the importer guesses on.
            ExportColumn::make('security_deposit')->label(__('admin.fields.security_deposit')),
            ExportColumn::make('security_deposit_basis')->label(__('admin.fields.security_deposit_basis')),
            ExportColumn::make('security_deposit_months')->label(__('admin.fields.security_deposit_months')),
            ExportColumn::make('security_deposit_percent')->label(__('admin.fields.security_deposit_percent')),

            // The percentage-rent clause and the sales-reporting duty, under the labels the importer
            // guesses on so a re-import maps itself (SW-255). `1`/`0`, and BLANK for a duty nobody
            // has ruled on — that null is the normal state and must survive the round trip.
            ExportColumn::make('has_percentage_rent')->label(__('admin.fields.has_percentage_rent'))
                ->state(fn (Lease $record): int => $record->has_percentage_rent ? 1 : 0),
            ExportColumn::make('requires_sales_reporting')->label(__('admin.fields.requires_sales_reporting'))
                ->state(fn (Lease $record): ?int => $record->requires_sales_reporting === null ? null : ($record->requires_sales_reporting ? 1 : 0)),

            // The clause's terms and the proration method: every column the importer takes, so a
            // full export re-imports as the same lease and not as a half of one.
            ExportColumn::make('percentage_rent_rate')->label(__('admin.imports.columns.percentage_rent_rate')),
            ExportColumn::make('percentage_rent_calculation_type')->label(__('admin.imports.columns.percentage_rent_calculation_type')),
            ExportColumn::make('percentage_rent_threshold')->label(__('admin.imports.columns.percentage_rent_threshold')),
            ExportColumn::make('percentage_rent_frequency')->label(__('admin.imports.columns.percentage_rent_frequency')),
            ExportColumn::make('proration_method')->label(__('admin.fields.proration_method')),

            // The operator's own fields (D-7), LAST — Filament maps a re-import by LABEL, so what a
            // colleague's template depends on is the headers, which these keep out of the way of.
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
