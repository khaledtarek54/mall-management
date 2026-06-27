<?php

namespace App\Filament\Admin\Resources\CamExpensePools\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CamExpensePoolForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.cam_pool'))
                ->description(__('admin.sections.cam_pool_description'))
                ->columns(3)
                ->components([
                    Select::make('asset_id')
                        ->label(__('admin.resources.asset.singular'))
                        ->options(fn () => \App\Support\TenantScope::selectableAssetOptions())
                        ->required()
                        ->native(false)
                        ->searchable()
                        ->default(fn () => \App\Support\TenantScope::currentAssetId())
                        ->disabled(fn () => \App\Support\TenantScope::currentAssetId() !== null)
                        ->dehydrated(),
                    TextInput::make('period_year')
                        ->label(__('admin.fields.period_year'))
                        ->required()
                        ->numeric()
                        ->minValue(2020)
                        ->maxValue(2099)
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule, \Filament\Schemas\Components\Utilities\Get $get) => $rule->where('asset_id', $get('asset_id')))
                        ->default(fn () => now()->year),
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn () => __('admin.statuses.cam_pool'))
                        ->default('draft')
                        ->required()
                        ->native(false),
                    TextInput::make('total_actual_expense')
                        ->label(__('admin.fields.total_actual_expense'))
                        ->prefix('EGP')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->step('0.01')
                        ->helperText(__('admin.helpers.cam_actual_expense')),
                    TextInput::make('total_estimated_collected')
                        ->label(__('admin.fields.total_estimated_collected'))
                        ->prefix('EGP')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->step('0.01')
                        ->helperText(__('admin.helpers.cam_estimated_collected')),
                ]),
            Section::make(__('admin.sections.cam_notes'))
                ->components([
                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(3)
                        ->maxLength(5000)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
