<?php

use App\Filament\Admin\Resources\MaintenanceRequests\MaintenanceRequestResource;
use Database\Seeders\RolesPermissionsSeeder;

it('marks closed and cancelled work-orders as terminal', function () {
    expect(makeMaintenanceRequest(['status' => 'closed'])->isTerminal())->toBeTrue()
        ->and(makeMaintenanceRequest(['status' => 'cancelled'])->isTerminal())->toBeTrue()
        ->and(makeMaintenanceRequest(['status' => 'in_progress'])->isTerminal())->toBeFalse();
});

it('blocks editing a closed or cancelled work-order (REQ-3)', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));

    expect(MaintenanceRequestResource::canEdit(makeMaintenanceRequest(['status' => 'in_progress'])))->toBeTrue()
        ->and(MaintenanceRequestResource::canEdit(makeMaintenanceRequest(['status' => 'closed'])))->toBeFalse()
        ->and(MaintenanceRequestResource::canEdit(makeMaintenanceRequest(['status' => 'cancelled'])))->toBeFalse();
});
