<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
use App\Support\PayrollRates;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One rung of Egypt's statutory payroll ladder — the numbers in force from a date (EG-03).
 *
 * A row is a SET, not a single figure, because that is how they are published: one decree sets the
 * insurable-wage band and the contribution rates together, effective 1 January. The accountant adds
 * one row a year.
 *
 * A rung runs until the next one starts. There is deliberately no end date — a from/to pair makes
 * overlapping and missing windows representable, and this project has already been bitten by
 * exactly that on charge schedules.
 *
 * **Editing a rung already in force is allowed and safe.** An approved payroll's amounts are frozen
 * on its own lines (`payroll_lines.gross` / `salary_tax` / `social_insurance`), so an edit changes
 * what the NEXT generation computes and nothing that has been computed. That is the same rule the
 * whole money core runs on, and it is why this is not a `NeverDeletable` record: a rung posts
 * nothing and settles nothing.
 *
 * It is activity-logged all the same. *"Who moved the insurable-wage ceiling, when, and from what"*
 * is the first question anyone asks about a payroll figure, and until this table existed the three
 * numbers were undated settings with no record of what a past run had used.
 */
#[DeletionAllowed(reason: 'a dated rung posts and settles nothing — payroll lines freeze their own amounts, so removing a mis-keyed year changes no computed payroll')]
// Egypt's statutory numbers are national, not per mall.
#[PortfolioShared]
class PayrollRate extends Model
{
    use LogsActivity;

    protected $fillable = [
        'effective_from',
        'employee_social_insurance_rate',
        'employer_social_insurance_rate',
        'salary_tax_rate',
        'insurable_wage_floor',
        'insurable_wage_ceiling',
        'note',
    ];

    protected $casts = [
        'effective_from' => 'immutable_date',
        'employee_social_insurance_rate' => 'decimal:3',
        'employer_social_insurance_rate' => 'decimal:3',
        'salary_tax_rate' => 'decimal:3',
        'insurable_wage_floor' => 'decimal:2',
        'insurable_wage_ceiling' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'effective_from',
                'employee_social_insurance_rate',
                'employer_social_insurance_rate',
                'salary_tax_rate',
                'insurable_wage_floor',
                'insurable_wage_ceiling',
                'note',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('payroll_rate');
    }

    protected static function booted(): void
    {
        // The resolver memoises the ladder per request, and a write here fires no event on it — so
        // without this a rate the accountant just changed would keep generating the old figure for
        // the rest of the request, and for the rest of the day on a `queue:work` daemon.
        static::saved(fn () => PayrollRates::flush());
        static::deleted(fn () => PayrollRates::flush());
    }
}
