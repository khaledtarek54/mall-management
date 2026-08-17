<?php

use App\Filament\Admin\RelationManagers\PortalUsersRelationManager;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\InvoiceIssuedNotification;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Feature #9 — multi-user tenant portal: USER MANAGEMENT + notifyPortal().
|--------------------------------------------------------------------------
| Complements PortalUsersRelationManagerTest (create-hashes-once, list-scope)
| and TenantUserGatingTest (resource-level canCreate gate). NET-NEW focus:
|   - PASSWORD lifecycle through the relation manager: edit WITHOUT a password
|     keeps the old hash; edit WITH a new password rotates it (and is still
|     hashed exactly once — the 'hashed' cast, never double-bcrypt).
|   - is_admin toggle EDIT flips the portal write gate (read-only <-> writer).
|   - email uniqueness rejects a duplicate on create.
|   - the relation-manager table is scoped to the owner tenant only.
|   - DeleteAction visibility: shown to super_admin, hidden for a manager.
|   - Tenant::notifyPortal() fans out to the Tenant record AND every portal
|     user; a tenant with zero portal users still notifies the Tenant (no error).
*/

beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

/** Render the relation manager as Filament would, under a given owner tenant. */
function portalUsersRm(Tenant $tenant): Testable
{
    return Livewire::test(PortalUsersRelationManager::class, [
        'ownerRecord' => $tenant,
        'pageClass' => EditTenant::class,
    ]);
}

// ============================================================
// PASSWORD LIFECYCLE — edit keeps / rotates, hashed exactly once
// ============================================================

it('editing WITHOUT a password keeps the existing hash untouched', function () {
    $this->actingAs(makeUser('super_admin'));
    $tenant = makeTenant();

    // Seed a known password through the cast so we have a real hash to compare.
    $user = TenantUser::create([
        'tenant_id' => $tenant->id,
        'name' => 'Old Name',
        'email' => 'keep@tenant.test',
        'password' => 'original-secret',
        'is_admin' => false,
    ]);
    $originalHash = $user->fresh()->password;

    portalUsersRm($tenant)
        ->callTableAction('edit', $user, data: [
            'name' => 'New Name',
            'email' => 'keep@tenant.test',
            'password' => '',           // blank → dehydrated(false) → not written
            'is_admin' => false,
        ])
        ->assertHasNoTableActionErrors();

    $user->refresh();

    expect($user->name)->toBe('New Name')                       // other fields DID save
        ->and($user->password)->toBe($originalHash)             // hash byte-for-byte unchanged
        ->and(Hash::check('original-secret', $user->password))->toBeTrue();
});

it('editing WITH a new password rotates it and hashes exactly once', function () {
    $this->actingAs(makeUser('super_admin'));
    $tenant = makeTenant();

    $user = TenantUser::create([
        'tenant_id' => $tenant->id,
        'name' => 'Rotate Me',
        'email' => 'rotate@tenant.test',
        'password' => 'first-pass',
        'is_admin' => true,
    ]);
    $originalHash = $user->fresh()->password;

    portalUsersRm($tenant)
        ->callTableAction('edit', $user, data: [
            'name' => 'Rotate Me',
            'email' => 'rotate@tenant.test',
            'password' => 'second-pass',
            'is_admin' => true,
        ])
        ->assertHasNoTableActionErrors();

    $user->refresh();

    expect($user->password)->not->toBe($originalHash)           // hash changed
        ->and(Hash::check('second-pass', $user->password))->toBeTrue()   // hashed ONCE
        ->and(Hash::check('first-pass', $user->password))->toBeFalse();  // old no longer valid
});

// ============================================================
// is_admin TOGGLE on EDIT — flips the portal write gate
// ============================================================

