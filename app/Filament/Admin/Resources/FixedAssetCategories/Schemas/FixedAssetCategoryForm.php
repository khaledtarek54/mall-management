<?php

namespace App\Filament\Admin\Resources\FixedAssetCategories\Schemas;

use App\Support\Modules;
use App\Support\TaxDepreciation;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class FixedAssetCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')
                ->label(__('admin.fields.code'))
                ->required()
                ->maxLength(64)
                // Immutable: every asset row stores the code itself.
                ->disabledOn('edit')
                ->helperText(__('admin.fixed_asset_categories_screen.help.code'))
                ->rules([
                    'regex:/^[A-Za-z][A-Za-z0-9_-]*$/',
                    fn ($record) => Rule::unique('fixed_asset_categories', 'code')->ignore($record?->id),
                ]),

            // The number series. Left blank, the model derives one from the code (`GEN` for
            // "generator") — stated here so the operator sees what their assets will be called.
            TextInput::make('tag_prefix')
                ->label(__('admin.fields.tag_prefix'))
                ->maxLength(12)
                ->rules([
                    'nullable',
                    'regex:/^[A-Za-z0-9]+$/',
                    fn ($record) => Rule::unique('fixed_asset_categories', 'tag_prefix')->ignore($record?->id),
                ])
                ->helperText(__('admin.fixed_asset_categories_screen.help.tag_prefix')),

            TextInput::make('name_en')->label(__('admin.fields.name_en'))->required()->maxLength(96),
            TextInput::make('name_ar')->label(__('admin.fields.name_ar'))->required()->maxLength(96),

            TextInput::make('default_useful_life_months')
                ->label(__('admin.fields.default_useful_life_months'))
                ->numeric()
                ->minValue(1)
                ->maxValue(1200)
                ->suffix(__('admin.fields.months'))
                ->helperText(__('admin.fixed_asset_categories_screen.help.default_useful_life')),

            // The MEMO value — SAP's rule, the accountant's "salvage default 1". Nullable: a class
            // with none proposes nothing and the asset's own field decides.
            TextInput::make('default_salvage_value')
                ->label(__('admin.fields.default_salvage_value'))
                ->numeric()
                ->minValue(0)
                ->prefix(config('app.currency', 'EGP'))
                ->helperText(__('admin.fixed_asset_categories_screen.help.default_salvage')),

            // The class's proposal for a field the asset form only offers while the tax schedule
            // is switched on (`tax_depreciation`) — hidden with it. A class registered while the
            // switch is off proposes NO pool, and its assets stay unstated until somebody looks.
            Select::make('default_tax_pool')
                ->label(__('admin.fields.default_tax_pool'))
                ->options(fn (): array => collect(TaxDepreciation::pools())
                    ->mapWithKeys(fn (string $p): array => [$p => __("admin.tax_depreciation.pools.{$p}")])->all())
                ->native(false)
                ->visible(fn (): bool => Modules::enabled('tax_depreciation'))
                ->helperText(__('admin.fixed_asset_categories_screen.help.default_tax_pool')),

            TextInput::make('sort_order')
                ->label(__('admin.fields.sort_order'))
                ->numeric()->minValue(0)->default(0)
                ->helperText(__('admin.fixed_asset_categories_screen.help.sort_order')),

            Toggle::make('is_active')
                ->label(__('admin.fields.is_active'))
                ->default(true)
                ->helperText(__('admin.fixed_asset_categories_screen.help.is_active')),
        ]);
    }
}
