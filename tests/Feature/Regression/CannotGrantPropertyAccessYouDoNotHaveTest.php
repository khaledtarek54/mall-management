<?php

use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\Asset;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * **You cannot grant property access you do not have yourself.**
 *
 * THE HOLE. `users.create` and `users.edit` are held by THREE roles besides super_admin — `manager`,
 * `hr` and `mall_admin` — and the user form's "Assigned properties" field is what grants access to a
 * mall. The field spans properties on purpose (it is about the portfolio, not about one record's
 * own property — `EntitySelect::acrossProperties()`), and nothing checked the GRANTOR.
 *
 * So a manager restricted to Mall A could open their own user record, add Mall B, and see Mall B —
 * privilege escalation through an ordinary CRUD screen, with no gate between them and it. Or create
 * a new account holding malls they cannot see and log in as it.
 *
 * "You cannot grant what you do not hold" is a principle rather than a preference, which is why this
 * is a fix and not a configuration question. The role catalogue itself — whether `hr` should hold
 * `users.edit` at all — remains the operator's call and is untouched here.
 *
 * WHERE THE GUARD LIVES. On the create/edit pages, not on the picker. The picker must keep offering
 * every property or the form's own "all properties by default" state would fail its own validation,
 * and a disabled/narrowed option list is not a gate anyway: the assignment arrives as a Livewire
 * payload and a crafted request never opens the dropdown.
 *
 * `AssignedAssets::idsForCurrentUser()`, NOT `TenantScope::visibleAssetIds()`. The latter collapses
 * to the SELECTED property, so an hr user assigned to two malls and currently working in one would
 * be unable to grant the other — which is legitimate and would read as a bug.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->mine = makeAsset(['code' => 'MINE']);
    $this->theirs = makeAsset(['code' => 'THEIRS']);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('refuses to grant a property the granting user cannot see', function () {
    $hr = makeUser('hr', [$this->mine->id]);
    $this->actingAs($hr);
    Filament::setTenant($this->mine);

    $target = makeUser('viewer', [$this->mine->id]);

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        // The tamper: a mall this hr user holds no access to.
        ->fillForm(['assignedAssets' => [$this->mine->id, $this->theirs->id]])
        ->call('save');

    expect($target->fresh()->assignedAssets->pluck('id')->all())
        ->not->toContain($this->theirs->id);
});

it('refuses the same escalation on create', function () {
    $hr = makeUser('hr', [$this->mine->id]);
    $this->actingAs($hr);
    Filament::setTenant($this->mine);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Minted With Extra Access',
            'email' => 'minted'.uniqid().'@test.local',
            'password' => 'secret-enough',
            'roles' => [Role::where('name', 'viewer')->value('id')],
            'assignedAssets' => [$this->mine->id, $this->theirs->id],
        ])
        ->call('create');

    $created = User::query()->where('name', 'Minted With Extra Access')->first();

    // Asserted unconditionally, and the creation itself is the control: wrapping the check in
    // `if ($created)` would let a form that refused the save outright pass this test while proving
    // nothing about the guard.
    expect($created)->not->toBeNull()
        ->and($created->assignedAssets->pluck('id')->all())
        ->toContain($this->mine->id)          // what they DO hold was granted…
        ->not->toContain($this->theirs->id);  // …and what they do not was stripped
});

it('still lets a restricted grantor assign every property they DO hold', function () {
    // The control. A guard that refused everything would satisfy both refusals above and read as
    // airtight — and it would also make the screen useless to the roles that own it.
    $hr = makeUser('hr', [$this->mine->id, $this->theirs->id]);
    $this->actingAs($hr);
    // Working IN one property while holding two: the guard must measure what they HOLD, not the
    // mall they happen to be looking at, or this assignment would be refused.
    Filament::setTenant($this->mine);

    $target = makeUser('viewer', []);

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm(['assignedAssets' => [$this->mine->id, $this->theirs->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->assignedAssets->pluck('id')->all())
        ->toContain($this->mine->id)
        ->toContain($this->theirs->id);
});

it('leaves super_admin unrestricted', function () {
    // `AssignedAssets::idsForCurrentUser()` returns null for super_admin, so the guard is a no-op —
    // which is what keeps the FIRST assignment on a new deployment possible.
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant($this->mine);

    $target = makeUser('viewer', []);

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm(['assignedAssets' => [$this->mine->id, $this->theirs->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->assignedAssets->pluck('id')->all())
        ->toContain($this->theirs->id);
});

it('offers a restricted grantor only their own properties as the create default', function () {
    // The default was "every real property", which for a restricted grantor was a form that
    // proposed exactly the assignment the guard now refuses.
    $hr = makeUser('hr', [$this->mine->id]);
    $this->actingAs($hr);
    Filament::setTenant($this->mine);

    $default = Livewire::test(CreateUser::class)->get('data')['assignedAssets'] ?? [];

    expect(array_map('intval', $default))
        ->toContain($this->mine->id)
        ->not->toContain($this->theirs->id)
        ->and(array_map('intval', $default))->not->toContain(Asset::where('code', Asset::ALL_PROPERTIES_CODE)->value('id'));
});
