<?php

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\ResolvesVisibleAssetByCode;
use App\Models\FixedAsset;
use App\Support\DataTransferNotice;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

/**
 * Load the fixed-asset register at cut-over — chillers, escalators, generators.
 *
 * These feed depreciation and the balance sheet from day one, which makes this the importer with
 * the most immediate accounting consequence: get it wrong and the first month's depreciation charge
 * and the first balance sheet are both wrong, in a way that compounds monthly.
 *
 * **Every imported asset is an OPENING BALANCE**, and that is not a checkbox on the file — it is
 * what importing means here. Two things follow, and neither is optional:
 *
 *  - **It posts no acquisition.** A 2023 chiller's cost is already inside the accountant's opening
 *    journal entry; posting `Dr Furniture & Equipment / Cr Cash` again would double it, or be
 *    refused for landing in a closed period and stranded inside the best-effort sync job. The
 *    importer sets `is_opening_balance` on every row and the journalizer returns null — the same
 *    rule, for the same reason, as `OpeningInvoiceImporter`.
 *  - **It carries the depreciation already taken.** Without
 *    `opening_accumulated_depreciation` a chiller three years into a ten-year life would depreciate
 *    its FULL cost again over another ten years, and the balance sheet would carry it at cost. The
 *    column is required for that reason: a blank is not "zero", it is "the operator has not told us",
 *    and a silent zero is the version of this that nobody notices for a year.
 *
 * Property-scoped through `ResolvesVisibleAssetByCode`, like `UnitImporter` and `LeaseImporter`: an
 * import bypasses the Create/Edit pages where `assertAssetInScope()` runs, so without the clamp a
 * restricted user could upload another mall's code and write to that mall's books.
 */
class FixedAssetImporter extends Importer
{
    use ResolvesVisibleAssetByCode;

    protected static ?string $model = FixedAsset::class;

    public static function getColumns(): array
    {
        return [
            // The property this asset stands in. Clamped — see the trait.
            ImportColumn::make('asset_code')
                ->label(__('admin.resources.asset.singular'))
                ->requiredMapping()
                ->rules(['required', 'string', static::assetInScopeRule()])
                ->fillRecordUsing(function (FixedAsset $record, string $state): void {
                    $record->asset_id = static::resolveVisibleAsset($state)?->id;
                }),

            ImportColumn::make('tag')
                ->label(__('admin.fixed_assets.fields.tag'))
                ->requiredMapping()
                ->rules(['required', 'max:40']),

            ImportColumn::make('name')
                ->label(__('admin.fields.name'))
                ->requiredMapping()
                ->rules(['required', 'max:255']),

            ImportColumn::make('category')
                ->label(__('admin.fields.category'))
                // Free-form on purpose (a string column, not an enum): the operator's own taxonomy.
                ->rules(['nullable', 'max:255']),

            ImportColumn::make('acquisition_date')
                ->label(__('admin.fixed_assets.fields.acquisition_date'))
                ->requiredMapping()
                // The real historical date, not the cut-over date. It posts nothing, but it is what
                // the register and the depreciation schedule are read against, and back-dating it
                // is the whole point of an opening balance.
                ->rules(['required', 'date']),

            ImportColumn::make('acquisition_cost')
                ->label(__('admin.fixed_assets.fields.acquisition_cost'))
                ->requiredMapping()
                ->numeric()
                ->rules(['required', 'numeric', 'min:0']),

            ImportColumn::make('opening_accumulated_depreciation')
                ->label(__('admin.fixed_assets.fields.opening_accumulated'))
                ->requiredMapping()
                ->numeric()
                // REQUIRED, deliberately. A blank would import as 0 and the asset would carry at
                // full cost while re-depreciating everything it has already written off. "The
                // operator did not say" and "nothing has been depreciated" are different answers
                // and only one of them is safe to assume.
                ->rules(['required', 'numeric', 'min:0']),

            ImportColumn::make('salvage_value')
                ->label(__('admin.fixed_assets.fields.salvage_value'))
                ->numeric()
                ->rules(['nullable', 'numeric', 'min:0']),

            ImportColumn::make('useful_life_months')
                ->label(__('admin.fixed_assets.fields.useful_life'))
                ->requiredMapping()
                ->integer()
                ->rules(['required', 'integer', 'min:1']),

            ImportColumn::make('notes')
                ->label(__('admin.fields.notes'))
                ->rules(['nullable', 'max:2000']),

            // `method` and `funded_from` are deliberately absent. Depreciation is straight-line
            // only, and `funded_from` picks the CREDIT side of an acquisition entry this importer
            // never posts — offering it would imply a choice that has no effect.
        ];
    }

    /**
     * Find the asset this row refers to, or start a new one.
     *
     * Identity is **(property, tag)** — the tag is the label physically stuck on the machine, and it
     * is unique within a mall rather than globally, because two malls each number their chillers
     * from 1. Keying on `tag` alone would merge two properties' assets; keying on `name` would fork
     * "Chiller 1" and "Chiller #1" into two, and a fixed asset that exists twice depreciates twice.
     *
     * A row whose property is out of scope resolves to null, which makes the row fail its own
     * validation rather than silently landing somewhere else.
     */
    public function resolveRecord(): ?FixedAsset
    {
        $asset = static::resolveVisibleAsset($this->data['asset_code'] ?? null);

        if (! $asset) {
            return null;
        }

        $existing = FixedAsset::query()
            ->where('asset_id', $asset->id)
            ->where('tag', $this->data['tag'] ?? '')
            ->first();

        return $existing ?? new FixedAsset([
            'asset_id' => $asset->id,
            // Set HERE rather than as a column default, so it is true of everything this importer
            // creates and of nothing else. An asset entered on the form is a real purchase and must
            // still post its acquisition.
            'is_opening_balance' => true,
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

    /** A guard rail against a mis-mapped file, not a capacity limit. */
    public function getMaxRows(): ?int
    {
        return (int) config('imports.max_rows', 5000);
    }
}
