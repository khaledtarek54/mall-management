<?php

namespace App\Filament\Imports;

use App\Models\Asset;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

class LeaseImporter extends Importer
{
    protected static ?string $model = Lease::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('tenant_email')
                ->label(__('admin.tables.tenant.email'))
                ->requiredMapping()
                ->rules(['required', 'email'])
                ->fillRecordUsing(function (Lease $record, string $state): void {
                    $tenant = Tenant::where('email', $state)->first();
                    if ($tenant) {
                        $record->tenant_id = $tenant->id;
                    }
                }),

            ImportColumn::make('asset_code')
                ->label(__('admin.tables.asset.code'))
                ->requiredMapping()
                ->rules(['required', 'max:20']),

            ImportColumn::make('unit_code')
                ->label(__('admin.tables.unit.code'))
                ->requiredMapping()
                ->rules(['required', 'max:20'])
                ->fillRecordUsing(function (Lease $record, string $state): void {
                    $assetCode = $this->data['asset_code'] ?? null;
                    if ($assetCode) {
                        $asset = Asset::withoutGlobalScopes()->where('code', $assetCode)->first();
                        if ($asset) {
                            $unit = Unit::where('asset_id', $asset->id)->where('code', $state)->first();
                            if ($unit) {
                                $record->unit_id = $unit->id;
                            }
                        }
                    }
                }),

            ImportColumn::make('reference')
                ->label(__('admin.fields.reference'))
                ->rules(['nullable', 'max:50']),

            ImportColumn::make('commencement_date')
                ->label(__('admin.fields.commencement_date'))
                ->requiredMapping()
                ->rules(['required', 'date']),

            ImportColumn::make('expiry_date')
                ->label(__('admin.fields.expiry_date'))
                ->rules(['nullable', 'date']),

            ImportColumn::make('term_months')
                ->label(__('admin.fields.term_months'))
                ->numeric()
                ->rules(['nullable', 'integer', 'min:1', 'max:120']),

            ImportColumn::make('base_rent_monthly')
                ->label(__('admin.fields.base_rent_monthly'))
                ->numeric()
                ->requiredMapping()
                ->rules(['required', 'numeric', 'min:0']),

            ImportColumn::make('service_charge_monthly')
                ->label(__('admin.fields.service_charge_monthly'))
                ->numeric()
                ->rules(['nullable', 'numeric', 'min:0']),

            ImportColumn::make('security_deposit')
                ->label(__('admin.fields.security_deposit'))
                ->numeric()
                ->rules(['nullable', 'numeric', 'min:0']),

            ImportColumn::make('status')
                ->label(__('admin.tables.common.status'))
                ->rules(['nullable', 'in:draft,active,expired,renewed,terminated']),
        ];
    }

    public function resolveRecord(): ?Lease
    {
        // Match on reference if present, otherwise on (unit_id, commencement_date) uniqueness.
        $reference = $this->data['reference'] ?? null;

        if ($reference) {
            return Lease::firstOrNew(['reference' => $reference]);
        }

        return new Lease([
            'reference' => Lease::generateReference($this->data['asset_code'] ?? 'AW'),
        ]);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your lease import has completed and ' . number_format($import->successful_rows) . ' ' . str('row')->plural($import->successful_rows) . ' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to import.';
        }

        return $body;
    }

    public function getJobConnection(): ?string
    {
        return 'sync';
    }
}
