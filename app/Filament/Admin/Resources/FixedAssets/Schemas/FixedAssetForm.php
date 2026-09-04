<?php

namespace App\Filament\Admin\Resources\FixedAssets\Schemas;

use App\Models\FixedAsset;
use App\Services\DepreciationService;
use App\Support\CategorySuggestions;
use App\Support\Filament\PropertyField;
use App\Support\TaxDepreciation;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class FixedAssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            PropertyField::make()
                ->label(__('admin.fixed_assets.fields.property')),
            TextInput::make('name')
                ->label(__('admin.fixed_assets.fields.name'))
                ->required()
                ->maxLength(255),
            TextInput::make('tag')
                ->label(__('admin.fixed_assets.fields.tag'))
                ->required()
                ->maxLength(40)
                // Unique per property (matches the DB composite unique index).
                // Clamped: `asset_id` is client-supplied, and a unique rule keyed on the
                // raw value leaks whether a tag exists in a property the user cannot see
                // (TenantScope::clampAssetId).
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('asset_id', TenantScope::clampAssetId($get('asset_id')))),
            // A real dropdown, not a datalist (a <datalist> is only a browser autocomplete
            // hint that won't reliably open on click). Category is free-form by design, so the
            // list merges the built-in suggestions with values already in use and keeps a
            // "create" affordance for a brand-new category — nullable, so not required.
            // The Egyptian tax pool, stated rather than inferred: `category` is free text the
            // operator invents ("HVAC", "Fit-out"), so the same word means different things in two
            // malls and cannot be mapped to a statutory class. Separate from `method`, which is the
            // ACCOUNTING basis — an asset routinely has a different rate under each.
            Select::make('tax_pool')
                ->label(__('admin.fixed_assets.fields.tax_pool'))
                ->options(fn () => collect(TaxDepreciation::pools())
                    ->mapWithKeys(fn (string $p) => [$p => __("admin.tax_depreciation.pools.{$p}")])->all())
                ->default(TaxDepreciation::default())
                ->native(false)
                ->helperText(__('admin.fixed_assets.helpers.tax_pool')),
            Select::make('category')
                ->label(__('admin.fixed_assets.fields.category'))
                ->native(false)
                ->searchable()
                // Built-in suggestions + every value already in use, plus the field's own
                // current state so a stored-but-unlisted value (or one just added via "create")
                // stays a valid option — otherwise Filament's implicit in:options rule would
                // reject it on save.
                // Labels are translated, VALUES are the stored strings — see CategorySuggestions.
                ->options(fn (Get $get): array => CategorySuggestions::options(
                    'fixed_asset',
                    CategorySuggestions::FIXED_ASSET,
                    FixedAsset::query()->pluck('category'),
                    $get('category'),
                ))
                ->createOptionForm([
                    TextInput::make('value')
                        ->label(__('admin.fixed_assets.fields.category'))
                        ->required()
                        ->maxLength(255),
                ])
                ->createOptionUsing(fn (array $data): string => $data['value']),
            DatePicker::make('acquisition_date')
                ->label(__('admin.fixed_assets.fields.acquisition_date'))
                ->default(now())
                ->required()
                ->native(false)
                // This IS the acquisition entry's `entry_date`, so moving it moves which period
                // recognised the asset. `ChangeImpact` classifies it DERIVED — legitimate to change,
                // and the posted entry is voided and re-posted to match — but DERIVED's own
                // definition ends *"the operator must be told"*, and this was the one money form in
                // the panel that said nothing at all: `AnnouncesLedgerRestatement` reports the
                // restatement AFTER the save, which is the wrong end of the decision. Not disabled,
                // because the model deliberately permits it (a re-cost is a supported operation, see
                // `DepreciationService::assertRecostValid`) and a form stricter than its model is
                // the divergence `DepositTransactionForm` had in the other direction.
                ->helperText(__('admin.fixed_assets.posted_field_hint')),
            TextInput::make('acquisition_cost')
                ->label(__('admin.fixed_assets.fields.acquisition_cost'))
                ->numeric()
                ->minValue(0)
                ->required()
                ->prefix('EGP')
                // On EDIT, the base (cost − salvage) can't drop below what has already been
                // depreciated — else NBV goes negative and depreciation stops forever (F-86).
                // Inline so the operator sees it before submit; EditFixedAsset re-checks server
                // side. `$get` reads the sibling salvage field so the pair is judged together.
                ->rule(fn (Get $get, ?FixedAsset $record) => function (string $attr, $value, \Closure $fail) use ($get, $record) {
                    if ($record === null) {
                        return; // create: nothing depreciated yet
                    }

                    try {
                        app(DepreciationService::class)->assertRecostValid(
                            $record,
                            (float) $value,
                            (float) ($get('salvage_value') ?? 0),
                        );
                    } catch (\DomainException $e) {
                        $fail($e->getMessage());
                    }
                }),
            TextInput::make('salvage_value')
                ->label(__('admin.fixed_assets.fields.salvage_value'))
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->prefix('EGP')
                // Salvage can't exceed cost, else the depreciable base is negative.
                ->lte('acquisition_cost'),
            TextInput::make('useful_life_months')
                ->label(__('admin.fixed_assets.fields.useful_life'))
                ->numeric()
                ->minValue(1)
                ->required(),
            Select::make('funded_from')
                ->label(__('admin.fixed_assets.fields.funded_from'))
                // Was `['cash' => 'Cash', 'bank' => 'Bank']` — hardcoded English on both panels,
                // found while giving this column an audit vocabulary. The trail and the form now
                // read the same words, in the reader's language.
                ->options(fn (): array => __('admin.enums.cash_or_bank'))
                ->default('cash')
                ->required()
                ->native(false)
                // Decides which account the acquisition's CREDIT leg hits, so switching it on a
                // posted asset moves real money between cash and bank in the books. Same reasoning
                // as the acquisition date above.
                ->helperText(__('admin.fixed_assets.posted_field_hint')),
            Textarea::make('notes')
                ->label(__('admin.fixed_assets.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }
}
