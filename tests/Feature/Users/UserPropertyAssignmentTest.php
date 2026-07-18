<?php

use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->hw = makeAsset(['code' => 'HW']);
    $this->pa = makeAsset(['code' => 'PA']);
    $this->bw = makeAsset(['code' => 'BW']);

    $this->actingAs(makeUser('super_admin'));
    // UserResource is not tenant-scoped, but Filament still resolves the
    // panel's tenant from the URL — set one explicitly so getUrl() works.
    \Filament\Facades\Filament::setTenant($this->hw);
});

it('the Create User form pre-fills every real property in assignedAssets', function () {
    Livewire::test(CreateUser::class)
        ->assertFormSet([
            'assignedAssets' => [$this->hw->id, $this->pa->id, $this->bw->id],
        ]);
});

it('the Create User form excludes the synthetic ALL pseudo-asset from the pre-fill', function () {
    $all = \App\Models\Asset::where('code', \App\Models\Asset::ALL_PROPERTIES_CODE)->first();

    $state = Livewire::test(CreateUser::class)->get('data.assignedAssets');

    expect($state)->not->toContain($all->id);
});

it('saving the create form attaches every selected property to the new user', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'New Manager',
            'email' => 'new-mgr-' . uniqid() . '@test.local',
            'password' => 'password',
            'roles' => [\Spatie\Permission\Models\Role::findByName('manager', 'web')->id],
            // assignedAssets is pre-filled with all three; submit as-is
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::orderByDesc('id')->first();
    expect($created->assignedAssets()->pluck('assets.id')->all())
        ->toEqualCanonicalizing([$this->hw->id, $this->pa->id, $this->bw->id]);
});

it('deselecting a property in the form restricts the user to only the chosen ones', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'HW-only User',
            'email' => 'hwonly-' . uniqid() . '@test.local',
            'password' => 'password',
            'roles' => [\Spatie\Permission\Models\Role::findByName('manager', 'web')->id],
            'assignedAssets' => [$this->hw->id], // only HW
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'like', 'hwonly-%')->first();
    expect($user->assignedAssets()->pluck('assets.id')->all())->toEqual([$this->hw->id]);
});

it('editing a user shows their currently-assigned properties, not a fresh default', function () {
    $user = makeUser('manager', [$this->hw->id, $this->pa->id]);

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->assertFormSet([
            'assignedAssets' => [$this->hw->id, $this->pa->id],
        ]);
});

it('a user with property restrictions only sees those in getTenants()', function () {
    $user = makeUser('manager', [$this->hw->id]);
    $tenants = $user->getTenants(filament()->getPanel('admin'))->pluck('code')->all();
    expect($tenants)->toEqual(['HW']);
});

it('a user with every property assigned sees only the real malls — never the ALL pseudo-tenant', function () {
    $user = makeUser('manager', [$this->hw->id, $this->pa->id, $this->bw->id]);
    $tenants = $user->getTenants(filament()->getPanel('admin'))->pluck('code')->all();

    // Property-first UX: the switcher never offers "All Properties".
    expect($tenants)->toEqualCanonicalizing(['HW', 'PA', 'BW'])
        ->not->toContain('ALL');
});
