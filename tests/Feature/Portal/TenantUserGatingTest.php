<?php

use App\Filament\Portal\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Portal\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;

it('lets an admin tenant user submit but makes non-admins read-only', function () {
    $tenant = makeTenant();

    // Admin tenant user — may submit.
    $this->actingAs(makeTenantUser($tenant, isAdmin: true), 'portal');
    expect(TenantRequestResource::canCreate())->toBeTrue()
        ->and(TenantSalesDeclarationResource::canCreate())->toBeTrue();

    // Non-admin tenant user — read-only: can VIEW but not submit.
    $this->actingAs(makeTenantUser($tenant, isAdmin: false), 'portal');
    expect(TenantRequestResource::canViewAny())->toBeTrue()        // viewing is shared
        ->and(TenantSalesDeclarationResource::canViewAny())->toBeTrue()
        ->and(TenantRequestResource::canCreate())->toBeFalse()     // submitting is admin-only
        ->and(TenantSalesDeclarationResource::canCreate())->toBeFalse();
});
