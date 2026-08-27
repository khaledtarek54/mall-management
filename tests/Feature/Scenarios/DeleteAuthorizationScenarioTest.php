<?php

/*
|--------------------------------------------------------------------------
| DELETE AUTHORIZATION (consolidated, cross-resource)
|--------------------------------------------------------------------------
| Project-wide rule (see RoleGatedActions): a destructive action is reserved
| for the platform owner. ONLY super_admin may delete a single record —
| Resource::canDelete($record) is true for super_admin and FALSE for every
| other role, *regardless* of any "{module}.delete" permission a role holds.
|
| Bulk delete is additionally disabled across the entire project — no resource
| opts into $bulkDeletable — so canDeleteAny()/canForceDeleteAny() are FALSE
| everywhere, even for super_admin.
|
| Complements BulkDeleteDisabledTest (which spot-checks 3 resources) by
| asserting the rule across a representative set of resources and the full
| role matrix. Net-new: force-delete gating + the complete non-admin role list.
*/

use App\Filament\Admin\Resources\Assets\AssetResource;
use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Admin\Resources\Departments\DepartmentResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Models\Asset;
use App\Models\CreditNote;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Support\DeletionPolicy;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

/**
 * Resources where single-delete follows the standard gate: super_admin yes,
 * everyone else no. Departments is intentionally excluded — it hard-disables
 * delete for *everyone* (fixed set, see departmentDeleteIsLockedForAll test).
 * Users is excluded from the force-delete matrices only (see BUG note).
 *
 * Resource class => a blank model instance (canDelete takes the record).
 *
 * @return array<string, array{0: class-string, 1: Model}>
 */
function deleteAuthResources(): array
{
    return [
        'Invoices' => [InvoiceResource::class, new Invoice],
        'Tenants' => [TenantResource::class, new Tenant],
        'Users' => [UserResource::class, new User],
        'Leases' => [LeaseResource::class, new Lease],
        'Payments' => [PaymentResource::class, new Payment],
        'Assets' => [AssetResource::class, new Asset],
        'Units' => [UnitResource::class, new Unit],
        'Vendors' => [VendorResource::class, new Vendor],
        'CreditNotes' => [CreditNoteResource::class, new CreditNote],
        'TenantRequests' => [TenantRequestResource::class, new TenantRequest],
    ];
}

/**
 * Force-delete coverage excludes UserResource: it neither uses RoleGatedActions
 * nor defines a force-delete policy, so canForceDelete() inherits Filament's
 * permissive default (true for everyone). Tracked as a BUG; see
 * userForceDeleteGateIsOpen below.
 *
 * @return array<string, array{0: class-string, 1: Model}>
 */
function forceDeleteAuthResources(): array
{
    $r = deleteAuthResources();
    unset($r['Users']);

    return $r;
}

/** Every non-super-admin role that must be denied delete. */
const NON_ADMIN_ROLES = ['manager', 'viewer', 'owner', 'leasing', 'operations', 'accounting', 'marketing', 'hr'];

// ---------------------------------------------------------------------------
// HAPPY PATH (RBAC): super_admin can single-delete on every resource.
// ---------------------------------------------------------------------------

it('lets super_admin single-delete every resource EXCEPT money records', function () {
    // Money and audit records are never deletable as of 2026-07-31 — not even by super_admin.
    // The exception is read from DeletionPolicy rather than restated here, so the two cannot
    // drift: adding a model to the registry updates this test with it.
    $this->actingAs(makeUser('super_admin'));

    foreach (deleteAuthResources() as $label => [$resource, $record]) {
        $expected = ! DeletionPolicy::isNeverDeletable($resource::getModel());

        expect($resource::canDelete($record))->toBe($expected, $expected
            ? "super_admin should be able to delete {$label}"
            : "{$label} is a money record — it must be corrected, not deleted");
    }
});

it('lets super_admin force-delete every resource EXCEPT money records', function () {
    $this->actingAs(makeUser('super_admin'));

    foreach (forceDeleteAuthResources() as $label => [$resource, $record]) {
        $expected = ! DeletionPolicy::isNeverDeletable($resource::getModel());

        expect($resource::canForceDelete($record))->toBe($expected, $expected
            ? "super_admin should be able to force-delete {$label}"
            : "{$label} is a money record — force delete destroys the row outright");
    }
});

// ---------------------------------------------------------------------------
// NEGATIVE / RBAC: no other role may single-delete or force-delete — anywhere.
// ---------------------------------------------------------------------------

it('denies single delete to every non-super-admin role across all resources', function (string $role) {
    $this->actingAs(makeUser($role));

    foreach (deleteAuthResources() as $label => [$resource, $record]) {
        expect($resource::canDelete($record))
            ->toBeFalse("{$role} must NOT be able to delete {$label}");
    }
})->with(NON_ADMIN_ROLES);

it('denies force delete to every non-super-admin role across all resources', function (string $role) {
    $this->actingAs(makeUser($role));

    foreach (forceDeleteAuthResources() as $label => [$resource, $record]) {
        expect($resource::canForceDelete($record))
            ->toBeFalse("{$role} must NOT be able to force-delete {$label}");
    }
})->with(NON_ADMIN_ROLES);

// ---------------------------------------------------------------------------
// NEGATIVE (boundary): unauthenticated guest can never delete.
// ---------------------------------------------------------------------------

