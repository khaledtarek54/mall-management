<?php

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Invoice;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

it('disables bulk delete by default on every resource, even with the delete permission', function () {
    // super_admin holds every *.delete permission.
    $this->actingAs(makeUser('super_admin'));

    expect(InvoiceResource::canDeleteAny())->toBeFalse()
        ->and(InvoiceResource::canForceDeleteAny())->toBeFalse()
        ->and(TenantResource::canDeleteAny())->toBeFalse()
        ->and(UserResource::canDeleteAny())->toBeFalse();
});

it('restricts delete to super_admin, even when another role holds the delete permission', function () {
    \Spatie\Permission\Models\Role::findByName('viewer', 'web')->givePermissionTo('invoices.delete');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs(makeUser('viewer'));
    expect(InvoiceResource::canDelete(new Invoice()))->toBeFalse();

    $this->actingAs(makeUser('super_admin'));
    expect(InvoiceResource::canDelete(new Invoice()))->toBeTrue();
});
