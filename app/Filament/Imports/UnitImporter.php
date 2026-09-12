<?php

namespace App\Filament\Imports;

use App\Models\Asset;
use App\Models\Floor;
use App\Models\Unit;
use App\Support\AreaFitsTheProperty;
use App\Support\DataTransferNotice;
use App\Support\Filament\CustomFieldsTable;
use App\Support\TenantScope;
use App\Support\ValueSets;
use Closure;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
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
    /**
     * Public because the area-ceiling rule below is an anonymous class and cannot reach a private
     * static on its enclosing class.
     */
    public static function resolveVisibleAsset(?string $code): ?Asset
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

    /**
     * A floor of this property, by the code the register holds — `floors` is `unique(asset_id,
     * code)`, so the code is its identity within a property and is what `UnitExporter` emits.
     *
     * Case-insensitively, because an operator's spreadsheet says `g` as readily as `G` and the
     * register is a fixed handful of rows. Global scopes are off for the same reason
     * {@see resolveVisibleAsset()} turns them off: the row names its own property and the import
     * runs outside a panel tenant.
     */
    public static function resolveFloor(Asset $asset, string $code): ?Floor
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        // DELIBERATELY NOT memoised in a static, though the rule and the fill each ask and so this
        // costs ~4 queries a row against a 5,000-row ceiling. CLAUDE.md's rule: a queue worker
        // OUTLIVES the request, so a static would serve one import's floors to the next one — and
        // since the key would be an `asset_id`, it would serve them ACROSS PROPERTIES. Trading a
        // background job's query count for a cross-property write is the wrong direction, and the
        // request-scoped container binding the rule states instead is not reachable from the
        // anonymous rule class this is called from. Filament's own `$resolvedRelatedRecords` is
        // per-IMPORTER-instance, which is the shape that would be safe here.
        return Floor::withoutGlobalScopes()
            ->where('asset_id', $asset->id)
            ->whereRaw('lower(code) = ?', [mb_strtolower($code)])
            ->first();
    }

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('asset_code')
                ->label(__('admin.tables.asset.code'))
                ->requiredMapping()
                ->rules(['required', 'max:20', function (string $attribute, $value, Closure $fail) {
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

            // `units.floor` was DROPPED by `2026_08_10_160000_create_floors_and_move_units_onto_them`
            // and this column was never moved onto the register that replaced it — so it had no
            // `fillRecordUsing` and no `relationship()`, and `ImportColumn::fillRecord()` fell
            // through to `data_set($record, 'floor', $state)`. A Model is `ArrayAccess`, so that
            // sets an ATTRIBUTE named for a column that does not exist, and the insert failed with
            // a raw `SQLSTATE[42S22]: Unknown column 'floor'` — on EVERY row, and on a BLANK cell
            // too, because `isBlankStateIgnored()` defaults to false so the fill still runs. Mapping
            // the column at all put a database error string in front of the operator where a field
            // message belongs (the SW-243 shape).
            //
            // It resolves the FLOOR REGISTER by code now, scoped to the row's own property —
            // `floors` is `unique(asset_id, code)`, so the code is the identity — which is also
            // what `UnitExporter` emits as `floor.code`, so a file exported here re-imports.
            // A floor that does not exist is REFUSED rather than created: a floor is property
            // master data with a `level` that orders every plan and report, and inventing one from
            // a spreadsheet cell would guess at that ordinal.
            ImportColumn::make('floor')
                ->label(__('admin.tables.unit.floor'))
                // A `DataAwareRule` rather than a plain closure, for the reason the area ceiling
                // below is one: the floor has to be looked for in the property THIS ROW names, and
                // `getColumns()` is static — `$this->data` in a closure declared here is unbound.
                // `max:16`, not the unit code's 20: this cell is a LOOKUP KEY on `floors.code`,
                // which is `varchar(16)`. The SW-243 width gate cannot see it — it resolves widths
                // against the importer's own model, and `units` has no `floor` column, so the width
                // comes back null and the column is skipped. Consequence today is only which
                // message the operator reads, since a 17-character code cannot exist; the shape —
                // an importer column keyed on ANOTHER table — is what the gate is blind to.
                ->rules(['nullable', 'max:16', new class implements DataAwareRule, ValidationRule
                {
                    /** @var array<string, mixed> */
                    protected array $data = [];

                    /** @param  array<string, mixed>  $data */
                    public function setData(array $data): static
                    {
                        $this->data = $data;

                        return $this;
                    }

                    public function validate(string $attribute, mixed $value, Closure $fail): void
                    {
                        if (blank($value)) {
                            return;
                        }

                        $code = $this->data['asset_code'] ?? null;
                        $asset = UnitImporter::resolveVisibleAsset(is_string($code) ? $code : null);

                        // The asset_code rule already failed this row; do not report it twice.
                        if ($asset === null) {
                            return;
                        }

                        if (UnitImporter::resolveFloor($asset, (string) $value) === null) {
                            $fail(__('admin.validation.import_floor_not_found', ['code' => $value]));
                        }
                    }
                }])
                ->fillRecordUsing(function (Unit $record, ?string $state): void {
                    // Blank CLEARS the floor rather than being ignored — a re-import is the
                    // operator restating the row, and silently keeping a floor they blanked would
                    // make the column un-clearable through the door they used to set it.
                    if (blank($state)) {
                        $record->floor_id = null;

                        return;
                    }

                    // `asset_id` is already stamped: Filament fills columns in declaration order
                    // and `asset_code` is declared first.
                    $asset = $record->asset_id ? Asset::withoutGlobalScopes()->find($record->asset_id) : null;

                    if ($asset !== null) {
                        $record->floor_id = self::resolveFloor($asset, $state)?->id;
                    }
                }),

            ImportColumn::make('category')
                ->label(__('admin.tables.unit.category'))
                ->rules(['nullable', Rule::in(ValueSets::allowed('units', 'category'))]),

            ImportColumn::make('area_sqm')
                ->label(__('admin.tables.unit.area'))
                // The column read *Area* until 2026-09-12, when the net area joined it and the
                // label became *Gross area*; a template exported under the old header still maps.
                ->guess(['Area', 'المساحة'])
                ->numeric()
                // `min:0` accepted a zero-area unit, which the form has always refused — a second
                // door carrying a weaker bound than the first. The ceiling is the third door onto
                // this column (form, remeasure service, here): a shop cannot measure more than the
                // whole lettable part of the mall it is being imported into.
                // The ceiling needs the ROW's property, not just this cell, so it is a DataAwareRule
                // rather than a plain closure — Filament validates the whole row as one array, which
                // is what makes `asset_code` reachable from here.
                ->rules(['nullable', 'numeric', 'min:0.01', new class implements DataAwareRule, ValidationRule
                {
                    /** @var array<string, mixed> */
                    protected array $data = [];

                    /** @param array<string, mixed> $data */
                    public function setData(array $data): static
                    {
                        $this->data = $data;

                        return $this;
                    }

                    public function validate(string $attribute, mixed $value, Closure $fail): void
                    {
                        if (blank($value)) {
                            return;
                        }

                        $code = $this->data['asset_code'] ?? null;
                        $asset = UnitImporter::resolveVisibleAsset(is_string($code) ? $code : null);

                        if (AreaFitsTheProperty::exceeds((float) $value, $asset)) {
                            $fail(AreaFitsTheProperty::message((float) $value, $asset));
                        }
                    }
                }]),

            // The NET area (point 20) — informational, so it carries no ceiling of its own beyond
            // the gross it sits inside; a stated net above the row's gross is refused in words
            // BELOW, in `beforeSave()`, because the pair may straddle the file (a file whose gross
            // column is UNMAPPED leaves the unit's own gross on a re-import — a MAPPED blank cell
            // is null, which `Unit::updating` refuses for the gross as it always has) and a rule
            // on one cell cannot see the other half where it lives. A mapped blank NET cell
            // CLEARS — the `floor` column's rule, so the column is settable back through the door
            // that set it.
            ImportColumn::make('net_area_sqm')
                ->label(__('admin.tables.unit.net_area'))
                ->numeric()
                ->rules(['nullable', 'numeric', 'min:0.01']),

            ImportColumn::make('status')
                ->label(__('admin.tables.common.status'))
                // 'occupied'/'reserved' are projections of a lease, not importable values — only
                // 'vacant' (default) and the manual 'maintenance' override may be set directly.
                // Deliberately NOT read from ValueSets like `category` above: this is a narrower
                // rule than the column accepts, and deriving it would widen the importer.
                ->rules(['nullable', 'in:vacant,maintenance']),

            // The operator's own fields (D-7), LAST so an existing mapping template's column
            // order is untouched. Optional: a sheet that names none imports as it always did.
            ...CustomFieldsTable::importColumns('unit'),
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

    /**
     * The gross/net pair, asked of the ROW as it will be saved — the file's gross where that
     * column is mapped, else the gross the unit already carries. `Unit::saving` refuses the same
     * thing, but a model refusal under an importer is a failed row with no sentence: only a
     * `RowImportFailedException` reaches the failed-rows file in words (the `FixedAssetImporter`
     * idiom).
     */
    protected function beforeSave(): void
    {
        /** @var Unit $unit */
        $unit = $this->record;

        if (Unit::netAreaExceedsGross($unit->net_area_sqm, $unit->area_sqm)) {
            throw new RowImportFailedException(Unit::netAreaRefusal($unit->net_area_sqm, $unit->area_sqm));
        }
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return DataTransferNotice::forImport($import);
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
