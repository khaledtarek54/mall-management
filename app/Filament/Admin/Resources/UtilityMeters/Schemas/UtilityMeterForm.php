<?php

namespace App\Filament\Admin\Resources\UtilityMeters\Schemas;

use App\Models\Asset;
use App\Models\Unit;
use App\Support\Filament\EntitySelect;
use App\Support\TenantScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

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
                        ->maxLength(50)
                        ->unique(ignoreRecord: true),
                    EntitySelect::make('asset_id')
                        ->label(__('admin.resources.asset.singular'))
                        ->entity(Asset::class)
                        ->required()
                        ->native(false)
                        ->searchable()
                        ->reactive()
                        ->default(fn () => TenantScope::currentAssetId())
                        ->disabled(fn () => TenantScope::currentAssetId() !== null)
                        ->dehydrated(),
                    EntitySelect::make('unit_id')
                        ->label(__('admin.fields.unit_label'))
                        ->entity(Unit::class)
                        ->modifyOptionsQuery(fn ($query, $get) => $query->when(
                            $get('asset_id') ?: TenantScope::currentAssetId(),
                            fn ($q, $assetId) => $q->where('asset_id', $assetId),
                        ))
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
                    // The tariff that turns consumption into money. Leave blank for a meter that is
                    // monitored but never recharged (e.g. a landlord/common-area meter).
                    TextInput::make('rate_per_unit')
                        ->label(__('admin.fields.rate_per_unit'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->step('0.0001')
                        ->helperText(__('admin.helpers.rate_per_unit'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.rate_per_unit')),
                ]),
        ]);
    }
}
