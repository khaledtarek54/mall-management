<?php

use App\Models\Lease;
use App\Models\LeaseOption;
use Carbon\CarbonImmutable;

/**
 * The one critical date that cannot be recovered once missed (UX-09).
 *
 * The "what needs action" widget already carried lease expiries, vendor contract notices, holdovers,
 * overdue AR and the rest. Option NOTICE WINDOWS were the gap — and they are the item with the
 * hardest deadline: once the window passes the right lapses, a renewal the tenant loses or a break
 * they can no longer take. `leases:scan-option-windows` notified about it; nothing showed it in the
 * work-list an operator actually opens.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

it('counts a lease whose option notice window closes inside 90 days', function () {
    CarbonImmutable::setTestNow('2026-06-01');
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset, ['code' => 'CD-1']), null, ['status' => 'active']);

    LeaseOption::create([
        'lease_id' => $lease->id,
        'type' => 'renewal',
        'status' => 'open',
        'earliest_notice_date' => '2026-05-01',
        'latest_notice_date' => '2026-07-15',   // inside the window
    ]);

    $found = Lease::whereHas('options', fn ($q) => $q
        ->where('status', 'open')
        ->whereNotNull('latest_notice_date')
        ->whereBetween('latest_notice_date', [now(), now()->addDays(90)]))
        ->pluck('id');

    expect($found)->toContain($lease->id);
});

it('ignores a window that has already been resolved or is far off', function () {
    // Both controls matter: a lapsed option is not an action, and one closing next year is not
    // urgent. Without them the list fills with rows nobody can act on and stops being read.
    CarbonImmutable::setTestNow('2026-06-01');
    $asset = makeAsset();

    $lapsed = makeLease(makeUnit($asset, ['code' => 'CD-2']), null, ['status' => 'active']);
    LeaseOption::create([
        'lease_id' => $lapsed->id, 'type' => 'renewal', 'status' => 'lapsed',
        'latest_notice_date' => '2026-07-15',
    ]);

    $distant = makeLease(makeUnit($asset, ['code' => 'CD-3']), null, ['status' => 'active']);
    LeaseOption::create([
        'lease_id' => $distant->id, 'type' => 'renewal', 'status' => 'open',
        'latest_notice_date' => '2027-06-01',
    ]);

    $found = Lease::whereHas('options', fn ($q) => $q
        ->where('status', 'open')
        ->whereNotNull('latest_notice_date')
        ->whereBetween('latest_notice_date', [now(), now()->addDays(90)]))
        ->pluck('id');

    expect($found)->not->toContain($lapsed->id)
        ->and($found)->not->toContain($distant->id);
});
