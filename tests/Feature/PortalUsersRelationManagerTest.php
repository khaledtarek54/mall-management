<?php

use App\Filament\Admin\RelationManagers\PortalUsersRelationManager;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Models\TenantUser;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

it('creates a portal user under a tenant from the relation manager (password hashed once)', function () {
    $this->actingAs(makeUser('super_admin'));
    $tenant = makeTenant();

    Livewire::test(PortalUsersRelationManager::class, [
        'ownerRecord' => $tenant,
        'pageClass' => EditTenant::class,
    ])
        ->callTableAction('create', data: [
            'name' => 'Jane Doe',
            'email' => 'jane@tenant.test',
            'password' => 'secret123',
            'is_admin' => true,
        ])
        ->assertHasNoTableActionErrors();

    $user = TenantUser::where('email', 'jane@tenant.test')->first();

    expect($user)->not->toBeNull()
        ->and($user->tenant_id)->toBe($tenant->id)
        ->and($user->is_admin)->toBeTrue()
        ->and(Hash::check('secret123', $user->password))->toBeTrue();   // hashed exactly once
});

it('only lists the owning tenant\'s portal users', function () {
    $this->actingAs(makeUser('super_admin'));
    $a = makeTenant();
    $b = makeTenant();
    $mine = makeTenantUser($a);
    $theirs = makeTenantUser($b);

    Livewire::test(PortalUsersRelationManager::class, [
        'ownerRecord' => $a,
        'pageClass' => EditTenant::class,
    ])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});
