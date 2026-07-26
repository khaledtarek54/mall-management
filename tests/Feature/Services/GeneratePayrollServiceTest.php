<?php

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Services\GeneratePayrollService;
use App\Settings\PayrollSettings;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->run = Payroll::create([
        'asset_id' => $this->asset->id,
        'period_month' => now()->startOfMonth()->toDateString(),
        'gross_salaries' => 0, 'salary_tax' => 0, 'social_insurance' => 0,
        'paid_from' => 'bank', 'status' => 'draft',
    ]);
});

function makeRosterEmployee(int $assetId, array $attrs = []): Employee
{
    return Employee::create(array_merge([
        'asset_id' => $assetId, 'code' => 'E-'.uniqid(), 'name' => 'Emp '.uniqid(),
        'hire_date' => '2026-01-01', 'base_salary' => 5000, 'payment_method' => 'bank',
    ], $attrs));
}

function runGenerate(Payroll $run): array
{
    return app(GeneratePayrollService::class)->generate($run);
}

it('generates one line per active employee, gross = base salary', function () {
    makeRosterEmployee($this->asset->id, ['base_salary' => 8000]);
    makeRosterEmployee($this->asset->id, ['base_salary' => 5000]);
    // Terminated employees are not on the roster for a new run.
    makeRosterEmployee($this->asset->id, ['base_salary' => 9000, 'status' => 'terminated', 'terminated_on' => '2026-06-30']);

    $result = runGenerate($this->run);

    expect($result['added'])->toBe(2);
    expect(PayrollLine::where('payroll_id', $this->run->id)->count())->toBe(2);
    expect(PayrollLine::where('payroll_id', $this->run->id)->pluck('gross')->map(fn ($g) => (float) $g)->sort()->values()->all())
        ->toBe([5000.0, 8000.0]);

    // The header derives from Σ lines (rates default to 0 → net = gross).
    $this->run->refresh();
    expect((float) $this->run->gross_salaries)->toBe(13000.0);
    expect((float) $this->run->net_paid)->toBe(13000.0);
});

it('pre-fills deductions from the configured rates', function () {
    $settings = app(PayrollSettings::class);
    $settings->social_insurance_rate = 10.0;          // 10% of gross (employee)
    $settings->salary_tax_rate = 5.0;                 // 5% of gross
    $settings->employer_social_insurance_rate = 18.75; // employer share — company cost
    $settings->save();

    makeRosterEmployee($this->asset->id, ['base_salary' => 10000]);

    runGenerate($this->run);

    $line = PayrollLine::where('payroll_id', $this->run->id)->firstOrFail();
    expect((float) $line->gross)->toBe(10000.0);
    expect((float) $line->salary_tax)->toBe(500.0);
    expect((float) $line->social_insurance)->toBe(1000.0);
    expect((float) $line->employer_social_insurance)->toBe(1875.0); // 18.75% of gross
    expect((float) $line->net)->toBe(8500.0);                       // employer SI does NOT reduce net

    $this->run->refresh();
    expect((float) $this->run->net_paid)->toBe(8500.0);
    expect((float) $this->run->employer_social_insurance)->toBe(1875.0);
});

it('skips employees already on a line (idempotent, then adds only new staff)', function () {
    makeRosterEmployee($this->asset->id);
    makeRosterEmployee($this->asset->id);

    expect(runGenerate($this->run)['added'])->toBe(2);

    // Second run adds nothing — the same roster is already lined.
    $second = runGenerate($this->run);
    expect($second['added'])->toBe(0);
    expect($second['skipped_existing'])->toBe(2);
    expect(PayrollLine::where('payroll_id', $this->run->id)->count())->toBe(2);

    // A newly hired employee is the only one added on the next generate.
    makeRosterEmployee($this->asset->id);
    expect(runGenerate($this->run)['added'])->toBe(1);
    expect(PayrollLine::where('payroll_id', $this->run->id)->count())->toBe(3);
});

it('refuses to generate on a non-draft run', function () {
    $this->run->update(['status' => 'approved']);
    makeRosterEmployee($this->asset->id);

    expect(fn () => runGenerate($this->run))->toThrow(DomainException::class);
    expect(PayrollLine::where('payroll_id', $this->run->id)->count())->toBe(0);
});

it('never pulls an employee from another property', function () {
    $other = makeAsset(['code' => 'OTHER']);
    makeRosterEmployee($other->id, ['base_salary' => 7000]);
    makeRosterEmployee($this->asset->id, ['base_salary' => 4000]);

    runGenerate($this->run);

    $lines = PayrollLine::where('payroll_id', $this->run->id)->get();
    expect($lines)->toHaveCount(1);
    expect((float) $lines->first()->gross)->toBe(4000.0);
});

it('caps deductions so a generated line never goes net-negative', function () {
    $settings = app(PayrollSettings::class);
    $settings->social_insurance_rate = 80.0;
    $settings->salary_tax_rate = 80.0; // 160% combined — would drive net negative uncapped
    $settings->save();

    makeRosterEmployee($this->asset->id, ['base_salary' => 1000]);

    runGenerate($this->run); // must not throw the net-negative invariant

    $line = PayrollLine::where('payroll_id', $this->run->id)->firstOrFail();
    expect((float) $line->net)->toBeGreaterThanOrEqual(0.0);
});

it('flags active employees with no base salary set', function () {
    makeRosterEmployee($this->asset->id, ['base_salary' => 0]);
    makeRosterEmployee($this->asset->id, ['base_salary' => 6000]);

    $result = runGenerate($this->run);

    expect($result['added'])->toBe(2);
    expect($result['zero_salary'])->toBe(1);
});
