<?php

use App\Filament\Admin\Resources\PayrollRates\Pages\CreatePayrollRate;
use App\Models\PayrollRate;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **THE INSURABLE-WAGE CEILING COULD NOT BE SAVED AT ALL** (SW-203, and more than the row claimed).
 *
 * The row said the `gte:insurable_wage_floor` rule refused a rung with a ceiling and a blank floor,
 * *"though a null floor is legal (no bound)"*. That half is real. Measured against HEAD, so is a
 * larger one underneath it: **the rule refused every ceiling there has ever been**, including the
 * 2,700 / 16,700 band the 2026-08-22 migration itself inserts.
 *
 * A rule string reaches the validator verbatim — measured, `TextInput::make('insurable_wage_ceiling')
 * ->numeric()->minValue(0)->rules(['gte:insurable_wage_floor'])->getValidationRules()` returns
 * `['nullable','numeric','min:0','gte:insurable_wage_floor']` — while the attribute it is keyed
 * under is `data.insurable_wage_ceiling`, because a Filament resource form lives at the `data`
 * state path. `Validator::getValue('insurable_wage_floor')` therefore looked at the ROOT of the
 * Livewire payload, found nothing, and `validateGte()` fell through to
 * `isSameType('16700', null)` — false. Measured on that exact rules array: floor 2,700 with
 * ceiling 16,700 FAILS, floor null with ceiling 16,700 FAILS, and both floor and ceiling blank
 * passes. So Egypt's statutory band could not be entered through the screen built to hold it, and
 * the operator's next real act — the January decree, on a ladder whose whole point is entering a
 * rise in advance — is exactly the case that was refused.
 *
 * Filament's own `->gte('insurable_wage_floor')` resolves the path and still refuses the second
 * case, because `validateGte()` answers false against a null comparison value. **A null bound is NO
 * bound** (`PayrollRates::insurableWage()` skips a null floor entirely), so the rule is stated as a
 * closure where both halves are visible at once.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);

    $this->asset = makeAsset();
    $this->actingAs(makeUser('accounting', [$this->asset->id]));

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // The 2026-08-22 migration ships one rung (2,700 / 16,700 from 1 Jan 2026), so this is a
    // baseline rather than a literal — anything this test creates is the newest row on top of it.
    $this->baseline = PayrollRate::query()->count();
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('saves the statutory band the system itself ships', function () {
    asTenant($this->asset, function () {
        Livewire::test(CreatePayrollRate::class)
            ->fillForm([
                'effective_from' => '2027-01-01',
                'insurable_wage_floor' => 2700,
                'insurable_wage_ceiling' => 16700,
                'employee_social_insurance_rate' => 11,
                'employer_social_insurance_rate' => 18.75,
                'salary_tax_rate' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    });

    $rung = PayrollRate::query()->latest('id')->first();

    expect(PayrollRate::query()->count())->toBe($this->baseline + 1)
        ->and((float) $rung->insurable_wage_floor)->toBe(2700.0)
        ->and((float) $rung->insurable_wage_ceiling)->toBe(16700.0);
});

it('saves a ceiling with no floor, because a null bound is no bound', function () {
    asTenant($this->asset, function () {
        Livewire::test(CreatePayrollRate::class)
            ->fillForm([
                'effective_from' => '2027-01-01',
                'insurable_wage_floor' => null,
                'insurable_wage_ceiling' => 20000,
                'employee_social_insurance_rate' => 11,
                'employer_social_insurance_rate' => 18.75,
                'salary_tax_rate' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    });

    $rung = PayrollRate::query()->latest('id')->first();

    // Stored as NOT STATED, which is what `PayrollRates::insurableWage()` reads as "no minimum" —
    // never as a zero floor, which would be a different and silently wrong claim.
    expect($rung->insurable_wage_floor)->toBeNull()
        ->and((float) $rung->insurable_wage_ceiling)->toBe(20000.0);
});

it('still refuses a ceiling below a floor that was actually stated', function () {
    // The refusal the original rule was reaching for, paired with the two controls above so a rule
    // that refused everything — which is what shipped — cannot read as a pass.
    asTenant($this->asset, function () {
        Livewire::test(CreatePayrollRate::class)
            ->fillForm([
                'effective_from' => '2027-01-01',
                'insurable_wage_floor' => 20000,
                'insurable_wage_ceiling' => 16700,
                'employee_social_insurance_rate' => 11,
                'employer_social_insurance_rate' => 18.75,
                'salary_tax_rate' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['insurable_wage_ceiling']);
    });

    // Nothing was written: an inverted band would insure every employee at a figure under the
    // statutory minimum, on every payslip, silently.
    expect(PayrollRate::query()->count())->toBe($this->baseline);
});
