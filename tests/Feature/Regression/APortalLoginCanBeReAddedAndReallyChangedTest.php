<?php

use App\Filament\Admin\RelationManagers\PortalUsersRelationManager;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Models\Tenant;
use App\Models\TenantUser;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Two cards from the tester's board, both on Tenant → Portal & App Logins.
 *
 * **"A deleted user's email is still blocked as already taken."** `TenantUser` soft-deletes so the
 * history a portal user left behind goes on resolving, and Laravel's `unique` rule counts a trashed
 * row — so removing somebody and re-adding them answered *"The email has already been taken"* about
 * an account nobody could see, with no way forward at all.
 *
 * **"The password can be changed to the current password and still saves."** It reported success, so
 * an operator resetting a compromised login could believe they had rotated it and leave the old one
 * live. NIST 800-63B and OWASP both require a new secret to differ from the one it replaces.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->tenant = makeTenant();
});

function portalUsers(Tenant $tenant): Testable
{
    return Livewire::test(PortalUsersRelationManager::class, [
        'ownerRecord' => $tenant,
        'pageClass' => EditTenant::class,
    ]);
}

it('lets a removed login be added again with the same address', function () {
    $login = TenantUser::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Mona Adel', 'email' => 'mona@example.test',
        'password' => Hash::make('first-password'), 'is_admin' => false,
    ]);
    $original = $login->id;

    $login->delete();

    portalUsers($this->tenant)
        ->callAction(TestAction::make('create')->table(), data: [
            'name' => 'Mona Adel',
            'email' => 'mona@example.test',
            'password' => 'a-brand-new-password',
        ])
        ->assertHasNoActionErrors();

    $reclaimed = TenantUser::where('email', 'mona@example.test')->sole();

    // RECLAIMED, not duplicated — the person's id, and with it their activity trail and their portal
    // history, reconnects instead of being silently orphaned beside a fresh row.
    expect($reclaimed->id)->toBe($original)
        ->and($reclaimed->trashed())->toBeFalse()
        ->and($reclaimed->tenant_id)->toBe($this->tenant->id)
        ->and(Hash::check('a-brand-new-password', $reclaimed->password))->toBeTrue();
});

it('still refuses an address that a LIVE login already holds', function () {
    // The control. Freeing a removed address must not free one that is in use.
    TenantUser::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Mona Adel', 'email' => 'mona@example.test',
        'password' => Hash::make('first-password'), 'is_admin' => false,
    ]);

    portalUsers($this->tenant)
        ->callAction(TestAction::make('create')->table(), data: [
            'name' => 'Someone Else',
            'email' => 'mona@example.test',
            'password' => 'another-password',
        ])
        ->assertHasActionErrors(['email']);

    expect(TenantUser::where('email', 'mona@example.test')->count())->toBe(1);
});

it('does not move another tenant s removed login into this one', function () {
    // An address is globally unique because one person has ONE login across the portal and the
    // mobile app, so a trashed row belonging to somebody else stays refused rather than being
    // quietly re-homed — which would hand this tenant another company's account history.
    $other = makeTenant();
    $theirs = TenantUser::create([
        'tenant_id' => $other->id, 'name' => 'Theirs', 'email' => 'shared@example.test',
        'password' => Hash::make('first-password'), 'is_admin' => false,
    ]);
    $theirs->delete();

    try {
        portalUsers($this->tenant)->callAction(TestAction::make('create')->table(), data: [
            'name' => 'Mine', 'email' => 'shared@example.test', 'password' => 'another-password',
        ]);
        $created = true;
    } catch (Throwable) {
        $created = false;
    }

    $row = TenantUser::withTrashed()->where('email', 'shared@example.test')->sole();

    expect($row->tenant_id)->toBe($other->id)
        ->and($row->trashed())->toBeTrue()
        ->and($created)->toBeFalse();
});

it('refuses a password change that changes nothing', function () {
    $login = TenantUser::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Mona Adel', 'email' => 'mona@example.test',
        'password' => Hash::make('the-current-password'), 'is_admin' => false,
    ]);

    portalUsers($this->tenant)
        ->callAction(TestAction::make('edit')->table($login->getKey()), data: [
            'name' => 'Mona Adel',
            'email' => 'mona@example.test',
            'password' => 'the-current-password',
        ])
        ->assertHasActionErrors(['password']);
});

it('still accepts a genuinely new password', function () {
    // The control. The refusal above passes just as happily on a form that refuses every password.
    $login = TenantUser::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Mona Adel', 'email' => 'mona@example.test',
        'password' => Hash::make('the-current-password'), 'is_admin' => false,
    ]);

    portalUsers($this->tenant)
        ->callAction(TestAction::make('edit')->table($login->getKey()), data: [
            'name' => 'Mona Adel',
            'email' => 'mona@example.test',
            'password' => 'a-genuinely-new-password',
        ])
        ->assertHasNoActionErrors();

    expect(Hash::check('a-genuinely-new-password', $login->fresh()->password))->toBeTrue();
});

it('still lets the field be left blank to keep the current password', function () {
    // The over-lock control, and the one that matters most: the field is deliberately optional on
    // edit, so a rule that refused an unchanged password must not refuse an ABSENT one — that would
    // make every other edit on this form impossible.
    $login = TenantUser::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Mona Adel', 'email' => 'mona@example.test',
        'password' => Hash::make('the-current-password'), 'is_admin' => false,
    ]);

    portalUsers($this->tenant)
        ->callAction(TestAction::make('edit')->table($login->getKey()), data: [
            'name' => 'Mona Renamed',
            'email' => 'mona@example.test',
            'password' => null,
        ])
        ->assertHasNoActionErrors();

    expect($login->fresh()->name)->toBe('Mona Renamed')
        ->and(Hash::check('the-current-password', $login->fresh()->password))->toBeTrue();
});
