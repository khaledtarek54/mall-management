<?php

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Models\Vendor;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

it('disables bulk delete by default on every resource, even with the delete permission', function () {
    // super_admin holds every *.delete permission.
    $this->actingAs(makeUser('super_admin'));

    expect(InvoiceResource::canDeleteAny())->toBeFalse()
        ->and(InvoiceResource::canForceDeleteAny())->toBeFalse()
        ->and(TenantResource::canDeleteAny())->toBeFalse()
        ->and(UserResource::canDeleteAny())->toBeFalse()
        // Roles too: bulk-deleting roles is a mass access revoke. It stays off
        // (only EditRole's single delete is audited) — re-enabling it without an
        // audit hook would silently drop the role_deleted trail.
        ->and(RoleResource::canDeleteAny())->toBeFalse();
});

it('restricts delete to super_admin, even when another role holds the delete permission', function () {
    // Use a still-deletable resource (Vendor): canDelete ignores the {module}.delete permission and
    // gates on the super_admin ROLE alone. (Money records like Invoice lost their .delete permission
    // entirely — they are never deletable; see DeletionPolicy.)
    \Spatie\Permission\Models\Role::findByName('viewer', 'web')->givePermissionTo('vendors.delete');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs(makeUser('viewer'));
    expect(VendorResource::canDelete(new Vendor()))->toBeFalse(); // holds vendors.delete, still can't delete

    $this->actingAs(makeUser('super_admin'));
    expect(VendorResource::canDelete(new Vendor()))->toBeTrue();
});
