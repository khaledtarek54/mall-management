<?php

use App\Filament\Admin\Widgets\ActionRequired;
use App\Models\Lease;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Module-04 (MVP holdover alert). A lease past its end date but still `active` keeps the shop
 * occupied while the monthly engine silently stops billing it (rent-free holdover). We don't
 * auto-bill it (that's a deferred domain decision) — we make it VISIBLE on the ActionRequired
 * dashboard + a table filter, so it can never go silent.
 */
it('scopes holdover to active leases past their end date only', function () {
    $asset = makeAsset();
    $holdover = makeLease(makeUnit($asset), makeTenant(), ['status' => 'active', 'expiry_date' => now()->subDays(5)->toDateString()]);
    $current = makeLease(makeUnit($asset), makeTenant(), ['status' => 'active', 'expiry_date' => now()->addMonths(6)->toDateString()]);
    $endedStatus = makeLease(makeUnit($asset), makeTenant(), ['status' => 'terminated', 'expiry_date' => now()->subDays(5)->toDateString()]);

    $ids = Lease::holdover()->pluck('id');

    expect($ids)->toContain($holdover->id)
        ->and($ids)->not->toContain($current->id)      // not yet expired
        ->and($ids)->not->toContain($endedStatus->id)  // past expiry but no longer active
        ->and($holdover->isHoldover())->toBeTrue()
        ->and($current->isHoldover())->toBeFalse();
});

it('surfaces holdover leases on the ActionRequired dashboard card', function () {
    // Real role definitions — the holdover card is gated on `leases.view`, and `makeUser()` alone
    // creates a role with no permissions at all.
    $this->seed(RolesPermissionsSeeder::class);

    $asset = makeAsset();
    makeLease(makeUnit($asset), makeTenant(), ['status' => 'active', 'expiry_date' => now()->subDays(10)->toDateString()]);

    $this->actingAs(makeUser('manager', [$asset->id]));

    asTenant($asset, function () {
        $items = collect((new ActionRequired)->getViewData()['items']);
        $holdover = $items->firstWhere('key', 'holdover');

        expect($holdover)->not->toBeNull()
            ->and($holdover['color'])->toBe('danger');
    });
});

it('does not show the holdover card when no lease is in holdover', function () {
    $asset = makeAsset();
    makeLease(makeUnit($asset), makeTenant(), ['status' => 'active', 'expiry_date' => now()->addYear()->toDateString()]);

    $this->actingAs(makeUser('manager', [$asset->id]));

    asTenant($asset, function () {
        $items = collect((new ActionRequired)->getViewData()['items']);
        expect($items->firstWhere('key', 'holdover'))->toBeNull();
    });
});
