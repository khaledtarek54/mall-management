<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's slice of a payroll run (module 24, Phase 3) — the basis for a
 * payslip. Net is DERIVED (gross − tax − insurance), matching the run header's own
 * derivation. Saving/deleting a line re-derives the parent run's aggregates from Σ
 * lines (Payroll::recomputeFromLines), so the header — and the GL it posts — always
 * ties to the sum of the lines.
 */
class PayrollLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_id',
        'employee_id',
        'gross',
        'salary_tax',
        'social_insurance',
        'notes',
    ];

    protected $casts = [
        'gross' => 'decimal:2',
        'salary_tax' => 'decimal:2',
        'social_insurance' => 'decimal:2',
    ];

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function employee(): BelongsTo
    {
        // withTrashed so a FROZEN run's payslips stay reproducible after staff turnover
        // (an employee may be soft-deleted long after their pay run was approved). New
        // lines still pick from LIVE employees only — that uses a separate query.
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** Net pay = gross − salary tax − social insurance. */
    protected function net(): Attribute
    {
        return Attribute::make(
            get: fn () => round((float) $this->gross - (float) $this->salary_tax - (float) $this->social_insurance, 2),
        );
    }

    protected static function booted(): void
    {
        // NOT-NULL guard for the money columns (read RAW — decimal cast throws on '').
        static::saving(function (self $line) {
            foreach (['gross', 'salary_tax', 'social_insurance'] as $column) {
                $raw = $line->getAttributes()[$column] ?? null;
                if ($raw === null || $raw === '') {
                    $line->{$column} = 0;
                }
            }
        });

        // Keep the run header (and thus the GL) in lock-step with Σ lines.
        static::saved(fn (self $line) => $line->payroll?->recomputeFromLines());
        static::deleted(fn (self $line) => $line->payroll?->recomputeFromLines());
    }
}
