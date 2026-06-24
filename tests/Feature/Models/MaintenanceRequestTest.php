<?php

use App\Models\MaintenanceRequest;

it('isOpen() recognises every OPEN_STATUSES value', function () {
    foreach (MaintenanceRequest::OPEN_STATUSES as $status) {
        $req = makeMaintenanceRequest(['status' => $status]);
        expect($req->isOpen())->toBeTrue();
    }

    foreach (['resolved', 'closed', 'cancelled'] as $status) {
        $req = makeMaintenanceRequest(['status' => $status]);
        expect($req->isOpen())->toBeFalse();
    }
});

it('isOverdue() returns true past target_resolution_at while still open', function () {
    $stale = makeMaintenanceRequest([
        'status' => 'in_progress',
        'target_resolution_at' => now()->subHours(3),
    ]);

    $onTime = makeMaintenanceRequest([
        'status' => 'in_progress',
        'target_resolution_at' => now()->addHours(3),
    ]);

    $closed = makeMaintenanceRequest([
        'status' => 'closed',
        'target_resolution_at' => now()->subHours(3),
    ]);

    expect($stale->isOverdue())->toBeTrue();
    expect($onTime->isOverdue())->toBeFalse();
    expect($closed->isOverdue())->toBeFalse();
});
