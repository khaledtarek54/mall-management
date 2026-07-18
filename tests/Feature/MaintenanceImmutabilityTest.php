<?php

use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use Database\Seeders\RolesPermissionsSeeder;

it('marks closed and cancelled work-orders as terminal', function () {
    expect(makeTenantRequest(['status' => 'closed'])->isTerminal())->toBeTrue()
        ->and(makeTenantRequest(['status' => 'cancelled'])->isTerminal())->toBeTrue()
        ->and(makeTenantRequest(['status' => 'in_progress'])->isTerminal())->toBeFalse();
});

it('blocks editing a closed or cancelled work-order (REQ-3)', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));

    expect(TenantRequestResource::canEdit(makeTenantRequest(['status' => 'in_progress'])))->toBeTrue()
        ->and(TenantRequestResource::canEdit(makeTenantRequest(['status' => 'closed'])))->toBeFalse()
        ->and(TenantRequestResource::canEdit(makeTenantRequest(['status' => 'cancelled'])))->toBeFalse();
});
