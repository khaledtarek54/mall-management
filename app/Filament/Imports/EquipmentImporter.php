<?php

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\ResolvesVisibleAssetByCode;
use App\Models\Equipment;
use App\Models\Trade;
use App\Models\Unit;
use App\Support\DataTransferNotice;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\Rule;

/**
 * Load the equipment register at cut-over — chillers, lifts, escalators, generators, pumps.
 *
 * The register a mall arrives with is a spreadsheet, and until now the only way in was the form,
 * one asset at a time: an operator onboarding a second mall types several hundred rows by hand or
 * does not have a register at all. The second outcome is the likely one, and it is expensive —
 * without equipment there are no service plans, so preventive maintenance has nothing to generate
 * work orders against and the whole of module 26's planned side stays empty.
 *
 * **Equipment is a MAINTENANCE record, not an accounting one**, and the distinction matters here
 * because the two registers look alike on a spreadsheet. A `FixedAsset` is what the balance sheet
 * carries and depreciates; an `Equipment` is what a technician is sent to. One chiller is usually
 * both, which is what `fixed_asset_id` is for — so this importer writes NOTHING to the ledger and
 * has no opening-balance question. Get that backwards and you either depreciate a lift twice or
 * cannot raise a work order against it.
 *
 * Property-scoped through `ResolvesVisibleAssetByCode`, like every other importer here: an import
 * bypasses the Create/Edit pages where `assertAssetInScope()` runs, so without the clamp a
 * restricted user could upload another mall's code and write into that mall's register.
 *
 * The TRADE is what routes a job to whoever can do it (module 26's spine), so it is resolved by
 * code and REFUSED when unknown rather than left null: equipment with no trade produces work
 * orders with no trade, which is the defect EG-14 exists to have ended.
 */
class EquipmentImporter extends Importer
{
    use ResolvesVisibleAssetByCode;

    protected static ?string $model = Equipment::class;

    public static function getColumns(): array
    {
        return [
            // The property this equipment stands in. Clamped — see the trait.
            ImportColumn::make('asset_code')
                ->label(__('admin.resources.asset.singular'))
                ->requiredMapping()
                ->rules(['required', 'string', static::assetInScopeRule()])
                ->fillRecordUsing(function (Equipment $record, string $state): void {
                    $record->asset_id = static::resolveVisibleAsset($state)?->id;
                }),

            // The operator's own tag — unique per property, and the key a re-import corrects on.
            ImportColumn::make('code')
                ->label(__('admin.fields.code'))
                ->requiredMapping()
                ->rules(['required', 'max:40']),

            ImportColumn::make('name_en')
                ->label(__('admin.fields.name_en'))
                ->requiredMapping()
                ->rules(['required', 'max:255']),

            // REQUIRED, like its English twin. A blank Arabic name is not a smaller problem here
            // than a blank English one: the panel is used in Arabic, and the column is NOT NULL, so
            // a row without it fails at the database with a message about a constraint rather than
            // about the file.
            ImportColumn::make('name_ar')
                ->label(__('admin.fields.name_ar'))
                ->requiredMapping()
                ->rules(['required', 'max:255']),

            // By CODE, and refused when unknown — see the class docblock.
            ImportColumn::make('trade_code')
                ->label(__('admin.facility.fields.trade'))
                ->requiredMapping()
                ->rules(['required', 'string', Rule::exists('trades', 'code')])
                ->fillRecordUsing(function (Equipment $record, string $state): void {
                    $record->trade_id = Trade::query()->where('code', $state)->value('id');
                }),

            ImportColumn::make('criticality')
                ->label(__('admin.facility.fields.criticality'))
                ->requiredMapping()
                ->rules(['required', Rule::in(Equipment::CRITICALITIES)]),

            // Where it physically is. A unit for a shop's own plant, free text for the rest — a
            // rooftop chiller belongs to no tenant, which is why the unit is optional and the
            // location is the fallback rather than the other way round.
            ImportColumn::make('unit_code')
                ->label(__('admin.resources.unit.singular'))
                ->rules(['nullable', 'string'])
                ->fillRecordUsing(function (Equipment $record, ?string $state): void {
                    if (blank($state)) {
                        return;
                    }

                    // Scoped to the row's OWN property, not merely to a visible one: a unit code is
                    // unique per mall, so an unscoped lookup would silently attach this mall's
                    // chiller to the identically-coded shop next door.
                    $record->unit_id = Unit::query()
                        ->where('asset_id', $record->asset_id)
                        ->where('code', $state)
                        ->value('id');
                }),

            ImportColumn::make('location')
                ->label(__('admin.fields.location'))
                ->rules(['nullable', 'max:255']),

            ImportColumn::make('notes')
                ->label(__('admin.fields.notes'))
                ->rules(['nullable', 'max:2000']),
        ];
    }

    public function resolveRecord(): ?Equipment
    {
        $asset = static::resolveVisibleAsset($this->data['asset_code'] ?? null);

        if (! $asset) {
            return null;
        }

        // Keyed on (property, code) — the same identity the register itself uses, so a second pass
        // CORRECTS a row rather than duplicating it. A migrating operator re-uploads a corrected
        // file more often than they upload a clean one.
        $existing = Equipment::query()
            ->where('asset_id', $asset->id)
            ->where('code', $this->data['code'] ?? '')
            ->first();

        return $existing ?? new Equipment([
            'asset_id' => $asset->id,
            'is_active' => true,
        ]);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return DataTransferNotice::forImport($import);
    }

    /** Queued in production, `sync` locally and in the suite — same as its siblings. */
    public function getJobConnection(): ?string
    {
        return config('imports.connection', 'sync');
    }
}
