<?php

use App\Models\Lease;
use App\Models\RentableItem;
use App\Services\AssignRentableItemService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

/**
 * Letting a parking bay, store or signage face — and billing it (space model).
 *
 * **The assignment moves no money by itself.** What bills is an ordinary `parking` charge on the
 * lease's schedule, so the monthly run, VAT and the GL need no knowledge that rentable items exist.
 * That is the whole reason this was built on the charge schedule rather than beside it, and the
 * billing test at the bottom is what proves it rather than asserting it.
 *
 * **One charge row per lease, not per item** — forced by `Charge`'s overlap guard (two active rows
 * of a type covering one period are refused) and, independently, what an operator wants on an
 * invoice: "Parking & rentable items", not four near-identical lines.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function itemFor(\App\Models\Asset $asset, string $code, float $rate = 900): RentableItem
{
    return RentableItem::create([
        'asset_id' => $asset->id,
        'code' => $code,
        'type' => RentableItem::TYPE_PARKING,
        'monthly_rate' => $rate,
    ]);
}

function leaseFor(\App\Models\Asset $asset, string $unitCode = 'S-01'): Lease
{
    return makeLease(makeUnit($asset, ['code' => $unitCode, 'area_sqm' => 100]), null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2029-12-31',
        'base_rent_monthly' => 30000,
    ])->fresh();
}

it('opens one parking charge for the items a lease holds', function () {
    CarbonImmutable::setTestNow('2026-03-05');
    $asset = makeAsset();
    $lease = leaseFor($asset);
    $service = app(AssignRentableItemService::class);

    $service->assign($lease, itemFor($asset, 'P-001'), ['effective_from' => '2026-03-01']);
    $service->assign($lease->fresh(), itemFor($asset, 'P-002'), ['effective_from' => '2026-03-01']);

    $rows = $lease->fresh()->charges()->where('type', 'parking')->get();

    // ONE row, summed — not two rows of 900.
    expect($rows)->toHaveCount(1)
        ->and((float) $rows->first()->amount)->toBe(1800.0);
});

it('honours a negotiated rate over the item’s asking rate', function () {
    CarbonImmutable::setTestNow('2026-03-05');
    $asset = makeAsset();
    $lease = leaseFor($asset);

    app(AssignRentableItemService::class)
        ->assign($lease, itemFor($asset, 'P-001', 900), ['effective_from' => '2026-03-01', 'monthly_rate' => 650]);

    expect((float) $lease->fresh()->charges()->where('type', 'parking')->sole()->amount)->toBe(650.0);
});

it('bills the bay through the ordinary monthly run', function () {
    // The claim the whole design rests on: nothing in billing, VAT or the GL knows what a rentable
    // item is, and the tenant still gets charged for it.
    CarbonImmutable::setTestNow('2026-03-05');
    $asset = makeAsset();
    $lease = leaseFor($asset);

    app(AssignRentableItemService::class)
        ->assign($lease, itemFor($asset, 'P-001', 900), ['effective_from' => '2026-03-01']);

    app(MonthlyBillingService::class)->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-03-01'));

    $line = $lease->fresh()->invoices()->sole()->items()->where('type', 'parking')->sole();

    expect((float) $line->total)->toBe(900.0)
        // Billed VAT-exempt until the accountant rules — the conservative direction.
        ->and((float) $line->vat_amount)->toBe(0.0);
});

it('stops billing the month after the bay goes back', function () {
    CarbonImmutable::setTestNow('2026-03-05');
    $asset = makeAsset();
    $lease = leaseFor($asset);
    $item = itemFor($asset, 'P-001');
    $service = app(AssignRentableItemService::class);

    $service->assign($lease, $item, ['effective_from' => '2026-03-01']);
    $service->release($lease->fresh(), $item->fresh(), '2026-06-30');

    $rows = $lease->fresh()->charges()->where('type', 'parking')->orderBy('start_date')->get();

    // March–June at 900, then nothing from July — the old amount stays true for its own months.
    expect((float) $rows->first()->amount)->toBe(900.0)
        ->and($rows->first()->end_date->toDateString())->toBe('2026-06-30')
        ->and((float) $rows->last()->amount)->toBe(0.0)
        ->and($rows->last()->start_date->toDateString())->toBe('2026-07-01');
});

it('frees the bay for re-letting once it is given back', function () {
    CarbonImmutable::setTestNow('2026-03-05');
    $asset = makeAsset();
    $item = itemFor($asset, 'P-001');
    $first = leaseFor($asset, 'S-01');
    $second = leaseFor($asset, 'S-02');
    $service = app(AssignRentableItemService::class);

    $service->assign($first, $item, ['effective_from' => '2026-03-01']);

    expect($item->fresh()->status)->toBe(RentableItem::STATUS_ASSIGNED);

    $service->release($first->fresh(), $item->fresh(), '2026-06-30');

    expect($item->fresh()->status)->toBe(RentableItem::STATUS_AVAILABLE);

    // And the next tenant can take it from July.
    $service->assign($second, $item->fresh(), ['effective_from' => '2026-07-01']);

    expect((float) $second->fresh()->charges()->where('type', 'parking')->sole()->amount)->toBe(900.0);
});

it('refuses to double-book a bay', function () {
    // The same rule the premises have — and the reason the service locks the ITEM rather than the
    // lease: two operators assigning the same bay contend on the item row.
    CarbonImmutable::setTestNow('2026-03-05');
    $asset = makeAsset();
    $item = itemFor($asset, 'P-001');
    $service = app(AssignRentableItemService::class);

    $service->assign(leaseFor($asset, 'S-01'), $item, ['effective_from' => '2026-03-01']);

    expect(fn () => $service->assign(leaseFor($asset, 'S-02'), $item->fresh(), ['effective_from' => '2026-04-01']))
        ->toThrow(DomainException::class);
});

it('refuses a bay from another property', function () {
    CarbonImmutable::setTestNow('2026-03-05');
    $here = makeAsset();
    $elsewhere = makeAsset();

    expect(fn () => app(AssignRentableItemService::class)
        ->assign(leaseFor($here), itemFor($elsewhere, 'P-999'), ['effective_from' => '2026-03-01']))
        ->toThrow(DomainException::class);
});

it('refuses an item that is out of service', function () {
    CarbonImmutable::setTestNow('2026-03-05');
    $asset = makeAsset();
    $item = itemFor($asset, 'P-001');
    $item->update(['status' => RentableItem::STATUS_OUT_OF_SERVICE]);

    expect(fn () => app(AssignRentableItemService::class)
        ->assign(leaseFor($asset), $item->fresh(), ['effective_from' => '2026-03-01']))
        ->toThrow(DomainException::class);

    // The control: back in service, the same call is accepted.
    $item->update(['status' => RentableItem::STATUS_AVAILABLE]);

    app(AssignRentableItemService::class)
        ->assign(leaseFor($asset, 'S-02'), $item->fresh(), ['effective_from' => '2026-03-01']);

    expect($item->fresh()->status)->toBe(RentableItem::STATUS_ASSIGNED);
});
