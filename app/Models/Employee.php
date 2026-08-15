<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\Attributes\DeletableWhenUnused;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An employee on the operator's payroll (module 24 — HR), scoped to one property.
 * The register of real staff — distinct from `users` (admin logins) and
 * `tenant_users` (retailer portal). Base salary drives future per-employee payslips
 * (Phase 3); advances/loans (Phase 2) attach to it.
 */
#[DeletableWhenUnused(blockedBy: ['payrollLines', 'advances', 'custodies'], instead: 'set the employee inactive — payroll history is a statutory record')]
class Employee extends Model
{
    use RefusesDeletionWhenReferenced, HasFactory, HasSearchText, LogsActivity, SoftDeletes;

    protected $fillable = [
        'asset_id',
        'department_id',
        'code',
        'name',
        'national_id',
        'position',
        'hire_date',
        'base_salary',
        'payment_method',
        'phone',
        'status',
        'terminated_on',
        'notes',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'terminated_on' => 'date',
        'base_salary' => 'decimal:2',
    ];

    // A new employee is active in-memory too (not just via the DB default), so status
    // checks (e.g. grant-advance) are correct before the row is reloaded.
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * Name, staff code, position and contact. `national_id` stays searchable because HR
     * genuinely looks staff up by it when reconciling payroll against the tax file.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->name,
            $this->code,
            $this->national_id,
            $this->position,
            $this->phone,
            self::digitsOf($this->phone),
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['asset_id', 'department_id', 'code', 'name', 'position', 'base_salary', 'payment_method', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('employee');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Payslip lines this employee appears on.
     *
     * `payroll_lines.employee_id` existed with no inverse, so an employee who has been paid looked
     * exactly like one who never has.
     */
    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class);
    }

    public function custodies(): HasMany
    {
        return $this->hasMany(Custody::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    protected static function booted(): void
    {
        // NOT-NULL guard for base_salary (blank form field must not send null).
        static::saving(function (self $employee) {
            $raw = $employee->getAttributes()['base_salary'] ?? null;
            if ($raw === null || $raw === '') {
                $employee->base_salary = 0;
            }
        });
    }
}
