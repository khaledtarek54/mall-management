<?php

namespace App\Filament\Admin\Resources\MarketingBudgets\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MarketingBudgetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.resources.marketing_budget.singular'))
                ->columns(2)
                ->components([
                    Select::make('asset_id')
                        ->label(__('admin.tables.marketing_budget.property'))
                        ->options(fn () => \App\Support\TenantScope::selectableAssetOptions())
                        ->searchable()
                        ->required()
                        ->default(fn () => \App\Support\TenantScope::currentAssetId())
                        ->disabled(fn () => \App\Support\TenantScope::currentAssetId() !== null)
                        ->dehydrated(),
                    TextInput::make('period_year')
                        ->label(__('admin.tables.marketing_budget.year'))
                        ->numeric()
                        ->required()
                        ->default((int) date('Y'))
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule, \Filament\Schemas\Components\Utilities\Get $get) => $rule->where('asset_id', $get('asset_id'))),
                    Select::make('status')
                        ->label(__('admin.tables.marketing_budget.status'))
                        ->options(['open' => 'Open', 'closed' => 'Closed'])
                        ->default('open')
                        ->required()
                        ->native(false),
                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
