<?php

namespace App\Filament\Admin\Resources\Holidays\Schemas;

use App\Models\Holiday;
use App\Support\Filament\PropertyField;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rules\Unique;

class HolidayForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('date')
                ->label(__('admin.fields.date'))
                ->required()
                ->native(false)
                // One row per property per date, refused as a field error rather than as the 500 the
                // unique index would otherwise raise. Scoped by `asset_id` so a national holiday and
                // a mall's own override of the same date can coexist — which is the whole mechanism.
                ->unique(
                    ignoreRecord: true,
                    // `clampAssetId`, never the raw `$get()`: a unique rule compiles to a query no
                    // tenancy scope touches, and field rules all run BEFORE any mutate hook — so the
                    // 403 below cannot protect it. Blank clamps to null, which is the national row
                    // and exactly what should be checked for a national holiday.
                    modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where(
                        'asset_id',
                        TenantScope::clampAssetId($get('asset_id')),
                    ),
                ),

            // FREE, not pinned. A national holiday is a null `asset_id` and is the ordinary case;
            // `PropertyField::make()` pins and requires the field whenever a mall is selected, which
            // would make the ordinary case unreachable through its own form. Registered with its
            // reason in `PropertyField::PORTFOLIO_LEVEL`.
            PropertyField::free()
                ->helperText(__('admin.facility.holiday.property_hint')),

            Select::make('kind')
                ->label(__('admin.fields.kind'))
                ->options(fn (): array => __('admin.facility.holiday.kinds'))
                ->default(Holiday::KIND_CLOSURE)
                ->required()
                ->live()
                ->native(false)
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.holiday_kind')),

            TimePicker::make('opens_at')
                ->label(__('admin.fields.opens_at'))
                ->seconds(false)
                ->visible(fn (Get $get): bool => $get('kind') === Holiday::KIND_SHORT_DAY)
                ->required(fn (Get $get): bool => $get('kind') === Holiday::KIND_SHORT_DAY),

            TimePicker::make('closes_at')
                ->label(__('admin.fields.closes_at'))
                ->seconds(false)
                ->visible(fn (Get $get): bool => $get('kind') === Holiday::KIND_SHORT_DAY)
                ->required(fn (Get $get): bool => $get('kind') === Holiday::KIND_SHORT_DAY)
                ->after('opens_at'),

            TextInput::make('name_en')
                ->label(__('admin.fields.name_en'))
                ->required()
                ->maxLength(120),

            TextInput::make('name_ar')
                ->label(__('admin.fields.name_ar'))
                ->required()
                ->maxLength(120),

            Toggle::make('is_active')
                ->label(__('admin.fields.is_active'))
                ->default(true)
                ->helperText(__('admin.facility.holiday.is_active_hint')),
        ]);
    }
}