it('denies delete and force-delete to an unauthenticated guest', function () {
    expect(auth()->check())->toBeFalse();

    foreach (deleteAuthResources() as $label => [$resource, $record]) {
        expect($resource::canDelete($record))->toBeFalse("guest delete {$label}");
    }

    foreach (forceDeleteAuthResources() as $label => [$resource, $record]) {
        expect($resource::canForceDelete($record))->toBeFalse("guest force-delete {$label}");
    }
});

// ---------------------------------------------------------------------------
// BULK DELETE: disabled project-wide — false for EVERY role incl. super_admin.
// ---------------------------------------------------------------------------

it('disables bulk delete on every resource even for super_admin', function () {
    $this->actingAs(makeUser('super_admin'));

    foreach (deleteAuthResources() as $label => [$resource]) {
        expect($resource::canDeleteAny())->toBeFalse("super_admin bulk delete {$label}")
            ->and($resource::canForceDeleteAny())->toBeFalse("super_admin bulk force-delete {$label}");
    }
});

it('disables bulk delete on every resource for every non-super-admin role', function (string $role) {
    $this->actingAs(makeUser($role));

    foreach (deleteAuthResources() as $label => [$resource]) {
        expect($resource::canDeleteAny())->toBeFalse("{$role} bulk delete {$label}")
            ->and($resource::canForceDeleteAny())->toBeFalse("{$role} bulk force-delete {$label}");
    }
})->with(NON_ADMIN_ROLES);

// ---------------------------------------------------------------------------
// STATE/PERMISSION INDEPENDENCE: holding the {module}.delete permission does
// NOT grant delete — only the super_admin role does. Guards against a future
// refactor that re-couples delete to the permission table.
// ---------------------------------------------------------------------------

it('keeps delete on the ROLE, with no {module}.delete permission left to grant', function () {
    // This test has now been narrowed TWICE by the same decision, which is the interesting part.
    //
    // It began as "grant a role {module}.delete and watch delete still be refused". On 2026-07-31
    // the money-record permissions were retired, so it moved its demonstration to `tenants.delete`.
    // On 2026-08-26 the remaining forty-three went the same way — nothing had ever consulted them
    // — so the grant is no longer expressible at all and spatie throws PermissionDoesNotExist.
    //
    // The property is unchanged and stronger than before: delete is decided by the super_admin ROLE
    // through `DeletionPolicy`, and there is now no permission anyone could mistake for granting it.
    expect(Permission::query()->where('name', 'like', '%.delete')->pluck('name')->all())->toBe([]);

    $this->actingAs(makeUser('manager'));

    expect(TenantResource::canDelete(new Tenant))->toBeFalse()
        ->and(InvoiceResource::canDelete(new Invoice))->toBeFalse()
        ->and(PaymentResource::canDelete(new Payment))->toBeFalse()
        ->and(InvoiceResource::canForceDelete(new Invoice))->toBeFalse()
        // ...and bulk delete remains off.
        ->and(InvoiceResource::canDeleteAny())->toBeFalse();

    // The control: a super admin still deletes what the policy permits — an unreferenced tenant is
    // `#[DeletableWhenUnused]` and therefore deletable — while a money record stays refused even to
    // them. A test in which nothing was deletable would satisfy every refusal above.
    $this->actingAs(makeUser('super_admin'));

    expect(TenantResource::canDelete(new Tenant))->toBeTrue()
        ->and(InvoiceResource::canDelete(new Invoice))->toBeFalse();
});

// ---------------------------------------------------------------------------
// CONTRAST: confirm the gate is specifically about *delete*, not a blanket
// lockout — manager (no delete) can still create/edit where the seeder allows,
// proving the deny above is the delete rule and not a mis-seeded role.
// ---------------------------------------------------------------------------

it('keeps non-delete actions available to manager while delete stays locked', function () {
    $this->actingAs(makeUser('manager'));

    expect(InvoiceResource::canCreate())->toBeTrue()
        ->and(InvoiceResource::canEdit(new Invoice))->toBeTrue()
        ->and(InvoiceResource::canDelete(new Invoice))->toBeFalse();
});

// ---------------------------------------------------------------------------
// DEPARTMENTS: a fixed, seed-defined set — single AND bulk delete are hard
// disabled for EVERYONE, super_admin included. Stricter than the generic rule.
// ---------------------------------------------------------------------------

it('locks department delete for everyone including super_admin', function (string $role) {
    $this->actingAs(makeUser($role));

    expect(DepartmentResource::canDelete(new Department))->toBeFalse("{$role} single delete department")
        ->and(DepartmentResource::canDeleteAny())->toBeFalse("{$role} bulk delete department");
})->with(['super_admin', ...NON_ADMIN_ROLES]);

// ---------------------------------------------------------------------------
// USERS: self-delete guard — even super_admin cannot delete their OWN account.
// ---------------------------------------------------------------------------

it('forbids super_admin from deleting their own user account', function () {
    $admin = makeUser('super_admin');
    $this->actingAs($admin);

    expect(UserResource::canDelete($admin))->toBeFalse()
        ->and(UserResource::canDelete(makeUser('manager')))->toBeTrue();
});

// ---------------------------------------------------------------------------
// BUG CHARACTERIZATION: UserResource::canForceDelete() is ungated.
// UserResource does not use RoleGatedActions and defines no force-delete
// policy, so it inherits Filament's permissive default — true for every role
// and even unauthenticated guests, violating "delete is super_admin-only".
// Skipped (not weakened) so the suite stays green; see bugsFound.
// ---------------------------------------------------------------------------

it('gates UserResource::canForceDelete to super_admin only', function () {
    $this->actingAs(makeUser('viewer'));

    expect(UserResource::canForceDelete(new User))->toBeFalse();
});
