<?php

namespace App\Filament\Admin\Resources\Units\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.unit_details'))
                ->columns(3)
                ->components([
                    Select::make('asset_id')
                        ->label(__('admin.tables.unit.asset'))
                        // Scope to the user's visible properties so an "All Properties"
                        // user cannot create a unit under a property outside their set
                        // (null = unrestricted: super_admin / portfolio roles).
                        ->relationship('asset', 'name', modifyQueryUsing: function ($query) {
                            $visibleAssetIds = \App\Support\TenantScope::visibleAssetIds();

                            return $visibleAssetIds !== null
                                ? $query->whereIn('id', $visibleAssetIds)
                                : $query;
                        })
                        ->required()
                        ->native(false)
                        ->default(fn () => \App\Support\TenantScope::currentAssetId())
                        ->disabled(fn () => \App\Support\TenantScope::currentAssetId() !== null)
                        ->dehydrated(),
                    TextInput::make('code')
                        ->label(__('admin.tables.unit.code'))
                        ->required()
                        ->maxLength(20)
                        // Clamped: `asset_id` is client-supplied, and a unique rule keyed on
                        // the raw value leaks whether a unit code exists in a property the
                        // user cannot see (TenantScope::clampAssetId).
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule, \Filament\Schemas\Components\Utilities\Get $get) => $rule->where('asset_id', \App\Support\TenantScope::clampAssetId($get('asset_id'))))
                        ->placeholder('A-01'),
                    TextInput::make('floor')
                        ->label(__('admin.pdf.floor'))
                        ->maxLength(20),
                    Select::make('category')
                        ->label(__('admin.tables.unit.category'))
                        ->options(fn () => __('admin.enums.category'))
                        ->required()
                        ->native(false),
                    TextInput::make('area_sqm')
                        ->label(__('admin.tables.unit.area'))
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->suffix('m²'),
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn () => __('admin.statuses.unit'))
                        ->required()
                        ->default('vacant')
                        ->native(false),
                    Textarea::make('description')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
