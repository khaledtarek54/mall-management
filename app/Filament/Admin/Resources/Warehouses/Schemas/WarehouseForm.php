<?php

namespace App\Filament\Admin\Resources\Warehouses\Schemas;

use App\Support\TenantScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class WarehouseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('asset_id')
                ->label(__('admin.inventory.fields.property'))
                // Scoped to the user's visible properties (never leaks another mall).
                ->options(fn () => TenantScope::selectableAssetOptions())
                ->default(fn () => TenantScope::currentAssetId())
                ->disabled(fn () => TenantScope::currentAssetId() !== null)
                ->dehydrated()
                ->required()
                ->native(false),
            TextInput::make('name')
                ->label(__('admin.inventory.fields.name'))
                ->required()
                ->maxLength(255),
            TextInput::make('code')
                ->label(__('admin.inventory.fields.code'))
                ->required()
                ->maxLength(30)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule, Get $get) => $rule->where('asset_id', $get('asset_id'))),
            TextInput::make('category')
                ->label(__('admin.inventory.fields.category'))
                ->maxLength(255)
                ->datalist(['spare_parts', 'machines', 'consumables']),
            Toggle::make('is_active')
                ->label(__('admin.inventory.fields.active'))
                ->default(true),
            Textarea::make('notes')
                ->label(__('admin.inventory.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }
}
