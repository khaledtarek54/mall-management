<?php

use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * **Resetting a portal ADMIN's password is account takeover, and it was open to `leasing`.**
 *
 * The portal-users relation manager gated two things on super_admin — granting the `is_admin` flag,
 * and deleting a login — and left Create and Edit to anyone holding `tenants.edit`. The edit form
 * carries a password field. So a `manager`, or a `leasing` user, could set an existing portal
 * ADMIN's password to a value they chose and sign in to `/portal` as that tenant, where an admin may
 * pay, submit sales declarations and read the entire AR. Gating the flag stopped them GRANTING it;
 * it did nothing about taking over an account that already had it.
 *
 * Impersonating a READ-ONLY portal user is a different question and is deliberately still allowed:
 * it grants nothing an admin-panel operator cannot already see, and resetting a forgotten password
 * is ordinary tenant-relations work. Every refusal below is paired with that control.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'PU']);
    $this->tenant = makeTenant(['name' => 'Portal Retail']);

    $this->portalAdmin = makeTenantUser($this->tenant, isAdmin: true);
    $this->readOnly = makeTenantUser($this->tenant, isAdmin: false);
});

it('refuses a manager the edit button on a portal ADMIN', function () {
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    $before = $this->portalAdmin->password;

    asTenant($this->asset, function () {
        portalUsersRm($this->tenant)->assertTableActionHidden('edit', $this->portalAdmin);
    });

    expect($this->portalAdmin->fresh()->password)->toBe($before);
});

it('refuses leasing too, which is the role that made this reachable from a department', function () {
    $this->actingAs(makeUser('leasing', [$this->asset->id]));

    asTenant($this->asset, function () {
        portalUsersRm($this->tenant)->assertTableActionHidden('edit', $this->portalAdmin);
    });
});

it('still lets a manager fix a READ-ONLY portal login', function () {
    // The control. A gate that hid Edit from everyone would satisfy both refusals above and take
    // away a legitimate task — impersonating a read-only user grants nothing the operator lacks.
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    asTenant($this->asset, function () {
        portalUsersRm($this->tenant)
            ->assertTableActionVisible('edit', $this->readOnly)
            ->callTableAction('edit', $this->readOnly, data: [
                'name' => 'Corrected Name',
                'email' => $this->readOnly->email,
            ])
            ->assertHasNoTableActionErrors();
    });

    expect($this->readOnly->fresh()->name)->toBe('Corrected Name');
});

it('lets a super_admin edit the portal admin, so the refusal is about the role', function () {
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    asTenant($this->asset, function () {
        portalUsersRm($this->tenant)->assertTableActionVisible('edit', $this->portalAdmin);
    });
});

it('refuses a role that cannot edit tenants the create button', function () {
    $this->actingAs(makeUser('viewer', [$this->asset->id]));

    asTenant($this->asset, function () {
        portalUsersRm($this->tenant)->assertTableActionHidden('create');
    });

    // The control: a role that CAN edit tenants still onboards a retailer's staff, which is the
    // ordinary use of this screen.
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    asTenant($this->asset, function () {
        portalUsersRm($this->tenant)->assertTableActionVisible('create');
    });
});

it('leaves a taken-over password unusable even if the action is dispatched anyway', function () {
    // The hard layer. `visible()` is not a gate; the `->authorize()` beside it is, and the seam ANDs
    // rather than replaces it.
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    $original = $this->portalAdmin->password;

    asTenant($this->asset, function () {
        // The TABLE's own configured action, not a freshly built one — a new `EditAction::make()`
        // carries none of the call site's gates, so asserting on it would prove nothing about this
        // screen. That distinction is the finding: the seam ANDs with what the call site declared,
        // and for a relation manager the call site is the only thing that knows the rule.
        $action = portalUsersRm($this->tenant)->instance()
            ->getTable()
            ->getAction('edit')
            ->record($this->portalAdmin);

        expect($action->isAuthorized())->toBeFalse();

        // The control, on the same action instance: a read-only login IS editable, so the refusal
        // is about which record it was asked about.
        expect($action->record($this->readOnly)->isAuthorized())->toBeTrue();
    });

    expect($this->portalAdmin->fresh()->password)->toBe($original)
        ->and(Hash::check('whatever-they-chose', $this->portalAdmin->fresh()->password))->toBeFalse();
});
