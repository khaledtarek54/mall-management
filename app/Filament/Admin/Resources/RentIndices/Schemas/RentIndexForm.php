<?php

namespace App\Filament\Admin\Resources\RentIndices\Schemas;

use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RentIndexForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label(__('admin.fields.index_code'))
                ->required()
                ->maxLength(32)
                // Upper-cased on the way in so `egy_cpi` and `EGY_CPI` cannot become two indices
                // that look identical in a dropdown and never match each other.
                ->dehydrateStateUsing(fn (?string $state) => strtoupper(trim((string) $state)))
                ->helperText(__('admin.fields.index_code_helper')),

            DatePicker::make('period')
                ->label(__('admin.fields.index_period'))
                ->required()
                ->native(false)
                ->displayFormat('M Y')
                // Normalised to the first of the month: the figure describes a MONTH, and two rows
                // for the same month dated the 1st and the 15th would defeat the unique key that
                // keeps one value per period.
                ->dehydrateStateUsing(fn ($state) => $state ? CarbonImmutable::parse($state)->startOfMonth()->toDateString() : null)
                ->helperText(__('admin.fields.index_period_helper')),

            TextInput::make('value')
                ->label(__('admin.fields.index_value'))
                ->required()
                ->numeric()
                ->minValue(0.0001)
                ->step('0.0001')
                ->helperText(__('admin.fields.index_value_helper')),

            DatePicker::make('published_on')
                ->label(__('admin.fields.index_published_on'))
                ->native(false)
                ->displayFormat('d/m/Y')
                ->helperText(__('admin.fields.index_published_on_helper')),

            TextInput::make('notes')
                ->label(__('admin.fields.notes'))
                ->maxLength(255)
                ->columnSpanFull(),
        ]);
    }
}
