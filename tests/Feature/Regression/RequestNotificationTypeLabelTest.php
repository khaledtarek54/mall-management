<?php

use App\Notifications\TenantRequestStatusChangedNotification;

/**
 * Once requests became typed, the notification copy must speak the request's
 * actual type — a resolved complaint must not tell the tenant "Maintenance
 * CR-… is now resolved". The :type placeholder is fed by typeLabel().
 */
it('uses the request type label in status notifications, not a hard-coded "Maintenance"', function () {
    $complaint = makeTenantRequest(['request_type' => 'complaint', 'status' => 'in_progress']);

    $data = (new TenantRequestStatusChangedNotification($complaint->fresh(), 'submitted'))
        ->toDatabase($complaint->tenant);

    expect($data['title'])->toContain('Complaint')->not->toContain('Maintenance');
});

it('still labels a maintenance request as Maintenance', function () {
    $maintenance = makeTenantRequest(['status' => 'in_progress']); // defaults to maintenance

    $data = (new TenantRequestStatusChangedNotification($maintenance->fresh(), 'submitted'))
        ->toDatabase($maintenance->tenant);

    expect($data['title'])->toContain('Maintenance');
});
