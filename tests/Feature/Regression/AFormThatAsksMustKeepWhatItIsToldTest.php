<?php

use App\Filament\Admin\RelationManagers\PayrollLinesRelationManager;
use App\Filament\Admin\Resources\Payrolls\Pages\EditPayroll;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollLine;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A form that asks for a figure must keep it.
 *
 * The payroll add-line modal renders EIGHT money fields and the create wrote THREE — `gross`,
 * `salary_tax`, `social_insurance` — silently discarding `allowances`, `other_deductions`,
 * `deduction_note` and `employer_social_insurance`. So an operator entered an allowance and a
 * deduction, pressed Add, and got a payslip that ignored both: no error, and a net figure that
 * looked deliberate. The employee is paid the wrong amount and the only evidence is a number nobody
 * has a reason to re-derive.
 *
 * The fields are enumerated from one register now, so a ninth added to the modal is carried by
 * being added there rather than by anyone remembering this hook.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(RolesPermissionsSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);

    $this->employee = Employee::create([
        'asset_id' => $this->asset->id,
        'name' => 'Mona Adel',
        'code' => 'EMP-'.substr(uniqid(), -6),
        'status' => 'active',
        'hire_date' => CarbonImmutable::now()->subYear()->toDateString(),
        'base_salary' => 12000,
        'payment_method' => 'bank',
    ]);

    $this->run = Payroll::create([
        'asset_id' => $this->asset->id,
        'period_month' => CarbonImmutable::now()->startOfMonth()->toDateString(),
        'status' => 'draft',
    ]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('keeps every figure the add-line modal asked for', function () {
    Livewire::test(PayrollLinesRelationManager::class, [
        'ownerRecord' => $this->run,
        'pageClass' => EditPayroll::class,
    ])->callTableAction('add_line', data: [
        'employee_id' => $this->employee->id,
        'gross' => 12000,
        'allowances' => 1500,
        'salary_tax' => 900,
        'social_insurance' => 1320,
        'other_deductions' => 250,
        'deduction_note' => 'Canteen',
        'employer_social_insurance' => 2100,
    ]);

    $line = PayrollLine::query()->where('payroll_id', $this->run->id)->firstOrFail();

    expect(round((float) $line->gross, 2))->toEqual(12000.0)
        // The four that were thrown away.
        ->and(round((float) $line->allowances, 2))->toEqual(1500.0)
        ->and(round((float) $line->other_deductions, 2))->toEqual(250.0)
        ->and($line->deduction_note)->toBe('Canteen')
        ->and(round((float) $line->employer_social_insurance, 2))->toEqual(2100.0)
        // …and the three that always worked, so this cannot pass by writing everything blindly.
        ->and(round((float) $line->salary_tax, 2))->toEqual(900.0)
        ->and(round((float) $line->social_insurance, 2))->toEqual(1320.0);
});

it('carries every money field the modal renders — no ninth can be forgotten', function () {
    // The register and the modal must agree. A field rendered but not registered is discarded
    // silently, which is the whole defect; `advance_deduction` is the one deliberate exception and
    // is named as such.
    $rendered = collect((new ReflectionClass(PayrollLinesRelationManager::class))
        ->getMethod('moneyFields')->invoke(app(PayrollLinesRelationManager::class)))
        ->map(fn ($field): string => (string) $field->getName())
        ->reject(fn (string $name): bool => $name === 'advance_deduction')
        ->values()
        ->all();

    $registered = (new ReflectionClass(PayrollLinesRelationManager::class))
        ->getConstant('LINE_MONEY_FIELDS');

    expect($rendered)->not->toBeEmpty()
        ->and(array_diff($rendered, $registered))->toBe([]);
});
