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
 *
 * @property-read float $net Gross − salary tax − social insurance (accessor).
 */
class PayrollLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_id',
        'employee_id',
        'employee_advance_id',
        'gross',
        'allowances',
        'salary_tax',
        'social_insurance',
        'advance_deduction',
        'other_deductions',
        'deduction_note',
        'employer_social_insurance',
        'notes',
    ];

    protected $casts = [
        'gross' => 'decimal:2',
        'allowances' => 'decimal:2',
        'salary_tax' => 'decimal:2',
        'social_insurance' => 'decimal:2',
        'advance_deduction' => 'decimal:2',
        'other_deductions' => 'decimal:2',
        'employer_social_insurance' => 'decimal:2',
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

    /** The advance/loan this line repays via a payroll installment (Phase 4b), if any. */
    public function employeeAdvance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class)->withTrashed();
    }

    /**
     * Net pay = gross − salary tax − social insurance − advance installment − other deductions.
     * Employer SI is NOT deducted (it's a company cost). The advance installment IS deducted
     * (repays the loan); ad-hoc/penalty deductions (خصومات) are deducted too.
     */
    protected function net(): Attribute
    {
        return Attribute::make(
            get: fn () => round((float) $this->gross - (float) $this->salary_tax - (float) $this->social_insurance - (float) $this->advance_deduction - (float) $this->other_deductions, 2),
        );
    }

    /** Basic pay = gross − allowances (the earnings breakdown; gross is the source of truth). */
    protected function basic(): Attribute
    {
        return Attribute::make(
            get: fn () => round((float) $this->gross - (float) $this->allowances, 2),
        );
    }

    protected static function booted(): void
    {
        // NOT-NULL guard for the money columns (read RAW — decimal cast throws on '').
        static::saving(function (self $line) {
            foreach (['gross', 'allowances', 'salary_tax', 'social_insurance', 'advance_deduction', 'other_deductions', 'employer_social_insurance'] as $column) {
                $raw = $line->getAttributes()[$column] ?? null;
                if ($raw === null || $raw === '') {
                    $line->{$column} = 0;
                }
            }

            // An installment with no advance linked (or vice-versa) is meaningless — a
            // deduction must name the advance it repays, or be zero.
            if ((float) $line->advance_deduction > 0 && $line->employee_advance_id === null) {
                throw new \DomainException(__('admin.payroll_lines.errors.advance_deduction_without_advance'));
            }
            if ((float) $line->advance_deduction <= 0) {
                $line->employee_advance_id = null; // no installment → no link
            }

            // Allowances are a PORTION of gross — they cannot exceed it (else basic goes
            // negative). Guards the itemisation; gross stays the total-earnings source of truth.
            if ((float) $line->allowances > (float) $line->gross) {
                throw new \DomainException(__('admin.payroll_lines.errors.allowances_exceed_gross'));
            }

            // A LINE's net must not go negative. The run header already refuses a net-negative
            // TOTAL (PayrollService::approve), but that's an aggregate: one employee's
            // deductions can exceed their gross while the run sums positive, and the payslip
            // then prints "Net −1,000" on a frozen run that can't be corrected (gap-analysis
            // F-90b). The relation-manager form validates this inline; this is the invariant
            // backstop for any other write path.
            if ($line->net < 0) {
                throw new \DomainException(__('admin.payroll_lines.errors.net_negative'));
            }
        });

        // Keep the run header (and thus the GL) in lock-step with Σ lines.
        static::saved(fn (self $line) => $line->payroll?->recomputeFromLines());
        static::deleted(fn (self $line) => $line->payroll?->recomputeFromLines());
    }
}
