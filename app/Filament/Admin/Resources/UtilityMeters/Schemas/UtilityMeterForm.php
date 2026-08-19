<?php

namespace App\Filament\Admin\Resources\UtilityMeters\Schemas;

use App\Models\Unit;
use App\Models\UtilityTariff;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use App\Support\TenantScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                    PropertyField::make()
                        ->label(__('admin.resources.asset.singular'))
                        ->searchable()
                        ->reactive(),
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
                    // The published price this meter follows. Narrowed to the meter's OWN utility —
                    // ->suggest() rather than a hard filter, because a hard filter refuses a
                    // legitimate value at validation and Filament rejects what the picker cannot
                    // label. A record picker, so EntitySelect: the operator searches it by code,
                    // by name in either language, and by provider.
                    EntitySelect::make('utility_tariff_id')
                        ->label(__('admin.fields.utility_tariff'))
                        ->entity(UtilityTariff::class)
                        ->suggest(fn ($query, Get $get) => $get('type')
                            ? $query->where('utility_type', $get('type'))->where('is_active', true)
                            : null)
                        ->preload()
                        ->placeholder(__('admin.fields.utility_tariff_placeholder'))
                        ->helperText(__('admin.helpers.utility_tariff'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.utility_tariff')),
                    // The per-meter OVERRIDE, and null is now the normal state. Set, it WINS over
                    // the tariff — for a rate negotiated with one tenant or a sub-meter billed at a
                    // blended figure. Blank means "follow the tariff"; blank with no tariff means
                    // monitored but never recharged (a landlord / common-area meter), which costs 0
                    // and which `BillMeterReadingService` refuses to bill.
                    TextInput::make('rate_per_unit')
                        ->label(__('admin.fields.rate_per_unit_override'))
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
