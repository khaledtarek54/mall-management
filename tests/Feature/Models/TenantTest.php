<?php

it('computes outstanding balance from open invoices only', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $tenant = makeTenant();
    $lease = makeLease($unit, $tenant);

    makeInvoice($lease, ['balance' => 1000, 'status' => 'issued']);
    makeInvoice($lease, ['balance' => 500, 'status' => 'overdue']);
    makeInvoice($lease, ['balance' => 0, 'status' => 'paid']);
    makeInvoice($lease, ['balance' => 9999, 'status' => 'cancelled']);

    expect($tenant->outstandingBalance())->toBe(1500.0);
});

it('flags delinquency when an invoice is overdue', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $tenant = makeTenant();
    $lease = makeLease($unit, $tenant);

    expect($tenant->isDelinquent())->toBeFalse();

    makeInvoice($lease, [
        'balance' => 500,
        'status' => 'overdue',
        'due_date' => now()->subDays(5),
    ]);

    expect($tenant->fresh()->isDelinquent())->toBeTrue();
});

it('exposes activeLeases scoped to active status', function () {
    $asset = makeAsset();
    $unit1 = makeUnit($asset);
    $unit2 = makeUnit($asset);
    $tenant = makeTenant();

    makeLease($unit1, $tenant, ['status' => 'active']);
    makeLease($unit2, $tenant, ['status' => 'terminated']);

    expect($tenant->leases)->toHaveCount(2);
    expect($tenant->activeLeases)->toHaveCount(1);
});
