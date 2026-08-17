<?php

namespace App\Filament\Admin\Resources\UtilityTariffs\Schemas;

use App\Models\UtilityMeter;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class UtilityTariffForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.utility_tariffs.sections.identity'))
                ->description(__('admin.utility_tariffs.sections.identity_description'))
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->label(__('admin.fields.code'))
                        ->required()
                        ->maxLength(32)
                        ->unique(ignoreRecord: true)
                        ->placeholder('EGPC-COMM')
                        ->helperText(__('admin.helpers.utility_tariff_code')),

                    // A VALUE picker, not a record picker — so a plain Select is correct here and
                    // EntitySelect would be wrong. The set is `utility_meters.type`, registered once
                    // in ValueSets and shared so a tariff can only ever be offered for meters of its
                    // own utility.
                    Select::make('utility_type')
                        ->label(__('admin.fields.meter_type'))
                        ->options(fn () => collect(UtilityMeter::TYPES)
                            ->mapWithKeys(fn (string $t) => [$t => __("admin.enums.meter_type.{$t}")])
                            ->all())
                        ->required()
                        ->native(false)
                        ->helperText(__('admin.helpers.utility_tariff_type')),

                    TextInput::make('name_en')
                        ->label(__('admin.fields.name_en'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('name_ar')
                        ->label(__('admin.fields.name_ar'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('provider')
                        ->label(__('admin.fields.provider'))
                        ->maxLength(255)
                        ->placeholder('North Cairo Electricity Distribution')
                        ->helperText(__('admin.helpers.utility_tariff_provider')),

                    TextInput::make('unit_of_measurement')
                        ->label(__('admin.fields.unit_of_measurement'))
                        ->maxLength(16)
                        ->placeholder('kWh / m³')
                        ->helperText(__('admin.helpers.utility_tariff_uom')),

                    Toggle::make('is_active')
                        ->label(__('admin.fields.is_active'))
                        ->default(true)
                        ->helperText(__('admin.helpers.utility_tariff_active'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.utility_tariff_active')),
                ]),
        ]);
    }
}
