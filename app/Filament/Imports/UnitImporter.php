<?php

namespace App\Filament\Imports;

use App\Models\Asset;
use App\Models\Unit;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

class UnitImporter extends Importer
{
    protected static ?string $model = Unit::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('asset_code')
                ->label(__('admin.tables.asset.code'))
                ->requiredMapping()
                ->rules(['required', 'max:20'])
                ->fillRecordUsing(function (Unit $record, string $state): void {
                    $asset = Asset::withoutGlobalScopes()->where('code', $state)->first();
                    if ($asset) {
                        $record->asset_id = $asset->id;
                    }
                }),

            ImportColumn::make('code')
                ->label(__('admin.tables.unit.code'))
                ->requiredMapping()
                ->rules(['required', 'max:20']),

            ImportColumn::make('floor')
                ->label(__('admin.tables.unit.floor'))
                ->rules(['nullable', 'max:20']),

            ImportColumn::make('category')
                ->label(__('admin.tables.unit.category'))
                ->rules(['nullable', 'in:retail,food_beverage,wellness,service,kiosk,office,storage']),

            ImportColumn::make('area_sqm')
                ->label(__('admin.tables.unit.area'))
                ->numeric()
                ->rules(['nullable', 'numeric', 'min:0']),

            ImportColumn::make('status')
                ->label(__('admin.tables.common.status'))
                ->rules(['nullable', 'in:vacant,reserved,occupied,maintenance']),
        ];
    }

    public function resolveRecord(): ?Unit
    {
        // Match on asset_code + code so re-imports update rather than duplicate.
        $assetCode = $this->data['asset_code'] ?? null;
        $unitCode = $this->data['code'] ?? null;

        if ($assetCode && $unitCode) {
            $asset = Asset::withoutGlobalScopes()->where('code', $assetCode)->first();
            if ($asset) {
                return Unit::firstOrNew([
                    'asset_id' => $asset->id,
                    'code' => $unitCode,
                ]);
            }
        }

        return new Unit;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your unit import has completed and ' . number_format($import->successful_rows) . ' ' . str('row')->plural($import->successful_rows) . ' imported.';

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
