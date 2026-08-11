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

    // March–June at 900, and then NOTHING — the row is closed, not replaced by a zero one.
    //
    // This assertion used to expect a second row at 0.00 from 1 July, and was wrong: it encoded the
    // defect rather than the requirement. A zero-amount row put "Parking & rentable items —
    // EGP 0.00" on every invoice for the rest of the term. The old amount still stays true for the
    // months it was true for, which was the part worth keeping.
    expect($rows)->toHaveCount(1)
        ->and((float) $rows->first()->amount)->toBe(900.0)
        ->and($rows->first()->end_date->toDateString())->toBe('2026-06-30')
        ->and((bool) $rows->first()->is_active)->toBeFalse();
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

it('refuses to let the SAME lease take the same bay twice', function () {
    // Found by adversarial review, not by the happy path. The held-check excluded the assigning
    // lease — meant for "somebody else has it" — so a second assignment on a different date was
    // accepted, the pivot took two rows, and `rebuildCharge()` summed one bay twice. A double-click
    // or an operator correcting a date DOUBLED the tenant's parking bill with nothing to show.
    CarbonImmutable::setTestNow('2026-03-05');
    $asset = makeAsset();
    $lease = leaseFor($asset);
    $item = itemFor($asset, 'P-001', 900);
    $service = app(AssignRentableItemService::class);

    $service->assign($lease, $item, ['effective_from' => '2026-03-01']);

    expect(fn () => $service->assign($lease->fresh(), $item->fresh(), ['effective_from' => '2026-04-01']))
        ->toThrow(DomainException::class);

    expect(\Illuminate\Support\Facades\DB::table('lease_rentable_item')->count())->toBe(1)
        ->and((float) $lease->fresh()->charges()->where('type', 'parking')->sole()->amount)->toBe(900.0);
});

it('closes the parking charge rather than billing zero for ever', function () {
    // The other review find. Releasing the last item called `setAmount(0)`, which opened a
    // zero-amount row — and the billing run put "Parking & rentable items — EGP 0.00" on every
    // invoice for the rest of the term. A charge for nothing is not a charge.
    CarbonImmutable::setTestNow('2026-03-05');
    $asset = makeAsset();
    $lease = leaseFor($asset);
    $item = itemFor($asset, 'P-001', 900);
    $service = app(AssignRentableItemService::class);

    $service->assign($lease, $item, ['effective_from' => '2026-03-01']);
    $service->release($lease->fresh(), $item->fresh(), '2026-03-31');

    $rows = $lease->fresh()->charges()->where('type', 'parking')->get();

    // The March row stays true for March, and nothing is in force afterwards.
    expect($rows)->toHaveCount(1)
        ->and((float) $rows->first()->amount)->toBe(900.0)
        ->and($rows->first()->end_date->toDateString())->toBe('2026-03-31')
        ->and((bool) $rows->first()->is_active)->toBeFalse();

    // And April's invoice carries no parking line at all.
    CarbonImmutable::setTestNow('2026-04-05');
    app(MonthlyBillingService::class)->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-04-01'));

    expect($lease->fresh()->invoices()->get()
        ->flatMap(fn ($i) => $i->items->pluck('type'))
        ->contains('parking'))->toBeFalse();
});

it('bills parking VAT only when the accountant has ruled it taxable', function () {
    // Rent is exempt, service charge is standard-rated, parking is neither obviously — so it is the
    // accountant's ruling on the `parking` charge code rather than a constant, shipping EXEMPT
    // because under-charging beats collecting tax that may not be due. (It was a settings toggle
    // until 2026-08-11, when taxability moved onto the charge-code catalogue with every other
    // supply's.)
    CarbonImmutable::setTestNow('2026-03-05');
    $asset = makeAsset();
    $service = app(AssignRentableItemService::class);

    // Off (the default): exempt.
    $exempt = leaseFor($asset, 'V-01');
    $service->assign($exempt, itemFor($asset, 'P-101', 1000), ['effective_from' => '2026-03-01']);
    $row = $exempt->fresh()->charges()->where('type', 'parking')->sole();

    expect((bool) $row->vat_applicable)->toBeFalse()
        ->and((float) $row->vat_rate)->toBe(0.0);

    // The accountant rules that parking is a taxable supply — one row, no deploy.
    \App\Models\ChargeCode::updateOrCreate(
        ['code' => 'parking'],
        ['name_en' => 'Parking', 'name_ar' => 'مواقف', 'tax_code' => 'VAT_14'],
    );

    $taxed = leaseFor($asset, 'V-02');
    $service->assign($taxed, itemFor($asset, 'P-102', 1000), ['effective_from' => '2026-03-01']);
    $taxedRow = $taxed->fresh()->charges()->where('type', 'parking')->sole();

    expect((bool) $taxedRow->vat_applicable)->toBeTrue()
        // The settings-driven standard rate, never a literal.
        ->and((float) $taxedRow->vat_rate)->toBe(\App\Support\Vat::standardRate())
        // …and the earlier lease is untouched: origination only, so a rate change never rewrites
        // what was already billed.
        ->and((bool) $exempt->fresh()->charges()->where('type', 'parking')->sole()->vat_applicable)
        ->toBeFalse();
});
