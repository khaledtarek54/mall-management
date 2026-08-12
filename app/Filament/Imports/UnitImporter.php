<?php

namespace App\Filament\Imports;

use App\Models\Asset;
use App\Models\Unit;
use App\Support\TenantScope;
use App\Support\ValueSets;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\Rule;

class UnitImporter extends Importer
{
    protected static ?string $model = Unit::class;

    /**
     * Resolve an asset by code, CLAMPED to the importing user's visible properties. The import
     * bypasses CreateUnit/EditUnit — the only place GuardsAssetInScope::assertAssetInScope runs —
     * so without this a restricted user could upload a CSV row for another mall's code and
     * create/overwrite its units (cross-property WRITE leak). null visibleAssetIds() = unrestricted
     * (super_admin); otherwise the asset must be in the visible set or this returns null (row fails).
     */
    private static function resolveVisibleAsset(?string $code): ?Asset
    {
        if (! $code) {
            return null;
        }
        $asset = Asset::withoutGlobalScopes()->where('code', $code)->first();
        if (! $asset) {
            return null;
        }
        $visible = TenantScope::visibleAssetIds();

        return ($visible === null || in_array($asset->id, $visible, true)) ? $asset : null;
    }

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('asset_code')
                ->label(__('admin.tables.asset.code'))
                ->requiredMapping()
                ->rules(['required', 'max:20', function (string $attribute, $value, \Closure $fail) {
                    if (self::resolveVisibleAsset(is_string($value) ? $value : null) === null) {
                        $fail(__('admin.validation.import_asset_out_of_scope'));
                    }
                }])
                ->fillRecordUsing(function (Unit $record, string $state): void {
                    $asset = self::resolveVisibleAsset($state);
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
                ->rules(['nullable', Rule::in(ValueSets::allowed('units', 'category'))]),

            ImportColumn::make('area_sqm')
                ->label(__('admin.tables.unit.area'))
                ->numeric()
                ->rules(['nullable', 'numeric', 'min:0']),

            ImportColumn::make('status')
                ->label(__('admin.tables.common.status'))
                // 'occupied'/'reserved' are projections of a lease, not importable values — only
                // 'vacant' (default) and the manual 'maintenance' override may be set directly.
                // Deliberately NOT read from ValueSets like `category` above: this is a narrower
                // rule than the column accepts, and deriving it would widen the importer.
                ->rules(['nullable', 'in:vacant,maintenance']),
        ];
    }

    public function resolveRecord(): ?Unit
    {
        // Match on asset_code + code so re-imports update rather than duplicate.
        $assetCode = $this->data['asset_code'] ?? null;
        $unitCode = $this->data['code'] ?? null;

        if ($assetCode && $unitCode) {
            $asset = self::resolveVisibleAsset(is_string($assetCode) ? $assetCode : null);
            if ($asset) {
                return Unit::firstOrNew([
                    'asset_id' => $asset->id,
                    'code' => $unitCode,
                ]);
            }
        }

        // Out-of-scope / unknown asset: the asset_code rule fails the row; return a bare record.
        return new Unit;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your unit import has completed and '.number_format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }

    /**
     * Queue the import rather than running it inline.
     *
     * Was a hard-coded `'sync'`, which no configuration could reach — so the cut-over ran inside
     * one HTTP request. `sync` remains the default (config/imports.php), so local work and the
     * suite are unchanged; production sets IMPORT_QUEUE_CONNECTION.
     */
    public function getJobConnection(): ?string
    {
        return config('imports.connection', 'sync');
    }

    /** A guard rail against a mis-mapped file, not a capacity limit. */
    public function getMaxRows(): ?int
    {
        return (int) config('imports.max_rows', 5000);
    }
}
