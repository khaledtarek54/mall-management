<?php

namespace App\Support\Filament;

use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/**
 * *From* and *until* over one date column — the one definition of a date-range filter (SW-083).
 *
 * It was written out by hand on five registers, nine identical lines each, and the two money lists
 * that most need it had none at all: the AP register and the expense register, while every AR list
 * offers one. "Which bills did we take in March" is the question a payables clerk asks first, and
 * the only way to answer it was to sort by date and scroll.
 *
 * Extracted rather than copied a seventh and eighth time. The copies had not drifted yet, which is
 * the only moment extracting one is cheap — and then the five older copies were never converted
 * and sixteen more were written beside the seam. Measured 2026-09-12: twenty-one inline copies
 * against three uses, nine of them with NO chip (an applied range with nothing in the bar to say
 * so or to clear it — SW-025's own defect), under four spellings of the picker keys. All read
 * this now; `ADateRangeFilterIsOneDefinitionConformanceTest` keeps it so.
 *
 * **`whereDate`, deliberately.** These columns are `date` on some tables and `datetime` on others
 * (`announcements.sent_at`), and a plain `>=` against a `datetime` silently excludes everything
 * recorded later than midnight on the closing day — the operator picks a range that includes today
 * and today's rows are missing.
 */
class DateRangeFilter
{
    /**
     * @param  string  $column  the date column the range narrows
     * @param  string|null  $label  the filter's label; the field's own label by default
     * @param  string|null  $name  the filter's NAME — the key saved views and the remembered
     *                             filter state are stored under. Defaults to the column. Given
     *                             where a hand-written copy this replaces already had a name
     *                             (`payment_date_range`), so what an operator saved keeps working.
     */
    public static function make(string $column, ?string $label = null, ?string $name = null): Filter
    {
        return Filter::make($name ?? $column)
            ->label($label ?? __('admin.fields.'.$column))
            ->schema([
                DatePicker::make('from')->label(__('admin.filters.date_from'))->native(false),
                DatePicker::make('until')->label(__('admin.filters.date_until'))->native(false),
            ])
            ->query(fn (Builder $query, array $data) => $query
                ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate($column, '>=', $d))
                ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate($column, '<=', $d)))
            // Without an indicator an applied range leaves nothing in the bar to say so or to clear
            // it — the defect SW-025 records for the entity filters' chips.
            ->indicateUsing(function (array $data) use ($column, $label): ?string {
                $from = $data['from'] ?? null;
                $until = $data['until'] ?? null;

                if (! $from && ! $until) {
                    return null;
                }

                $name = $label ?? __('admin.fields.'.$column);

                // d/m/Y, matching the five older hand-written copies — a chip printing raw ISO
                // beside chips printing d/m/Y reads as two different features.
                $show = fn ($d): string => $d
                    ? CarbonImmutable::parse($d)->format('d/m/Y')
                    : '…';

                return $name.': '.$show($from).' → '.$show($until);
            });
    }
}
