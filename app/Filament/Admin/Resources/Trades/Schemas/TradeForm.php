<?php

namespace App\Filament\Admin\Resources\Trades\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class TradeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label(__('admin.fields.code'))
                ->required()
                ->maxLength(32)
                ->unique(ignoreRecord: true)
                ->helperText(__('admin.facility.help.trade_code')),

            // Both languages on the row, because a trade the operator adds tomorrow has to be
            // named correctly in both without a deploy — which was the whole reason this stopped
            // being a translation key.
            TextInput::make('name_en')
                ->label(__('admin.fields.name_en'))
                ->required()
                ->maxLength(80),

            TextInput::make('name_ar')
                ->label(__('admin.fields.name_ar'))
                ->required()
                ->maxLength(80),

            TextInput::make('standard_hourly_rate')
                ->label(__('admin.facility.fields.standard_hourly_rate'))
                ->numeric()
                ->minValue(0)
                ->prefix('EGP')
                ->helperText(__('admin.facility.help.standard_hourly_rate'))
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.standard_hourly_rate')),

            TextInput::make('sort_order')
                ->label(__('admin.fields.sort_order'))
                ->numeric()
                ->default(0),

            Toggle::make('is_active')
                ->label(__('admin.fields.active'))
                ->default(true)
                ->helperText(__('admin.facility.help.trade_active')),

            Textarea::make('notes')
                ->label(__('admin.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ])->columns(2);
    }
}
