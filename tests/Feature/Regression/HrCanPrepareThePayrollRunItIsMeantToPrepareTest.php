<?php

/*
|--------------------------------------------------------------------------
| Regression — HR can prepare a payroll run, and still cannot approve one
|--------------------------------------------------------------------------
| `RolesPermissionsSeeder`'s hr grant carries a comment stating its own premise — *"HR generates the
| run and must be able to see what it will compute on. Writing it is the accountant's"* — and then
| granted `payroll_rates.view` and NOTHING from the `payrolls.*` family. Measured on the dev
| database 2026-09-04: hr held exactly `payroll_rates.view`, so `PayrollResource::canViewAny()`
| (`payrolls.view`, via `RoleGatedActions`) refused, the Payrolls screen was absent from hr's
| sidebar, and the URL answered 403.
|
| The second half is the one that reads as a broken screen rather than a missing right.
| `EmployeePayslipsRelationManager` renders on the Employee record — which hr opens through
| `employees.view` — and prints gross, salary tax, social insurance and net for every month. Only
| the payslip PDF beside those figures is gated, on `payrolls.view`. So HR read every number on the
| payslip and could not hand the employee the payslip.
|
| `payrolls.approve` stays withheld, deliberately and for the reason the seeder already gives one
| line above about `payroll_rates`: approval is what makes a run POSTABLE, and the accountant
| answers for the entry. Preparing and approving are two acts; that is Yardi's split too.
*/

use App\Filament\Admin\RelationManagers\EmployeePayslipsRelationManager;
use App\Filament\Admin\Resources\Employees\Pages\EditEmployee;
use App\Filament\Admin\Resources\Payrolls\Pages\EditPayroll;
use App\Filament\Admin\Resources\Payrolls\Pages\ListPayrolls;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\PayrollRuns;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    $this->run = PayrollRuns::run($this->asset, 1);
    $this->line = $this->run->lines()->first();
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('lets hr open the payroll register it is asked to prepare', function () {
    $hr = makeUser('hr', [$this->asset->id]);

    expect($hr->can('payrolls.view'))->toBeTrue()
        ->and($hr->can('payrolls.create'))->toBeTrue()
        ->and($hr->can('payrolls.edit'))->toBeTrue();

    $this->actingAs($hr);
    Filament::setTenant($this->asset);

    Livewire::test(ListPayrolls::class)->assertOk();
});

it('lets hr hand the employee the payslip whose figures it already reads', function () {
    $this->actingAs(makeUser('hr', [$this->asset->id]));
    Filament::setTenant($this->asset);

    Livewire::test(EmployeePayslipsRelationManager::class, [
        'ownerRecord' => $this->line->employee,
        'pageClass' => EditEmployee::class,
    ])->assertTableActionVisible('payslip', $this->line);
});

it('still refuses hr the approval that makes a run postable', function () {
    // The withholding is the point of the split, so it is asserted rather than assumed. A grant
    // that quietly widened to `payrolls.approve` would hand HR the salary-expense entry.
    $hr = makeUser('hr', [$this->asset->id]);

    expect($hr->can('payrolls.approve'))->toBeFalse();

    $this->actingAs($hr);
    Filament::setTenant($this->asset);

    Livewire::test(EditPayroll::class, ['record' => $this->run->getRouteKey()])
        ->assertActionHidden('approve');
});

it('still offers the approval to the accountant who answers for the entry', function () {
    // The control. A refusal test alone passes just as happily when nothing is reachable at all.
    $accounting = makeUser('accounting', [$this->asset->id]);

    expect($accounting->can('payrolls.approve'))->toBeTrue();

    $this->actingAs($accounting);
    Filament::setTenant($this->asset);

    Livewire::test(EditPayroll::class, ['record' => $this->run->getRouteKey()])
        ->assertActionVisible('approve');
});

it('does not widen hr beyond payroll preparation', function () {
    // A grant is a list, and a list grows by accident. These are the neighbours a payroll right is
    // most easily confused with; none of them is HR's.
    $hr = makeUser('hr');

    $held = collect(['journal_entries.post', 'invoices.create', 'settings.manage', 'payroll_rates.edit', 'payrolls.approve'])
        ->filter(fn (string $permission): bool => $hr->can($permission))
        ->values()
        ->all();

    expect($held)->toBe([], 'hr should not hold: '.implode(', ', $held));
});
