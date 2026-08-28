<?php

namespace App\Support\Filament;

use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;

/**
 * A field that picks a MONTH, and only a month.
 *
 * **Why not a `DatePicker` with a month display format.** Because it still opens a calendar of
 * DAYS: the operator is asked to click the 14th of a field that means "August 2026", and the value
 * then has to be normalised behind their back — so what they picked and what was stored differ,
 * silently, on a field where the day was never meaningful. Reported from the panel while entering
 * a rent index (2026-08-28).
 *
 * Filament's `DatePicker` has no month-only mode, and the panel already answers this the right way
 * elsewhere: `BillingRunPreview` picks its period from a `Select` of months. This is that idiom,
 * once, so a month field looks and behaves the same everywhere.
 *
 * The label is written in the READER's language — `format()` emits English month names whatever the
 * locale, which is the trap `BillingForecastRelationManager::periodLabel()` already records.
 *
 *   MonthPicker::make('period')->label(…)->monthsBack(60)->monthsAhead(12)
 */
class MonthPicker extends Select
{
    protected int $monthsBack = 24;

    protected int $monthsAhead = 12;

    protected ?CarbonImmutable $anchor = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->native(false)
            ->searchable()
            ->options(fn (): array => $this->monthOptions())
            // The stored value is always the FIRST of the month: a period is a month, and two rows
            // for one month dated the 1st and the 15th would defeat every unique key over it.
            ->dehydrateStateUsing(fn ($state): ?string => $state
                ? CarbonImmutable::parse($state)->startOfMonth()->toDateString()
                : null)
            // An existing record holds a date; the option keys are month starts, so a stored
            // mid-month value would select nothing and the field would open blank on a row that
            // plainly has a period.
            ->formatStateUsing(fn ($state): ?string => $state
                ? CarbonImmutable::parse($state)->startOfMonth()->toDateString()
                : null);
    }

    /** How far back the list runs. */
    public function monthsBack(int $months): static
    {
        $this->monthsBack = $months;

        return $this;
    }

    /** How far forward the list runs. */
    public function monthsAhead(int $months): static
    {
        $this->monthsAhead = $months;

        return $this;
    }

    /** Centre the window on something other than today. */
    public function anchoredOn(CarbonImmutable $month): static
    {
        $this->anchor = $month->startOfMonth();

        return $this;
    }

    /**
     * Newest first — a period field is filled far more often for a recent month than an old one,
     * and a list that opens on 2024 makes the common case the longest scroll.
     *
     * @return array<string, string>
     */
    protected function monthOptions(): array
    {
        $anchor = $this->anchor ?? CarbonImmutable::now()->startOfMonth();
        $locale = app()->getLocale();
        $options = [];

        for ($i = $this->monthsAhead; $i >= -$this->monthsBack; $i--) {
            $month = $anchor->addMonths($i);
            $options[$month->toDateString()] = $month->locale($locale)->isoFormat('MMMM YYYY');
        }

        // A value stored outside the window — an old index reading, a back-dated period — must still
        // resolve, or editing that row silently clears its month.
        $state = $this->getState();

        if ($state) {
            $stored = CarbonImmutable::parse($state)->startOfMonth();

            if (! array_key_exists($stored->toDateString(), $options)) {
                $options = [$stored->toDateString() => $stored->locale($locale)->isoFormat('MMMM YYYY')] + $options;
            }
        }

        return $options;
    }
}