it('toggling is_admin on edit flips the portal write access', function () {
    $this->actingAs(makeUser('super_admin'));
    $tenant = makeTenant();

    // Start as a read-only (non-admin) portal user.
    $user = makeTenantUser($tenant, isAdmin: false);
    expect($user->isPortalAdmin())->toBeFalse();

    // Promote to admin via the relation manager.
    portalUsersRm($tenant)
        ->callTableAction('edit', $user, data: [
            'name' => $user->name,
            'email' => $user->email,
            'password' => '',
            'is_admin' => true,
        ])
        ->assertHasNoTableActionErrors();

    expect($user->refresh()->isPortalAdmin())->toBeTrue();      // now a writer

    // Demote back to read-only.
    portalUsersRm($tenant)
        ->callTableAction('edit', $user, data: [
            'name' => $user->name,
            'email' => $user->email,
            'password' => '',
            'is_admin' => false,
        ])
        ->assertHasNoTableActionErrors();

    expect($user->refresh()->isPortalAdmin())->toBeFalse();     // back to read-only
});

// ============================================================
// EMAIL UNIQUENESS — duplicate create is rejected
// ============================================================

it('rejects a duplicate email on create (uniqueness)', function () {
    $this->actingAs(makeUser('super_admin'));
    $tenant = makeTenant();

    // An existing portal login owns the address.
    $existing = makeTenantUser($tenant);

    portalUsersRm($tenant)
        ->callTableAction('create', data: [
            'name' => 'Clashing User',
            'email' => $existing->email,   // already taken
            'password' => 'whatever123',
            'is_admin' => false,
        ])
        ->assertHasTableActionErrors(['email']);

    // No second row was created for that address.
    expect(TenantUser::where('email', $existing->email)->count())->toBe(1);
});

// ============================================================
// TABLE SCOPING — only the owner tenant's users are listed
// ============================================================

it('lists only the owner tenant\'s portal users in the relation manager', function () {
    $this->actingAs(makeUser('super_admin'));
    $owner = makeTenant();
    $other = makeTenant();

    $mineA = makeTenantUser($owner, isAdmin: true);
    $mineB = makeTenantUser($owner, isAdmin: false);
    $theirs = makeTenantUser($other);

    portalUsersRm($owner)
        ->assertCanSeeTableRecords([$mineA, $mineB])
        ->assertCanNotSeeTableRecords([$theirs]);
});

// ============================================================
// DELETE VISIBILITY — super_admin sees it, manager does not
// ============================================================

it('shows the DeleteAction to a super_admin actor', function () {
    $this->actingAs(makeUser('super_admin'));
    $tenant = makeTenant();
    $user = makeTenantUser($tenant);

    portalUsersRm($tenant)
        ->assertTableActionVisible('delete', $user);
});

it('hides the DeleteAction from a non-super_admin (manager) actor', function () {
    $this->actingAs(makeUser('manager'));
    $tenant = makeTenant();
    $user = makeTenantUser($tenant);

    portalUsersRm($tenant)
        ->assertTableActionHidden('delete', $user);
});

// ============================================================
// notifyPortal() — fan-out to the Tenant AND every portal user
// ============================================================

it('notifyPortal notifies the Tenant record AND every portal user', function () {
    Notification::fake();

    $tenant = makeTenant();
    $userA = makeTenantUser($tenant, isAdmin: true);
    $userB = makeTenantUser($tenant, isAdmin: false);

    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()), $tenant));

    $tenant->notifyPortal(new InvoiceIssuedNotification($invoice));

    // The Tenant record (mobile API surface) is notified once...
    Notification::assertSentTo($tenant, InvoiceIssuedNotification::class);
    Notification::assertSentToTimes($tenant, InvoiceIssuedNotification::class, 1);

    // ...and so is each portal user (the web bell surface).
    Notification::assertSentTo($userA, InvoiceIssuedNotification::class);
    Notification::assertSentTo($userB, InvoiceIssuedNotification::class);
});

it('notifyPortal still notifies a tenant that has NO portal users (no error)', function () {
    Notification::fake();

    $tenant = makeTenant();
    expect($tenant->users()->count())->toBe(0);

    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()), $tenant));

    // Must not throw even with an empty users() relation.
    $tenant->notifyPortal(new InvoiceIssuedNotification($invoice));

    Notification::assertSentTo($tenant, InvoiceIssuedNotification::class);
    Notification::assertSentToTimes($tenant, InvoiceIssuedNotification::class, 1);
});
