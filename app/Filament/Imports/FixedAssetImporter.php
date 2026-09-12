<?php

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\ResolvesVisibleAssetByCode;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\Vendor;
use App\Support\DataTransferNotice;
use App\Support\ValueSets;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\Rule;

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

            // A migrating register's own numbers are KEPT (the counterparty-code rule — its
            // accountant's paperwork already carries them); a blank cell is allocated the next
            // number in the class's series for the property, by the model, as the form does — so
            // a row naming NO class must carry its own tag, or nothing can number it and the
            // insert would fail on the column's NOT NULL with no sentence for the operator.
            ImportColumn::make('tag')
                ->label(__('admin.fixed_assets.fields.tag'))
                ->requiredMapping()
                ->rules(['nullable', 'required_without:category', 'max:40']),

            ImportColumn::make('name')
                ->label(__('admin.fields.name'))
                ->requiredMapping()
                ->rules(['required', 'max:255']),

            // The asset CLASS — a catalogue code since 2026-09-12 (`FixedAssetCategory`): what
            // numbers an untagged row and proposes a blank salvage, life or tax pool.
            // `ValueSets::allowed()` — what the column ACCEPTS, which is every catalogue row
            // including a retired one (a migrating file may legitimately carry a class the
            // operator has since stopped offering on the form; refusing the row would lose it).
            // Nullable, deliberately: a migrating register carries its own numbers and lives, and
            // may carry no classes at all — the form requires one for a NEW asset because that is
            // where the number comes from.
            ImportColumn::make('category')
                ->label(__('admin.fields.category'))
                ->rules(['nullable', Rule::in(ValueSets::allowed('fixed_assets', 'category') ?? [])]),

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

            // Blank takes the class's proposed life (the model fills it on create); a figure
            // stated in the file wins, exactly as salvage does above. Required where no class is
            // named — nothing else can propose one — and `beforeCreate()` below refuses in words
            // where the class named proposes none either. `ignoreBlankState()`, because on a
            // RE-IMPORT (the tag matched an existing asset) a blank cell must leave the life the
            // row already has: Filament fills a blank as null otherwise, and the column is NOT NULL.
            ImportColumn::make('useful_life_months')
                ->label(__('admin.fixed_assets.fields.useful_life'))
                ->requiredMapping()
                ->integer()
                ->ignoreBlankState()
                ->rules(['nullable', 'required_without:category', 'integer', 'min:1']),

            ImportColumn::make('notes')
                ->label(__('admin.fields.notes'))
                ->rules(['nullable', 'max:2000']),

            // The supplier, by its CODE (point 15) — the identity the vendor register keys on and
            // exports. Resolved to an EXISTING row and refused in words otherwise: an importer that
            // minted a vendor from a spreadsheet cell would be the free-text door the form
            // deliberately does not offer, on a counterparty the next slice's supplier bill needs
            // to be real. `fillRecordUsing` so the cell never reaches `data_set($record,
            // 'vendor_code', …)` on a column the table does not have. A BLANK cell clears — the
            // `UnitImporter::floor` rule: what a door set, the same door must be able to unset.
            ImportColumn::make('vendor_code')
                ->label(__('admin.fields.vendor_code'))
                ->rules(['nullable', 'max:40'])
                ->fillRecordUsing(function (FixedAsset $record, ?string $state): void {
                    $code = trim((string) $state);

                    if ($code === '') {
                        $record->vendor_id = null;

                        return;
                    }

                    $vendor = Vendor::query()->where('code', $code)->first(['id']);

                    if ($vendor === null) {
                        throw new RowImportFailedException(__('admin.fixed_assets.errors.unknown_vendor_code', ['code' => $code]));
                    }

                    $record->vendor_id = $vendor->id;
                }),

            // `method`, `funded_from` and `bank_account_id` are deliberately absent. Depreciation is
            // straight-line only, and the rail and the bank pick the CREDIT side of an acquisition
            // entry this importer never posts (every row is an opening balance) — offering them
            // would imply a choice that has no effect.
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

        // A row with no tag has no identity to match on: it is a NEW asset, numbered by the class
        // on create. Matching a blank against `''` would never find anything either, but saying so
        // is what stops a second import of the same untagged file reading as an update.
        $tag = trim((string) ($this->data['tag'] ?? ''));

        $existing = $tag === '' ? null : FixedAsset::query()
            ->where('asset_id', $asset->id)
            ->where('tag', $tag)
            ->first();

        return $existing ?? new FixedAsset([
            'asset_id' => $asset->id,
            // Set HERE rather than as a column default, so it is true of everything this importer
            // creates and of nothing else. An asset entered on the form is a real purchase and must
            // still post its acquisition.
            'is_opening_balance' => true,
        ]);
    }

    /**
     * A NEW asset with no life of its own and a class proposing none is refused HERE, in words:
     * the model throws the same sentence as a `DomainException`, and Filament's `ImportCsv`
     * writes only a `RowImportFailedException`'s message into the failed-rows file — every other
     * throwable becomes a failed row with no sentence (the `LeaseImporter` idiom).
     */
    protected function beforeCreate(): void
    {
        /** @var FixedAsset $asset */
        $asset = $this->record;

        if (filled($asset->getAttributes()['useful_life_months'] ?? null)) {
            return;
        }

        if ((FixedAssetCategory::defaultsFor($asset->category)['useful_life_months'] ?? null) !== null) {
            return;
        }

        throw new RowImportFailedException(__('admin.fixed_assets.errors.useful_life_required'));
    }

    /**
     * A re-import that restates a TRANSFERRED asset's cost, date or opening figures is refused
     * here in words (point 18) — the model refuses it as a `DomainException`, which the importer
     * would file as a failed row with no sentence.
     */
    protected function beforeSave(): void
    {
        /** @var FixedAsset $asset */
        $asset = $this->record;

        if ($asset->exists && $asset->isDirty(FixedAsset::TRANSFER_FROZEN) && $asset->historyLockedByTransfer()) {
            throw new RowImportFailedException(__('admin.fixed_assets.errors.transferred_history_locked'));
        }
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
