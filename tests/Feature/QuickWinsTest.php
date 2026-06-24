<?php

it('stores a tenant commercial register number (TEN-1)', function () {
    $tenant = makeTenant(['commercial_register' => 'CR-12345']);

    expect($tenant->fresh()->commercial_register)->toBe('CR-12345');
});

it('stores a scheduled work window on a maintenance request (REQ-1)', function () {
    $req = makeMaintenanceRequest([
        'scheduled_from' => '2026-07-01 09:00:00',
        'scheduled_to' => '2026-07-01 12:00:00',
    ])->fresh();

    expect($req->scheduled_from)->not->toBeNull()
        ->and($req->scheduled_from->format('Y-m-d H:i'))->toBe('2026-07-01 09:00')
        ->and($req->scheduled_to->format('H:i'))->toBe('12:00');
});
