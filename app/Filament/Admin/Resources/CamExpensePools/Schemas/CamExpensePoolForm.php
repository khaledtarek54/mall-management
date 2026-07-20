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
                        // Clamped: `asset_id` is client-supplied, and a unique rule keyed on
                        // the raw value leaks whether a pool exists for a year in a property
                        // the user cannot see (TenantScope::clampAssetId).
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule, \Filament\Schemas\Components\Utilities\Get $get) => $rule->where('asset_id', \App\Support\TenantScope::clampAssetId($get('asset_id'))))
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
                    // Admin fee % is stored as a FRACTION (0.10) but operators think in percent (10).
                    // formatStateUsing (×100) runs on hydrate — INCLUDING on the default — so the
                    // default is expressed in the field's raw (pre-format) space, the fraction 0.10,
                    // which formats to "10" for display and dehydrates back to 0.10 on save. (A
                    // default of 10 would format to 1000 and blow maxValue(100).) Blank ⇒ null ⇒ no fee.
                    TextInput::make('admin_fee_pct')
                        ->label(__('admin.fields.cam_admin_fee_pct'))
                        ->suffix('%')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step('0.01')
                        ->default(0.10)
                        ->helperText(__('admin.helpers.cam_admin_fee_pct'))
                        ->formatStateUsing(fn ($state) => $state === null ? null : round((float) $state * 100, 4))
                        ->dehydrateStateUsing(fn ($state) => ($state === null || $state === '') ? null : round((float) $state / 100, 6)),

                    // VAT on the cost recovery (true-up + over-collection credit). Plain percentage
                    // (14, not the fee's 0.10 fraction). Default 14% to match the monthly CAM estimate;
                    // 0% for a genuinely non-taxable pass-through. Frozen once an allocation is billed.
                    TextInput::make('recovery_vat_rate')
                        ->label(__('admin.fields.cam_recovery_vat_rate'))
                        ->suffix('%')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step('0.01')
                        ->default(14)
                        ->required()
                        ->helperText(__('admin.helpers.cam_recovery_vat_rate')),
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
