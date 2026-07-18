<?php

use App\Filament\Admin\RelationManagers\EmployeeAdvancesRelationManager;
use App\Filament\Admin\Resources\Employees\Pages\EditEmployee;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->employee = Employee::create([
        'asset_id' => $this->asset->id, 'code' => 'E-1', 'name' => 'Sara Ali',
        'hire_date' => now()->startOfYear()->toDateString(), 'base_salary' => 6000, 'payment_method' => 'bank',
    ]);
});

function advancesRM(Employee $employee)
{
    return Livewire::test(EmployeeAdvancesRelationManager::class, [
        'ownerRecord' => $employee,
        'pageClass' => EditEmployee::class,
    ]);
}

it('grants an advance via the relation manager (hr)', function () {
    $this->actingAs(makeUser('hr', [$this->asset->id]));

    advancesRM($this->employee)
        ->callTableAction('grant_advance', data: [
            'type' => 'loan', 'amount' => 4000, 'advance_date' => now()->toDateString(), 'paid_from' => 'bank',
        ])
        ->assertHasNoTableActionErrors();

    $advance = EmployeeAdvance::where('employee_id', $this->employee->id)->first();
    expect($advance)->not->toBeNull();
    expect($advance->type)->toBe('loan');
    expect((float) $advance->amount)->toBe(4000.0);
    expect((int) $advance->asset_id)->toBe($this->asset->id); // denormalised
});

it('records a repayment and reduces outstanding', function () {
    $this->actingAs(makeUser('hr', [$this->asset->id]));
    $advance = $this->employee->advances()->create([
        'asset_id' => $this->asset->id, 'amount' => 3000, 'advance_date' => now()->toDateString(), 'paid_from' => 'cash',
    ]);

    advancesRM($this->employee)
        ->callTableAction('record_repayment', $advance, data: [
            'amount' => 1200, 'repaid_on' => now()->toDateString(), 'method' => 'cash',
        ])
        ->assertHasNoTableActionErrors();

    expect(EmployeeAdvanceRepayment::where('employee_advance_id', $advance->id)->count())->toBe(1);
    expect($advance->fresh()->outstanding())->toBe(1800.0);
});

it('rejects a repayment above the outstanding balance (maxValue guard)', function () {
    $this->actingAs(makeUser('hr', [$this->asset->id]));
    $advance = $this->employee->advances()->create([
        'asset_id' => $this->asset->id, 'amount' => 3000, 'advance_date' => now()->toDateString(), 'paid_from' => 'cash',
    ]);

    advancesRM($this->employee)
        ->callTableAction('record_repayment', $advance, data: [
            'amount' => 5000, 'repaid_on' => now()->toDateString(), 'method' => 'cash',
        ])
        ->assertHasTableActionErrors(['amount']);

    expect(EmployeeAdvanceRepayment::where('employee_advance_id', $advance->id)->count())->toBe(0);
});

it('hides the grant action from a role without employees.grant_advance', function () {
    // viewer has employees.view but not grant_advance.
    $this->actingAs(makeUser('viewer', [$this->asset->id]));

    advancesRM($this->employee)->assertTableActionHidden('grant_advance');
});

it('hides the grant action for a terminated employee', function () {
    $this->actingAs(makeUser('hr', [$this->asset->id]));
    $this->employee->update(['status' => 'terminated']);

    advancesRM($this->employee->fresh())->assertTableActionHidden('grant_advance');
});

it('reverses a mis-keyed repayment via the relation manager (F-91)', function () {
    $this->actingAs(makeUser('hr', [$this->asset->id]));
    $advance = $this->employee->advances()->create([
        'asset_id' => $this->asset->id, 'amount' => 10000, 'advance_date' => now()->toDateString(), 'paid_from' => 'cash',
    ]);
    $repayment = $advance->repayments()->create([
        'asset_id' => $this->asset->id, 'amount' => 5000, 'repaid_on' => now()->toDateString(), 'method' => 'cash',
    ]);
    expect($advance->fresh()->outstanding())->toBe(5000.0);

    advancesRM($this->employee)
        ->callTableAction('reverse_repayment', $advance, data: [
            'repayment_id' => $repayment->id, 'reason' => 'Typo — should have been 500',
        ])
        ->assertHasNoTableActionErrors();

    expect($advance->fresh()->outstanding())->toBe(10000.0)
        ->and(EmployeeAdvanceRepayment::withTrashed()->find($repayment->id)->trashed())->toBeTrue();
});

it('hides the reverse action when the advance has no repayments', function () {
    $this->actingAs(makeUser('hr', [$this->asset->id]));
    $advance = $this->employee->advances()->create([
        'asset_id' => $this->asset->id, 'amount' => 3000, 'advance_date' => now()->toDateString(), 'paid_from' => 'cash',
    ]);

    advancesRM($this->employee)->assertTableActionHidden('reverse_repayment', $advance);
});

it('hides the reverse action from a role without employees.record_repayment', function () {
    // viewer has employees.view but not record_repayment.
    $this->actingAs(makeUser('viewer', [$this->asset->id]));
    $advance = $this->employee->advances()->create([
        'asset_id' => $this->asset->id, 'amount' => 3000, 'advance_date' => now()->toDateString(), 'paid_from' => 'cash',
    ]);
    $advance->repayments()->create([
        'asset_id' => $this->asset->id, 'amount' => 1000, 'repaid_on' => now()->toDateString(), 'method' => 'cash',
    ]);

    advancesRM($this->employee)->assertTableActionHidden('reverse_repayment', $advance);
});
