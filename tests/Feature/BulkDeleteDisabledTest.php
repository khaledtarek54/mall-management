<?php

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Models\Vendor;
use Database\Seeders\RolesPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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
    // `canDelete()` gates on the super_admin ROLE alone. It never consulted `{module}.delete`, which
    // is why that whole family was retired on 2026-08-26 — so the grant this test used to make is
    // no longer expressible, and the invariant is stronger for it: there is no permission left that
    // could be mistaken for granting delete.
    expect(Permission::query()->where('name', 'like', '%.delete')->exists())->toBeFalse();

    $this->actingAs(makeUser('viewer'));
    expect(VendorResource::canDelete(new Vendor))->toBeFalse();

    $this->actingAs(makeUser('super_admin'));
    expect(VendorResource::canDelete(new Vendor))->toBeTrue();
});
