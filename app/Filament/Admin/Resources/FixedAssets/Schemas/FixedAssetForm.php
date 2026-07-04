<?php

namespace App\Filament\Admin\Resources\FixedAssets\Schemas;

use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class FixedAssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('asset_id')
                ->label(__('admin.fixed_assets.fields.property'))
                // Scoped to the user's visible properties (never leaks another mall).
                ->options(fn () => TenantScope::selectableAssetOptions())
                ->default(fn () => TenantScope::currentAssetId())
                ->disabled(fn () => TenantScope::currentAssetId() !== null)
                ->dehydrated()
                ->required()
                ->native(false),
            TextInput::make('name')
                ->label(__('admin.fixed_assets.fields.name'))
                ->required()
                ->maxLength(255),
            TextInput::make('tag')
                ->label(__('admin.fixed_assets.fields.tag'))
                ->required()
                ->maxLength(40)
                // Unique per property (matches the DB composite unique index).
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule, Get $get) => $rule->where('asset_id', $get('asset_id'))),
            TextInput::make('category')
                ->label(__('admin.fixed_assets.fields.category'))
                ->maxLength(255)
                ->datalist(['furniture', 'equipment', 'HVAC', 'IT', 'vehicles', 'fit-out']),
            DatePicker::make('acquisition_date')
                ->label(__('admin.fixed_assets.fields.acquisition_date'))
                ->default(now())
                ->required()
                ->native(false),
            TextInput::make('acquisition_cost')
                ->label(__('admin.fixed_assets.fields.acquisition_cost'))
                ->numeric()
                ->minValue(0)
                ->required()
                ->prefix('EGP'),
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
                ->options(['cash' => 'Cash', 'bank' => 'Bank'])
                ->default('cash')
                ->required()
                ->native(false),
            Textarea::make('notes')
                ->label(__('admin.fixed_assets.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }
}
