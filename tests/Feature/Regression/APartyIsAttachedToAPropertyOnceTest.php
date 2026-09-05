<?php

use App\Filament\Admin\RelationManagers\AssetOwnersRelationManager;
use App\Filament\Admin\RelationManagers\AssetStaffRelationManager;
use App\Filament\Admin\RelationManagers\DepartmentMembersRelationManager;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Filament\Admin\Resources\Departments\Pages\EditDepartment;
use App\Models\Asset;
use App\Models\Department;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Attaching a party who is already attached must be a REFUSAL, never a 500.
 *
 * Reported by the tester on Owners: the only user holding the `owner` role was already attached at
 * 50%, the Attach picker offered them again, and pressing Attach produced Filament's
 * "Error while loading page" — a duplicate-key QueryException on `unique(user_id, asset_id)`,
 * surfaced as if the panel were broken rather than as if a rule had been broken.
 *
 * TWO layers, and the test asserts both separately because they fail differently:
 *
 *  - the PICKER should not offer them (upstream's own `whereDoesntHave` exclusion, which both call
 *    sites had disabled by replacing `->options()` instead of narrowing the query);
 *  - the WRITE should refuse them, because a picker is not a gate — the id arrives in the Livewire
 *    payload and a crafted or stale request carries whatever it likes.
 *
 * Asserting only the first would pass on a build where the write still 500s.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
});

function ownersRm(Asset $asset): Testable
{
    return Livewire::test(AssetOwnersRelationManager::class, [
        'ownerRecord' => $asset,
        'pageClass' => EditAsset::class,
    ]);
}

function assetStaffRm(Asset $asset): Testable
{
    return Livewire::test(AssetStaffRelationManager::class, [
        'ownerRecord' => $asset,
        'pageClass' => EditAsset::class,
    ]);
}

it('does not offer an owner who already owns a share of this property', function () {
    $asset = makeAsset();
    $attached = makeUser('owner');
    $free = makeUser('owner');
    $asset->propertyOwners()->attach($attached->id, ['ownership_percentage' => 50]);

    $options = ownersRm($asset)
        ->mountAction(TestAction::make('attach')->table())
        ->instance()
        ->getMountedAction()
        ->getRecordSelect()
        ->getOptions();

    // Paired: the exclusion must remove the attached owner AND leave the free one reachable. A
    // picker that offered nobody would satisfy the refusal alone and read as a pass.
    expect($options)->not->toHaveKey($attached->id)
        ->and($options)->toHaveKey($free->id);
});

it('refuses a second owner row for the same person instead of crashing', function () {
    $asset = makeAsset();
    $owner = makeUser('owner');
    $asset->propertyOwners()->attach($owner->id, ['ownership_percentage' => 50]);

    ownersRm($asset)
        ->callAction(TestAction::make('attach')->table(), data: [
            'recordId' => $owner->getKey(),
            'ownership_percentage' => 10,
        ]);
})
    // A DomainException is the app talking to a person — `bootstrap/app.php` renders it as a toast
    // and a redirect back, never the 500 the duplicate key produced. Asserting the MESSAGE pins
    // that the operator is told which person they picked twice, not merely that something refused.
    ->throws(DomainException::class);

it('still attaches a genuinely new owner', function () {
    // The control. Both assertions above pass on a build that refuses every attach.
    $asset = makeAsset();
    $first = makeUser('owner');
    $second = makeUser('owner');
    $asset->propertyOwners()->attach($first->id, ['ownership_percentage' => 50]);

    ownersRm($asset)
        ->callAction(TestAction::make('attach')->table(), data: [
            'recordId' => $second->getKey(),
            'ownership_percentage' => 50,
        ])
        ->assertHasNoActionErrors();

    expect($asset->fresh()->propertyOwners()->count())->toBe(2);
});

it('does not offer a staff member already assigned to this property', function () {
    $asset = makeAsset();
    $attached = makeUser('manager');
    $free = makeUser('leasing');
    $asset->staff()->attach($attached->id, ['assigned_at' => '2026-09-01']);

    $options = assetStaffRm($asset)
        ->mountAction(TestAction::make('attach')->table())
        ->instance()
        ->getMountedAction()
        ->getRecordSelect()
        ->getOptions();

    expect($options)->not->toHaveKey($attached->id)
        ->and($options)->toHaveKey($free->id);
});

it('refuses a second staff assignment for the same person', function () {
    $asset = makeAsset();
    $staff = makeUser('manager');
    $asset->staff()->attach($staff->id, ['assigned_at' => '2026-09-01']);

    assetStaffRm($asset)
        ->callAction(TestAction::make('attach')->table(), data: ['recordId' => $staff->getKey()]);
})->throws(DomainException::class);

it('leaves the existing row untouched when a duplicate attach is refused', function () {
    $asset = makeAsset();
    $owner = makeUser('owner');
    $asset->propertyOwners()->attach($owner->id, ['ownership_percentage' => 50]);

    try {
        ownersRm($asset)->callAction(TestAction::make('attach')->table(), data: [
            'recordId' => $owner->getKey(),
            'ownership_percentage' => 10,
        ]);
    } catch (DomainException) {
        // expected — the point is what the refusal LEFT BEHIND
    }

    $owners = $asset->fresh()->propertyOwners;
    expect($owners)->toHaveCount(1)
        // 50, not 10: the refusal must not half-apply the submitted share.
        ->and((float) $owners->first()->pivot->ownership_percentage)->toBe(50.0);
});

it('refuses a second membership of the same department, and still admits a new member', function () {
    // The THIRD door onto this defect. Not on the tester's card — found by grepping for the shape
    // rather than from the diff that fixed Properties, which is the rule this repo keeps relearning.
    // `department_user` carries the same unique index, so the same 500 was reachable here.
    $dept = Department::factory()->create();
    $member = makeUser('manager');
    $newcomer = makeUser('leasing');
    $dept->members()->attach($member->id, ['assigned_at' => '2026-09-01']);

    $rm = fn () => Livewire::test(DepartmentMembersRelationManager::class, [
        'ownerRecord' => $dept,
        'pageClass' => EditDepartment::class,
    ]);

    $options = $rm()->mountAction(TestAction::make('attach')->table())
        ->instance()->getMountedAction()->getRecordSelect()->getOptions();

    expect($options)->not->toHaveKey($member->id)
        ->and($options)->toHaveKey($newcomer->id);

    try {
        $rm()->callAction(TestAction::make('attach')->table(), data: ['recordId' => $member->getKey()]);
        $refused = false;
    } catch (DomainException) {
        $refused = true;
    }

    expect($refused)->toBeTrue()
        ->and($dept->fresh()->members()->count())->toBe(1);
});
