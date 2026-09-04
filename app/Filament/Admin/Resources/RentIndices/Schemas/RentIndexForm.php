<?php

namespace App\Filament\Admin\Resources\RentIndices\Schemas;

use App\Models\RentIndex;
use App\Support\Filament\MonthPicker;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
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
                ->dehydrateStateUsing(fn (?string $state) => RentIndex::normaliseCode($state))
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
                // ── ONE READING PER INDEX PER MONTH, REFUSED IN WORDS (SW-043, 2026-09-04) ─────
                //
                // `rent_indices` has carried `unique(code, period)` since it shipped and this form
                // asked nothing about it, so re-entering a month that already has a reading came
                // back as a raw 500. That is not an exotic mistake: it is what an operator does
                // when the agency REVISES a figure, and the migration's own docblock already says
                // what should happen instead — "a revision is an EDIT to that row, not a second
                // row, because a lease that escalated on the old figure must be able to show which
                // figure it used and when it changed". So the refusal names that escape.
                //
                // A closure rather than `->unique()`, because BOTH sides have to be normalised the
                // way storage normalises them and a unique rule can only normalise the sibling it
                // is keyed on. The code is stored upper-cased by the dehydrator ABOVE, which has
                // not run at validation time, so `egy_cpi` typed against a stored `EGY_CPI` matches
                // nothing under SQLite's case-sensitive `=` — green in the suite, different on
                // MySQL. And the period state is not always the 1st: `RentIndexScreenIsReachableTest`
                // fills `2026-10-17` and asserts it lands as `2026-10-01`, so the month is snapped
                // here exactly as `RentIndex::valueFor()` snaps it when it reads one back.
                //
                // The form is the only door — `DemoSeeder` is the one other writer and it uses
                // `updateOrCreate` — so there is no model guard beside this. The database index is
                // the backstop it always was.
                ->rule(static fn (Get $get, ?RentIndex $record): Closure => static function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                    if (blank($value)) {
                        return;
                    }

                    $taken = RentIndex::query()
                        ->where('code', RentIndex::normaliseCode($get('code')))
                        ->whereDate('period', CarbonImmutable::parse($value)->startOfMonth()->toDateString())
                        // Editing a reading must not be refused by the reading itself.
                        ->when($record?->exists, fn ($query) => $query->whereKeyNot($record->getKey()))
                        ->exists();

                    if ($taken) {
                        $fail(__('admin.validation.rent_index_period_taken'));
                    }
                })
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
