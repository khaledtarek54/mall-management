<?php

use App\Filament\Admin\RelationManagers\PayrollLinesRelationManager;
use App\Filament\Admin\Resources\Payrolls\Pages\EditPayroll;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollLine;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->run = Payroll::create([
        'asset_id' => $this->asset->id, 'period_month' => now()->startOfMonth()->toDateString(),
        'gross_salaries' => 0, 'salary_tax' => 0, 'social_insurance' => 0, 'paid_from' => 'bank', 'status' => 'draft',
    ]);
    $this->employee = Employee::create([
        'asset_id' => $this->asset->id, 'code' => 'E-1', 'name' => 'Mona Adel',
        'hire_date' => '2026-01-01', 'base_salary' => 8000, 'payment_method' => 'bank',
    ]);
});

function linesRM(Payroll $run)
{
    return Livewire::test(PayrollLinesRelationManager::class, [
        'ownerRecord' => $run,
        'pageClass' => EditPayroll::class,
    ]);
}

it('adds an employee line and recomputes the header (accounting)', function () {
    $this->actingAs(makeUser('accounting', [$this->asset->id]));

    linesRM($this->run)
        ->callTableAction('add_line', data: [
            'employee_id' => $this->employee->id, 'gross' => 9000, 'salary_tax' => 800, 'social_insurance' => 600,
        ])
        ->assertHasNoTableActionErrors();

    expect(PayrollLine::where('payroll_id', $this->run->id)->count())->toBe(1);
    expect((float) $this->run->fresh()->gross_salaries)->toBe(9000.0);
    expect((float) $this->run->fresh()->net_paid)->toBe(7600.0);
});

it('freezes lines once the run leaves draft', function () {
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    $this->run->update(['status' => 'approved']);

    linesRM($this->run->fresh())->assertTableActionHidden('add_line');
});

it('rejects adding an employee from another property (tamper guard)', function () {
    $otherAsset = makeAsset(['code' => 'OTHER']);
    $foreign = Employee::create([
        'asset_id' => $otherAsset->id, 'code' => 'X-1', 'name' => 'Foreign',
        'hire_date' => '2026-01-01', 'base_salary' => 5000, 'payment_method' => 'bank',
    ]);

    $this->actingAs(makeUser('accounting', [$this->asset->id]));

    try {
        linesRM($this->run)->callTableAction('add_line', data: [
            'employee_id' => $foreign->id, 'gross' => 9000, 'salary_tax' => 0, 'social_insurance' => 0,
        ]);
    } catch (\Throwable $e) {
        // abort(403) may surface as an exception on the Livewire path.
    }

    expect(PayrollLine::where('payroll_id', $this->run->id)->count())->toBe(0);
});

it('hides the add-line action from a role without payrolls.edit', function () {
    // viewer has payrolls.view but not payrolls.edit.
    $this->actingAs(makeUser('viewer', [$this->asset->id]));

    linesRM($this->run)->assertTableActionHidden('add_line');
});

it('offers a payslip download for a line (payrolls.view)', function () {
    $this->actingAs(makeUser('viewer', [$this->asset->id]));
    $line = $this->run->lines()->create(['employee_id' => $this->employee->id, 'gross' => 9000, 'salary_tax' => 800, 'social_insurance' => 600]);

    linesRM($this->run)->assertTableActionVisible('payslip', $line);
});
