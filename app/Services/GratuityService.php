<?php

namespace App\Services;

use App\Models\Employee;
use App\Settings\PayrollSettings;
use App\Support\TenantScope;
use Carbon\CarbonImmutable;

/**
 * The accruing end-of-service gratuity liability (مكافأة نهاية الخدمة).
 *
 * **What was missing.** Payroll books the employee withholdings and the EMPLOYER social-insurance
 * contribution, so month-to-month labour cost is right — but an entitlement that builds up silently
 * over a career appeared nowhere. If it is owed, the books understate both the expense and the
 * liability by the whole accrued amount, and nobody sees the gap until somebody leaves.
 *
 * **Egyptian Labour Law 12/2003, Art. 122**: half a month's pay for each of the first five years of
 * service, one month's pay for each year after that. Both figures are settings rather than
 * constants, because a contract may be more generous than the floor and often is.
 *
 * **Entitlement is NOT assumed.** Art. 122 applies to workers *not covered by the social insurance
 * law*, and in Egypt most employees are covered — unlike the Gulf, where an EOS gratuity is close
 * to universal. So this ships **switched off**: whether this workforce is entitled is a question
 * about their contracts and their insurance status, and accruing a provision nobody owes overstates
 * the liability exactly as surely as omitting a real one understates it. Same treatment straight-
 * line rent gets under EAS 49 — built, correct, and inert until an accountant decides.
 *
 * **Nothing here posts.** It reports the exposure so the decision can be made against a number
 * rather than a feeling. Wiring it to the GL is a separate step that should follow the entitlement
 * ruling, not precede it — a journalizer would put a provision on the balance sheet before anyone
 * had established it was owed.
 */
class GratuityService
{
    public function __construct(private PayrollSettings $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->gratuity_enabled;
    }

    /**
     * What one employee has accrued to a date.
     *
     * Pro-rated within a year rather than stepped at the anniversary: the liability builds
     * continuously, and a provision that jumps once a year would be wrong for eleven months of it.
     */
    public function accruedFor(Employee $employee, ?CarbonImmutable $on = null): float
    {
        $on ??= CarbonImmutable::now();

        if (blank($employee->hire_date)) {
            return 0.0;
        }

        $start = CarbonImmutable::parse($employee->hire_date);
        $end = $employee->terminated_on ? CarbonImmutable::parse($employee->terminated_on) : $on;

        if ($end->lte($start)) {
            return 0.0;
        }

        $years = $start->diffInDays($end) / 365.25;
        $daily = round(((float) $employee->base_salary) / 30, 4);

        $firstFive = min($years, 5.0) * (float) $this->settings->gratuity_days_first_five;
        $after = max(0.0, $years - 5.0) * (float) $this->settings->gratuity_days_thereafter;

        return round(($firstFive + $after) * $daily, 2);
    }

    /**
     * The portfolio (or property) exposure, employee by employee.
     *
     * Terminated staff are excluded: whatever they were owed has been settled or is a payable in
     * its own right, and leaving them in would double the liability at exactly the moment it
     * crystallises.
     *
     * @return array{enabled: bool, total: float, headcount: int, rows: array<int, array{employee: Employee, years: float, accrued: float}>}
     */
    public function exposure(?array $assetIds = null, ?CarbonImmutable $on = null): array
    {
        $on ??= CarbonImmutable::now();
        $assetIds ??= TenantScope::visibleAssetIds();

        $employees = Employee::query()
            ->where('status', 'active')
            ->when($assetIds !== null, fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->orderBy('hire_date')
            ->get();

        $rows = [];
        $total = 0.0;

        foreach ($employees as $employee) {
            $accrued = $this->accruedFor($employee, $on);
            $total += $accrued;

            $rows[] = [
                'employee' => $employee,
                'years' => blank($employee->hire_date)
                    ? 0.0
                    : round(CarbonImmutable::parse($employee->hire_date)->diffInDays($on) / 365.25, 2),
                'accrued' => $accrued,
            ];
        }

        return [
            'enabled' => $this->enabled(),
            'total' => round($total, 2),
            'headcount' => $employees->count(),
            'rows' => $rows,
        ];
    }
}
