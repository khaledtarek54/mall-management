<?php

use App\Notifications\MaintenanceStatusChangedNotification;

/**
 * Once requests became typed, the notification copy must speak the request's
 * actual type — a resolved complaint must not tell the tenant "Maintenance
 * CR-… is now resolved". The :type placeholder is fed by typeLabel().
 */
it('uses the request type label in status notifications, not a hard-coded "Maintenance"', function () {
    $complaint = makeMaintenanceRequest(['request_type' => 'complaint', 'status' => 'in_progress']);

    $data = (new MaintenanceStatusChangedNotification($complaint->fresh(), 'submitted'))
        ->toDatabase($complaint->tenant);

    expect($data['title'])->toContain('Complaint')->not->toContain('Maintenance');
});

it('still labels a maintenance request as Maintenance', function () {
    $maintenance = makeMaintenanceRequest(['status' => 'in_progress']); // defaults to maintenance

    $data = (new MaintenanceStatusChangedNotification($maintenance->fresh(), 'submitted'))
        ->toDatabase($maintenance->tenant);

    expect($data['title'])->toContain('Maintenance');
});
