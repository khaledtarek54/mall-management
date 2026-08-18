<?php

/**
 * The accruing end-of-service gratuity liability (مكافأة نهاية الخدمة).
 *
 * Payroll books the employee withholdings and the EMPLOYER social-insurance contribution, so
 * month-to-month labour cost is right — but an entitlement that builds up silently over a career
 * appeared nowhere. If it is owed, the books understate both the expense and the liability by the
 * whole accrued amount, and nobody sees the gap until somebody leaves.
 *
 * **Entitlement is not assumed, and the OFF default is the first thing pinned below.** Labour Law
 * 12/2003 Art. 122 applies to workers *not covered by the social insurance law*, and in Egypt most
 * employees are covered — unlike the Gulf. Accruing a provision nobody owes overstates the
 * liability exactly as surely as omitting a real one understates it, so the decision is the
 * accountant's and the software's job is to make the number visible for it.
 */

use App\Models\Employee;
use App\Services\GratuityService;
use App\Settings\PayrollSettings;
use Carbon\CarbonImmutable;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();

    $this->settings = app(PayrollSettings::class);
    $this->settings->gratuity_enabled = true;
    $this->settings->gratuity_days_first_five = 15.0;
    $this->settings->gratuity_days_thereafter = 30.0;

    $this->svc = new GratuityService($this->settings);
});

function gratuityEmployee(array $attrs = []): Employee
{
    return Employee::create(array_merge([
        'asset_id' => test()->asset->id,
        'name' => 'Sara Kamal',
        'code' => 'EMP-'.bin2hex(random_bytes(3)),
        'hire_date' => '2020-01-01',
        'base_salary' => 9000,
        'status' => 'active',
    ], $attrs));
}

/* ---- it is OFF until somebody decides ------------------------------------ */

it('ships switched off', function () {
    // The default matters more than the formula: in Egypt an employer covered by social insurance
    // often owes no gratuity at all, so an on-by-default provision would be a fabricated liability
    // on every balance sheet.
    $fresh = new GratuityService(app(PayrollSettings::class));

    expect((new PayrollSettings)->gratuity_enabled)->toBeFalse()
        ->and($fresh->enabled())->toBe((bool) app(PayrollSettings::class)->gratuity_enabled);
});

it('reports whether it is on, so a reader is never left guessing at a zero', function () {
    $this->settings->gratuity_enabled = false;

    expect((new GratuityService($this->settings))->exposure([$this->asset->id])['enabled'])->toBeFalse();

    $this->settings->gratuity_enabled = true;

    expect((new GratuityService($this->settings))->exposure([$this->asset->id])['enabled'])->toBeTrue();
});

/* ---- Art. 122: half a month, then a full month --------------------------- */

it('accrues half a month per year for the first five years', function () {
    // 3 years exactly, 9,000/month → daily 300 → 3 × 15 × 300 = 13,500.
    $e = gratuityEmployee(['hire_date' => '2023-01-01', 'base_salary' => 9000]);

    expect($this->svc->accruedFor($e, CarbonImmutable::parse('2026-01-01')))
        ->toBeGreaterThan(13400.0)->toBeLessThan(13600.0);
});

it('steps up to a full month per year after five', function () {
    // 8 years: 5 × 15 + 3 × 30 = 165 days × 300 = 49,500. Straight 15 days would give 36,000, so
    // this also proves the second tier is really applied rather than the first extended.
    $e = gratuityEmployee(['hire_date' => '2018-01-01', 'base_salary' => 9000]);

    expect($this->svc->accruedFor($e, CarbonImmutable::parse('2026-01-01')))
        ->toBeGreaterThan(49300.0)->toBeLessThan(49700.0);
});

it('builds continuously rather than jumping at the anniversary', function () {
    // A provision that stepped once a year would be wrong for eleven months of it.
    $e = gratuityEmployee(['hire_date' => '2024-01-01', 'base_salary' => 9000]);

    $half = $this->svc->accruedFor($e, CarbonImmutable::parse('2024-07-01'));
    $full = $this->svc->accruedFor($e, CarbonImmutable::parse('2025-01-01'));

    expect($half)->toBeGreaterThan(0.0)->toBeLessThan($full);
});

it('follows the settings, because a contract may be more generous than the floor', function () {
    $this->settings->gratuity_days_first_five = 30.0;
    $svc = new GratuityService($this->settings);

    $e = gratuityEmployee(['hire_date' => '2023-01-01', 'base_salary' => 9000]);

    // Twice the statutory floor → twice the accrual.
    expect($svc->accruedFor($e, CarbonImmutable::parse('2026-01-01')))
        ->toBeGreaterThan(26800.0)->toBeLessThan(27200.0);
});

/* ---- edges that would otherwise produce a wrong liability ---------------- */

it('accrues nothing for a record with no hire date', function () {
    // `employees.hire_date` is NOT NULL, so the database cannot produce this — the guard is for the
    // in-memory instance, which is how the service is actually called (a model built by an importer
    // or a form before it is saved). Asserted on an UNSAVED model for that reason: writing it as a
    // DB fixture would just fail the insert and prove nothing about the arithmetic.
    $unsaved = new Employee(['name' => 'No dates', 'base_salary' => 9000]);

    expect($this->svc->accruedFor($unsaved))->toBe(0.0);
});

it('stops the clock at termination rather than accruing forever', function () {
    // Left in 2022; asking in 2026 must not add four more years to what was owed on the way out.
    $e = gratuityEmployee(['hire_date' => '2020-01-01', 'terminated_on' => '2022-01-01']);

    $atLeaving = $this->svc->accruedFor($e, CarbonImmutable::parse('2022-01-01'));

    expect($this->svc->accruedFor($e, CarbonImmutable::parse('2026-01-01')))->toBe($atLeaving);
});

it('leaves terminated staff out of the exposure', function () {
    // Whatever they were owed is settled or is a payable in its own right; counting them here would
    // double the liability at the moment it crystallises.
    gratuityEmployee(['hire_date' => '2020-01-01']);
    gratuityEmployee(['hire_date' => '2020-01-01', 'status' => 'terminated', 'terminated_on' => '2025-01-01']);

    $exposure = $this->svc->exposure([$this->asset->id]);

    expect($exposure['headcount'])->toBe(1)
        ->and($exposure['total'])->toBeGreaterThan(0.0);
});

it('scopes the exposure to the properties asked for', function () {
    $other = makeAsset();
    gratuityEmployee(['hire_date' => '2020-01-01']);
    Employee::create([
        'asset_id' => $other->id, 'name' => 'Other', 'code' => 'EMP-OTH',
        'hire_date' => '2020-01-01', 'base_salary' => 20000, 'status' => 'active',
    ]);

    expect($this->svc->exposure([$this->asset->id])['headcount'])->toBe(1)
        ->and($this->svc->exposure([$other->id])['headcount'])->toBe(1)
        ->and($this->svc->exposure([$this->asset->id])['total'])
        ->not->toBe($this->svc->exposure([$other->id])['total']);
});
