<?php

use App\Models\TenantRequest;

it('isOpen() recognises every OPEN_STATUSES value', function () {
    foreach (TenantRequest::OPEN_STATUSES as $status) {
        $req = makeTenantRequest(['status' => $status]);
        expect($req->isOpen())->toBeTrue();
    }

    foreach (['resolved', 'closed', 'cancelled'] as $status) {
        $req = makeTenantRequest(['status' => $status]);
        expect($req->isOpen())->toBeFalse();
    }
});

it('isOverdue() returns true past target_resolution_at while still open', function () {
    $stale = makeTenantRequest([
        'status' => 'in_progress',
        'target_resolution_at' => now()->subHours(3),
    ]);

    $onTime = makeTenantRequest([
        'status' => 'in_progress',
        'target_resolution_at' => now()->addHours(3),
    ]);

    $closed = makeTenantRequest([
        'status' => 'closed',
        'target_resolution_at' => now()->subHours(3),
    ]);

    expect($stale->isOverdue())->toBeTrue();
    expect($onTime->isOverdue())->toBeFalse();
    expect($closed->isOverdue())->toBeFalse();
});
