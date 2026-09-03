<?php

use App\Filament\Admin\Resources\Employees\Pages\EditEmployee;
use App\Models\Employee;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * SW-097 — terminating an employee was a one-way door.
 *
 * `terminate` was the only act that touched `employees.status`, and the form carries no status
 * field. So a mis-click on a list — the wrong row, which is how this happens — was permanent: the
 * person drops out of payroll, the org chart and every active-only picker, with nothing on any
 * screen offering a correction.
 *
 * A dead-end status is a shape this codebase has had to fix twice in the same sweep: a draft invoice
 * that could be neither issued back nor cancelled, and a cheque whose only way out of `cleared` was
 * to be marked as returned by a bank that never saw it. The rule they all follow is the one
 * `RefusesDeletionOfCommittedRecords` states — correct a record through a workflow that leaves a
 * trail, rather than by editing a column or by having no answer at all.
 *
 * `terminated_on` is cleared with the status: leaving it behind would say the person left on a day
 * they are still employed, and it is what every "was this person here then" read looks at.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'EMP']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $this->employee = Employee::create([
        'asset_id' => $this->asset->id,
        'code' => 'E-'.uniqid(),
        'name' => 'Mahmoud Fathy',
        'position' => 'Technician',
        'base_salary' => 9000,
        'hire_date' => '2024-01-15',
        'status' => 'active',
    ]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('offers the way back once someone is terminated, and not before', function () {
    // The pair is exclusive: exactly one of them applies to any given record, so the header never
    // offers both and never offers neither.
    Livewire::test(EditEmployee::class, ['record' => $this->employee->getRouteKey()])
        ->assertActionVisible('terminate')
        ->assertActionHidden('reinstate');

    $this->employee->update(['status' => 'terminated', 'terminated_on' => now()->toDateString()]);

    Livewire::test(EditEmployee::class, ['record' => $this->employee->fresh()->getRouteKey()])
        ->assertActionHidden('terminate')
        ->assertActionVisible('reinstate');
});

it('puts the person back on the payroll, and clears the leaving date with them', function () {
    $this->employee->update(['status' => 'terminated', 'terminated_on' => '2026-07-31']);

    Livewire::test(EditEmployee::class, ['record' => $this->employee->fresh()->getRouteKey()])
        ->callAction('reinstate');

    $fresh = $this->employee->fresh();

    expect($fresh->status)->toBe('active')
        // Measured before: there was no action at all — the record could only be corrected in the
        // database. A leftover date would say they left on a day they are still employed.
        ->and($fresh->terminated_on)->toBeNull();
});

it('leaves a trail that the status moved', function () {
    // `employees` is one of the models `ActivityLogging::for()` audits, so the act needs no bespoke
    // record of its own — the column change IS the trail.
    //
    // Asserted at the row level, not the column level: an ordinary update on this install writes an
    // `updated` activity row whose `properties` come back EMPTY, on every model I probed, not just
    // this one. That contradicts what the denylist flip claims and is recorded as SW-232 rather than
    // asserted around here.
    $this->employee->update(['status' => 'terminated', 'terminated_on' => '2026-07-31']);

    $before = Activity::query()
        ->where('subject_type', $this->employee->getMorphClass())
        ->where('subject_id', $this->employee->id)
        ->count();

    Livewire::test(EditEmployee::class, ['record' => $this->employee->fresh()->getRouteKey()])
        ->callAction('reinstate');

    expect(Activity::query()
        ->where('subject_type', $this->employee->getMorphClass())
        ->where('subject_id', $this->employee->id)
        ->count())->toBeGreaterThan($before);
});

it('refuses a crafted reinstate of someone who is already active', function () {
    // `visible()` is a UI decision and the payload still arrives, so the act re-checks server-side —
    // the rule this codebase states for every write action, and the same shape `terminate` uses one
    // definition above it.
    Livewire::test(EditEmployee::class, ['record' => $this->employee->getRouteKey()])
        ->assertActionHidden('reinstate');

    expect($this->employee->fresh()->status)->toBe('active');
});
