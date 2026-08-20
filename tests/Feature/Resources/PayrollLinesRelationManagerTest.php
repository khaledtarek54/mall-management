<?php

use App\Filament\Admin\RelationManagers\PayrollLinesRelationManager;
use App\Filament\Admin\Resources\Payrolls\Pages\EditPayroll;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Support\Filament\RecordChanged;
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
    } catch (Throwable $e) {
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

/* ---- Generate from roster ------------------------------------------------ */

it('generates payslips from the active roster and derives the header', function () {
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    // A second active employee on the same property (beforeEach seeds one at 8000).
    Employee::create([
        'asset_id' => $this->asset->id, 'code' => 'E-2', 'name' => 'Sara Nabil',
        'hire_date' => '2026-01-01', 'base_salary' => 5000, 'payment_method' => 'bank',
    ]);

    linesRM($this->run)
        ->callTableAction('generate_from_roster')
        ->assertHasNoTableActionErrors()
        // Tells the parent Edit form to re-pull the derived totals (live, no refresh).
        ->assertDispatched(RecordChanged::EVENT);

    expect(PayrollLine::where('payroll_id', $this->run->id)->count())->toBe(2);
    // Header derives from Σ lines (default rates 0 → net = gross).
    expect((float) $this->run->fresh()->gross_salaries)->toBe(13000.0);
    expect((float) $this->run->fresh()->net_paid)->toBe(13000.0);
});

it('hides the generate action once the run leaves draft', function () {
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    $this->run->update(['status' => 'approved']);

    linesRM($this->run->fresh())->assertTableActionHidden('generate_from_roster');
});

it('hides the generate action from a role without payrolls.edit', function () {
    $this->actingAs(makeUser('viewer', [$this->asset->id]));

    linesRM($this->run)->assertTableActionHidden('generate_from_roster');
});

/* ---- Advance installment (Phase 4b) -------------------------------------- */

it('applies an advance installment to a line, reducing net', function () {
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    $advance = EmployeeAdvance::create(['employee_id' => $this->employee->id, 'asset_id' => $this->asset->id,
        'type' => 'loan', 'amount' => 5000, 'advance_date' => '2026-01-01', 'paid_from' => 'cash']);
    $line = $this->run->lines()->create(['employee_id' => $this->employee->id, 'gross' => 9000, 'salary_tax' => 800, 'social_insurance' => 600]);

    linesRM($this->run)
        ->callTableAction('deduct_advance', $line, data: ['employee_advance_id' => $advance->id, 'advance_deduction' => 1000])
        ->assertHasNoTableActionErrors();

    $line->refresh();
    expect((float) $line->advance_deduction)->toBe(1000.0);
    expect($line->employee_advance_id)->toBe($advance->id);
    expect((float) $line->net)->toBe(6600.0);                       // 9000 − 800 − 600 − 1000
    expect((float) $this->run->fresh()->advance_deductions)->toBe(1000.0);
});

it('refuses an installment that exceeds the advance outstanding', function () {
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    $advance = EmployeeAdvance::create(['employee_id' => $this->employee->id, 'asset_id' => $this->asset->id,
        'type' => 'loan', 'amount' => 500, 'advance_date' => '2026-01-01', 'paid_from' => 'cash']);
    $line = $this->run->lines()->create(['employee_id' => $this->employee->id, 'gross' => 9000, 'salary_tax' => 0, 'social_insurance' => 0]);

    linesRM($this->run)->callTableAction('deduct_advance', $line, data: ['employee_advance_id' => $advance->id, 'advance_deduction' => 1000]);

    // Nothing applied — the guard notified instead.
    expect((float) $line->fresh()->advance_deduction)->toBe(0.0);
});

it('hides the deduct-advance action once the run leaves draft', function () {
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    EmployeeAdvance::create(['employee_id' => $this->employee->id, 'asset_id' => $this->asset->id,
        'type' => 'loan', 'amount' => 5000, 'advance_date' => '2026-01-01', 'paid_from' => 'cash']);
    $line = $this->run->lines()->create(['employee_id' => $this->employee->id, 'gross' => 9000]);
    $this->run->update(['status' => 'approved']);

    linesRM($this->run->fresh())->assertTableActionHidden('deduct_advance', $line);
});

// Regression: net-guard must account for the FULL deduction set (advance + other), not a subset —
// else a reachable input slips past the inline rule and hits the model throw uncaught (a 500).
it('refuses to edit gross below a retained advance installment (net guard)', function () {
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    $advance = EmployeeAdvance::create(['employee_id' => $this->employee->id, 'asset_id' => $this->asset->id,
        'type' => 'loan', 'amount' => 5000, 'advance_date' => '2026-01-01', 'paid_from' => 'cash']);
    $line = $this->run->lines()->create(['employee_id' => $this->employee->id, 'gross' => 10000,
        'employee_advance_id' => $advance->id, 'advance_deduction' => 3000]);

    linesRM($this->run)
        ->callTableAction('edit', $line, data: ['gross' => 2000, 'allowances' => 0, 'salary_tax' => 0,
            'social_insurance' => 0, 'other_deductions' => 0, 'employer_social_insurance' => 0])
        ->assertHasTableActionErrors();

    expect((float) $line->fresh()->gross)->toBe(10000.0);   // unchanged — the edit was refused
});

it('refuses an advance installment that the line’s other deductions already consume', function () {
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    $advance = EmployeeAdvance::create(['employee_id' => $this->employee->id, 'asset_id' => $this->asset->id,
        'type' => 'loan', 'amount' => 5000, 'advance_date' => '2026-01-01', 'paid_from' => 'cash']);
    // take-home = 10000 − 9500 = 500, so a 1000 installment would drive net negative.
    $line = $this->run->lines()->create(['employee_id' => $this->employee->id, 'gross' => 10000, 'other_deductions' => 9500]);

    linesRM($this->run)->callTableAction('deduct_advance', $line, data: ['employee_advance_id' => $advance->id, 'advance_deduction' => 1000]);

    expect((float) $line->fresh()->advance_deduction)->toBe(0.0);   // refused, no 500
});
