<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Payroll;
use App\Settings\PayrollSettings;
use App\Support\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds a draft payroll run's per-employee lines FROM the active roster — the
 * Odoo-style "generate payslips" step, so the operator reviews computed slips instead
 * of typing lump-sum totals. One line per active employee in the run's property, with:
 *
 *   gross            = the employee's base salary (master data — always correct)
 *   salary_tax       = gross × PayrollSettings::salary_tax_rate      (0 by default)
 *   social_insurance = gross × PayrollSettings::social_insurance_rate (0 by default)
 *
 * The lines ARE the review surface (the relation-manager table); the run header then
 * DERIVES from Σ lines (Payroll::recomputeFromLines), so the GL and its tie-out are
 * untouched — this only automates data entry, it does not change what posts.
 *
 * Invariants honoured: draft-only (a settled run's lines are frozen), property scope
 * (never pulls an employee from another mall), one line per employee (existing lines
 * are skipped, never duplicated), and net ≥ 0 (deductions are capped so a generated
 * payslip can never print a negative net). Kept single-action + reusable, like the
 * other payroll services.
 */
class GeneratePayrollService
{
    /**
     * @return array{added:int, skipped_existing:int, zero_salary:int, gross:float}
     *
     * @throws \DomainException if the run is not a draft.
     */
    public function generate(Payroll $run): array
    {
        if ($run->status !== 'draft') {
            throw new \DomainException(__('admin.payroll_lines.errors.generate_not_draft'));
        }

        $settings = app(PayrollSettings::class);
        $taxRate = max(0.0, (float) $settings->salary_tax_rate) / 100;
        $siRate = max(0.0, (float) $settings->social_insurance_rate) / 100;
        $employerSiRate = max(0.0, (float) $settings->employer_social_insurance_rate) / 100;

        return DB::transaction(function () use ($run, $taxRate, $siRate, $employerSiRate) {
            // Lock the run row so two concurrent generates can't both pass the
            // already-lined check and race a duplicate past the unique index.
            $run = Payroll::whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            $alreadyLined = $run->lines()->pluck('employee_id')->all();

            $employees = $this->eligibleEmployees($run)
                ->when($alreadyLined, fn ($q) => $q->whereNotIn('id', $alreadyLined))
                ->get();

            $added = 0;
            $zeroSalary = 0;
            $gross = 0.0;

            foreach ($employees as $employee) {
                $lineGross = round((float) $employee->base_salary, 2);
                if ($lineGross <= 0) {
                    $zeroSalary++;
                }

                [$tax, $insurance] = $this->deductions($lineGross, $taxRate, $siRate);
                // Employer SI is a company cost — it does NOT reduce net, so no cap needed.
                $employerInsurance = round($lineGross * $employerSiRate, 2);

                $run->lines()->create([
                    'employee_id' => $employee->id,
                    'gross' => $lineGross,
                    'salary_tax' => $tax,
                    'social_insurance' => $insurance,
                    'employer_social_insurance' => $employerInsurance,
                ]);

                $added++;
                $gross += $lineGross;
            }

            // recomputeFromLines() ran on each line's saved hook; the header now ties to Σ lines.
            return [
                'added' => $added,
                'skipped_existing' => count($alreadyLined),
                'zero_salary' => $zeroSalary,
                'gross' => round($gross, 2),
            ];
        });
    }

    /**
     * How many active employees would still be added (nothing written) — powers the
     * confirmation preview so the operator sees the count before generating.
     */
    public function eligibleCount(Payroll $run): int
    {
        if ($run->status !== 'draft') {
            return 0;
        }

        $alreadyLined = $run->lines()->pluck('employee_id')->all();

        return $this->eligibleEmployees($run)
            ->when($alreadyLined, fn ($q) => $q->whereNotIn('id', $alreadyLined))
            ->count();
    }

    /**
     * Active employees in the run's property (or the caller's visible set for a
     * consolidated run) — mirrors PayrollLinesRelationManager::employeeQuery so the
     * generate + manual-add paths select from exactly the same pool.
     *
     * @return Builder<Employee>
     */
    private function eligibleEmployees(Payroll $run): Builder
    {
        $query = Employee::query()->active();

        if ($run->asset_id) {
            $query->where('asset_id', $run->asset_id);
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            $query->whereIn('asset_id', $ids);
        }

        return $query->orderBy('name');
    }

    /**
     * Compute (tax, insurance) for a line, capping so net = gross − tax − insurance stays
     * ≥ 0 even if the accountant sets rates summing past 100% — a generated payslip must
     * never print a negative net (the PayrollLine invariant would otherwise reject it).
     *
     * @return array{0: float, 1: float}
     */
    private function deductions(float $gross, float $taxRate, float $siRate): array
    {
        $tax = round($gross * $taxRate, 2);
        $insurance = round($gross * $siRate, 2);

        if ($tax + $insurance > $gross) {
            $tax = min($tax, $gross);
            $insurance = round($gross - $tax, 2);
        }

        return [$tax, $insurance];
    }
}
