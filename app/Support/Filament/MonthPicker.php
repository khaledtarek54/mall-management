<?php

namespace App\Support\Filament;

use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;

/**
 * A date picker that picks a MONTH — the same calendar, with the days taken out.
 *
 * **Why not a `DatePicker` with a month display format.** It still opens a grid of DAYS: the
 * operator is asked to click the 14th of a field that means "August 2026", and the value is then
 * normalised to the 1st behind their back — so what they picked and what was stored differ, on a
 * field where the day was never part of the answer. Reported from the panel while entering a rent
 * index (2026-08-28).
 *
 * **And why not a `Select` of months**, which was the first attempt: a dropdown is a worse control
 * for a date than a calendar is. Filament's own picker already carries a month select and a year
 * input above its grid, so the fix is to keep that header and drop the days — the reader still gets
 * a picker, and cannot express a day.
 *
 * Everything else is upstream's: the panel, the Alpine component, the keyboard handling, the
 * min/max bounds. Only the view differs, and the state is clamped to the first of the month on the
 * way in and out.
 *
 *   MonthPicker::make('period')->label(…)
 */
class MonthPicker extends DatePicker
{
    protected string $view = 'forms.components.month-picker';

    protected function setUp(): void
    {
        parent::setUp();

        $this->native(false)
            ->displayFormat('F Y')
            // A period IS a month: two rows for one month dated the 1st and the 15th would defeat
            // every unique key over it, and a stored mid-month value would render as a day nobody
            // chose.
            ->dehydrateStateUsing(fn ($state): ?string => $state
                ? CarbonImmutable::parse($state)->startOfMonth()->toDateString()
                : null)
            ->formatStateUsing(fn ($state): ?string => $state
                ? CarbonImmutable::parse($state)->startOfMonth()->toDateString()
                : null);
    }
}
