<?php

/*
|--------------------------------------------------------------------------
| Cloning a role
|--------------------------------------------------------------------------
| Atriom always let an operator build a custom role, but only by ticking the right boxes out of
| ~200 across 40 collapsed sections and getting none of them wrong. The observable result is that
| nobody builds the narrow role — they hand someone `manager` and move on. Cloning turns "accounting
| without the credit-note rights" into: copy accounting, untick two boxes.
|
| The clone is a privilege event in its own right (a role that appears already holding someone
| else's whole permission set), so it goes into the same access-control trail as an edit.
*/

use App\Support\MorphMap;
use App\Filament\Admin\Resources\Roles\Pages\ListRoles;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
});

it('copies every permission from the source role', function () {
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    $source = Role::findByName('accounting', 'web');
    $expected = $source->permissions()->pluck('name')->sort()->values()->all();

    asTenant($this->asset, function () use ($expected) {
        Livewire::test(ListRoles::class)
            ->callTableAction('clone', Role::findByName('accounting', 'web'), ['name' => 'accounting_lite'])
            ->assertHasNoTableActionErrors();

        $clone = Role::findByName('accounting_lite', 'web');

        expect($clone)->not->toBeNull()
            ->and($clone->permissions()->pluck('name')->sort()->values()->all())->toBe($expected)
            // A real copy, not a rename — the source must be untouched.
            ->and(Role::findByName('accounting', 'web')->permissions()->count())->toBe(count($expected));
    });
});

it('audits the clone as a permission grant', function () {
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(ListRoles::class)
            ->callTableAction('clone', Role::findByName('leasing', 'web'), ['name' => 'leasing_junior']);

        $clone = Role::findByName('leasing_junior', 'web');

        $audited = Activity::query()
            ->where('log_name', 'access_control')
            ->where('subject_type', MorphMap::alias(Role::class))
            ->where('subject_id', $clone->id)
            ->exists();

        expect($audited)->toBeTrue(
            'A role that appears already holding a full permission set must be in the access-control trail.'
        );
    });
});

it('refuses to clone for a role that can see roles but not create them', function () {
    // `manager` holds roles.view (it can read the list) but NOT roles.create — the case that
    // matters, because a role that cannot even open the page is already stopped by the resource
    // gate and proves nothing about this action.
    //
    // Dispatched via mountTableAction, NOT callTableAction: callTableAction asserts the action is
    // visible first, so a visible()-only gate would report the hole fixed while it was still
    // exploitable over a crafted Livewire call.
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    asTenant($this->asset, function () {
        $before = Role::count();

        Livewire::test(ListRoles::class)
            ->mountTableAction('clone', Role::findByName('accounting', 'web'))
            ->setTableActionData(['name' => 'sneaky_role'])
            ->callMountedTableAction();

        expect(Role::count())->toBe($before)
            ->and(Role::where('name', 'sneaky_role')->exists())->toBeFalse();
    });
});
