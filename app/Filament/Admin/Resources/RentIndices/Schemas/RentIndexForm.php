<?php

namespace App\Filament\Admin\Resources\RentIndices\Schemas;

use App\Models\RentIndex;
use App\Support\Filament\MonthPicker;
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
                // ── THE CODES ALREADY IN USE, OFFERED (2026-08-28) ──────────────────────────
                //
                // An index is a SERIES: every reading of one index carries the same code, and the
                // escalation looks the base month and the review month up under it. Type the code
                // differently the second time and you have created a second index of one reading
                // each — and the rent then never escalates, silently, because the review month's
                // reading is filed under a name the lease does not know.
                //
                // Reported from the panel by exactly that route: CPI-EG and EGY_CPI, one reading
                // each. Free text is still right — the codes belong to whoever publishes them, and
                // a closed list could not accept a new series — so the existing ones are SUGGESTED
                // rather than enforced.
                ->datalist(fn (): array => RentIndex::query()
                    ->distinct()->orderBy('code')->pluck('code')->all())
                ->helperText(__('admin.fields.index_code_helper')),

            // A MONTH, so a month is what it asks for. A `DatePicker` here opened a calendar of
            // days and made the operator click one, on a field where the day has never meant
            // anything — and the value was then normalised behind their back.
            MonthPicker::make('period')
                ->label(__('admin.fields.index_period'))
                ->required()
                // An index series runs years back; the reading being entered is almost always a
                // recent one, so the window is wide but opens on the near months.
                ->monthsBack(120)
                ->monthsAhead(3)
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
