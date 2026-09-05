<?php

use App\Filament\Admin\RelationManagers\AssetOwnersRelationManager;
use App\Filament\Admin\RelationManagers\AssetStaffRelationManager;
use App\Filament\Admin\RelationManagers\DepartmentMembersRelationManager;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Filament\Admin\Resources\BankStatements\Pages\CreateBankStatement;
use App\Filament\Admin\Resources\Departments\Pages\EditDepartment;
use App\Models\Department;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * A tenure may END on the day it BEGAN. A one-day assignment is an ordinary thing.
 *
 * Reported by the tester on Assigned Staff: Assigned 30 Sep, Ended 30 Sep, refused with *"The ended
 * field must be a date after or equal to assigned"* — a message stating the rule it was breaking.
 *
 * **The refusal could not be reproduced on SQLite at HEAD** and that is stated rather than papered
 * over: driving the real relation manager and dumping Filament's generated rule showed
 * `after_or_equal:` resolving correctly to the sibling path, with equal dates passing. What is
 * certain is the SHAPE — `after_or_equal` compares INSTANTS, so a time component on either side
 * (a `->default(now())` never floored, a driver returning `2026-09-30 00:00:00` where another
 * returns `2026-09-30`) makes two dates on the same DAY different instants: "equal" fails while
 * "after" still works, which is exactly the asymmetry reported. This codebase already recorded that
 * hazard once, in `coversWholeMonth()`, noting the bug was *intermittent by caller*.
 *
 * So every one of the ten start/end pairs in the panel is pinned to midnight through
 * `TenureRange::endsOnOrAfter()`, via `minDate` — which greys the impossible days out of the
 * calendar as well as refusing them.
 *
 * **Both directions are asserted, and that is the point.** `minDate` resolves to a VALUE, so a
 * context where the sibling path did not resolve would yield null and silently remove the
 * constraint altogether — a fix that quietly deletes the guard it was meant to correct. Every case
 * here pairs "equal is accepted" with "earlier is still refused".
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset();
});

it('lets a staff assignment end on the day it began, and still refuses an earlier end', function () {
    $staff = makeUser('manager');
    $this->asset->staff()->attach($staff->id, ['assigned_at' => '2026-09-30']);

    $rm = fn () => Livewire::test(AssetStaffRelationManager::class, [
        'ownerRecord' => $this->asset,
        'pageClass' => EditAsset::class,
    ]);

    $rm()->callAction(TestAction::make('edit')->table($staff->getKey()), data: [
        'assigned_at' => '2026-09-30',
        'ended_at' => '2026-09-30',
    ])->assertHasNoActionErrors();

    expect((string) $this->asset->fresh()->staff()->first()->pivot->ended_at)->toContain('2026-09-30');

    $rm()->callAction(TestAction::make('edit')->table($staff->getKey()), data: [
        'assigned_at' => '2026-09-30',
        'ended_at' => '2026-09-29',
    ])->assertHasActionErrors(['ended_at']);
});

it('lets an ownership end on the day it began, and still refuses an earlier end', function () {
    $owner = makeUser('owner');
    $this->asset->propertyOwners()->attach($owner->id, [
        'ownership_percentage' => 100,
        'started_at' => '2026-09-30',
    ]);

    $rm = fn () => Livewire::test(AssetOwnersRelationManager::class, [
        'ownerRecord' => $this->asset,
        'pageClass' => EditAsset::class,
    ]);

    $rm()->callAction(TestAction::make('edit')->table($owner->getKey()), data: [
        'ownership_percentage' => 100,
        'started_at' => '2026-09-30',
        'ended_at' => '2026-09-30',
    ])->assertHasNoActionErrors();

    $rm()->callAction(TestAction::make('edit')->table($owner->getKey()), data: [
        'ownership_percentage' => 100,
        'started_at' => '2026-09-30',
        'ended_at' => '2026-09-29',
    ])->assertHasActionErrors(['ended_at']);
});

it('lets a department membership end on the day it began', function () {
    $dept = Department::factory()->create();
    $member = makeUser('manager');
    $dept->members()->attach($member->id, ['assigned_at' => '2026-09-30']);

    $rm = fn () => Livewire::test(DepartmentMembersRelationManager::class, [
        'ownerRecord' => $dept,
        'pageClass' => EditDepartment::class,
    ]);

    $rm()->callAction(TestAction::make('edit')->table($member->getKey()), data: [
        'assigned_at' => '2026-09-30',
        'ended_at' => '2026-09-30',
    ])->assertHasNoActionErrors();

    $rm()->callAction(TestAction::make('edit')->table($member->getKey()), data: [
        'assigned_at' => '2026-09-30',
        'ended_at' => '2026-09-29',
    ])->assertHasActionErrors(['ended_at']);
});

it('keeps the guard on an ordinary resource form too', function () {
    // A structurally DIFFERENT context from the three relation-manager modals above: a plain
    // resource create page. If `Get` resolved differently here, minDate would come back null and
    // the constraint would vanish rather than fail — which is why the refusal half is asserted.
    asTenant($this->asset, function () {
        Livewire::test(CreateBankStatement::class)
            ->fillForm(['period_start' => '2026-09-30', 'period_end' => '2026-09-29'])
            ->call('create')
            ->assertHasFormErrors(['period_end']);

        Livewire::test(CreateBankStatement::class)
            ->fillForm(['period_start' => '2026-09-30', 'period_end' => '2026-09-30'])
            ->call('create')
            ->assertHasNoFormErrors(['period_end']);
    });
});

it('imposes no floor while the start date is still empty', function () {
    // A half-typed form must not be refused on a rule about a field nobody has filled in yet, and a
    // throw inside the resolver would take the whole form down mid-keystroke. The end date carries
    // no error here — whatever the form says about the MISSING start is that field's business.
    asTenant($this->asset, function () {
        Livewire::test(CreateBankStatement::class)
            ->fillForm(['period_start' => null, 'period_end' => '2026-09-29'])
            ->call('create')
            ->assertHasNoFormErrors(['period_end']);
    });
});
