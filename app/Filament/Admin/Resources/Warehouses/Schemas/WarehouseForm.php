<?php

namespace App\Filament\Admin\Resources\Warehouses\Schemas;

use App\Models\Warehouse;
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
                // Clamped: `asset_id` is client-supplied, and a unique rule keyed on the
                // raw value leaks whether a code exists in a property the user cannot see
                // (TenantScope::clampAssetId).
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule, Get $get) => $rule->where('asset_id', TenantScope::clampAssetId($get('asset_id')))),
            // A real dropdown, not a datalist (a <datalist> is only a browser autocomplete
            // hint that won't reliably open on click). Category is free-form by design, so the
            // list merges the built-in suggestions with values already in use and keeps a
            // "create" affordance for a brand-new category — nullable, so not required.
            Select::make('category')
                ->label(__('admin.inventory.fields.category'))
                ->native(false)
                ->searchable()
                // Built-in suggestions + every value already in use, plus the field's own
                // current state so a stored-but-unlisted value (or one just added via "create")
                // stays a valid option — otherwise Filament's implicit in:options rule would
                // reject it on save.
                ->options(fn (Get $get): array => collect(['spare_parts', 'machines', 'consumables'])
                    ->merge(Warehouse::query()->pluck('category'))
                    ->push($get('category'))
                    ->filter()
                    ->unique()
                    ->mapWithKeys(fn (string $category): array => [$category => $category])
                    ->all())
                ->createOptionForm([
                    TextInput::make('value')
                        ->label(__('admin.inventory.fields.category'))
                        ->required()
                        ->maxLength(255),
                ])
                ->createOptionUsing(fn (array $data): string => $data['value']),
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
