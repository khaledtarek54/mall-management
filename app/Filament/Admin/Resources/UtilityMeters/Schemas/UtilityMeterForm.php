<?php

namespace App\Filament\Admin\Resources\UtilityMeters\Schemas;

use App\Models\Unit;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UtilityMeterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.utility_meter'))
                ->columns(3)
                ->components([
                    TextInput::make('meter_number')
                        ->label(__('admin.fields.meter_number'))
                        ->required()
                        ->maxLength(50),
                    Select::make('asset_id')
                        ->label(__('admin.resources.asset.singular'))
                        ->options(fn () => \App\Support\TenantScope::selectableAssetOptions())
                        ->required()
                        ->native(false)
                        ->searchable()
                        ->reactive()
                        ->default(fn () => \App\Support\TenantScope::currentAssetId())
                        ->disabled(fn () => \App\Support\TenantScope::currentAssetId() !== null)
                        ->dehydrated(),
                    Select::make('unit_id')
                        ->label(__('admin.fields.unit_label'))
                        ->options(function ($get) {
                            $assetId = $get('asset_id') ?: \App\Support\TenantScope::currentAssetId();
                            return Unit::query()
                                ->when($assetId, fn ($q, $aid) => $q->where('asset_id', $aid))
                                ->orderBy('code')
                                ->pluck('code', 'id');
                        })
                        ->native(false)
                        ->searchable()
                        ->placeholder(__('admin.fields.common_area_placeholder')),
                    Select::make('type')
                        ->label(__('admin.fields.meter_type'))
                        ->options(fn () => __('admin.enums.meter_type'))
                        ->required()
                        ->native(false),
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn () => __('admin.statuses.meter'))
                        ->default('active')
                        ->required()
                        ->native(false),
                    TextInput::make('provider')
                        ->label(__('admin.fields.meter_provider'))
                        ->maxLength(100),
                    TextInput::make('unit_of_measurement')
                        ->label(__('admin.fields.unit_of_measurement'))
                        ->maxLength(16)
                        ->placeholder('kWh / m³'),
                ]),
        ]);
    }
}
