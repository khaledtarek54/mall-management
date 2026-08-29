<?php

/*
|--------------------------------------------------------------------------
| The rentable-item picker offers only what can be assigned (2026-08-28)
|--------------------------------------------------------------------------
| Reported from the panel: a lease already holding all three of a property's items was offered all
| three, and choosing one failed on submit —
|
|     "This lease already holds P-101. Give it back first if you need to change the date or the rate."
|
| The options query excluded the holder's OWN holdings from the clash test, under a comment saying
| re-assigning a bay it already holds should read as "you have this" rather than "someone has this".
| `AssignRentableItemService::assign()` refuses exactly that, so the intent was never realised and
| the comment described behaviour that did not exist.
|
| **A picker whose value the write guard rejects is the worst kind**, because the operator has
| already decided by the time they are told no. The clash test now has no exception, and the
| service's refusal stays as the backstop for a crafted submit — the picker is UI, not a gate.
|
| The empty state is named for the same reason the other pickers' are: "No options" cannot be told
| apart from a broken screen, and here it has a real cause the operator can act on.
*/

use App\Models\RentableItem;
use App\Services\AssignRentableItemService;
use App\Support\RentableItemOptions;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset, ['area_sqm' => 110]), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-08-01',
        'expiry_date' => '2029-07-31',
    ]);

    $this->bay = RentableItem::create([
        'asset_id' => $this->asset->id, 'code' => 'P-101', 'name' => 'Bay 101',
        'type' => 'parking', 'status' => 'available', 'monthly_rate' => 1500,
    ]);
    $this->spare = RentableItem::create([
        'asset_id' => $this->asset->id, 'code' => 'P-102', 'name' => 'Bay 102',
        'type' => 'parking', 'status' => 'available', 'monthly_rate' => 1500,
    ]);
});

it('drops an item this lease already holds', function () {
    app(AssignRentableItemService::class)->assign($this->lease, $this->bay, [
        'effective_from' => '2026-08-01', 'monthly_rate' => 1500,
    ]);

    $options = RentableItemOptions::lettable($this->lease->fresh());

    expect($options)->not->toHaveKey($this->bay->id)
        // …and still offers the one that is free, or the guard would be indistinguishable from a
        // picker that broke.
        ->and($options)->toHaveKey($this->spare->id);
});

it('offers nothing at all when the lease holds everything', function () {
    foreach ([$this->bay, $this->spare] as $item) {
        app(AssignRentableItemService::class)->assign($this->lease, $item, [
            'effective_from' => '2026-08-01', 'monthly_rate' => 1500,
        ]);
    }

    expect(RentableItemOptions::lettable($this->lease->fresh()))->toBe([]);
});

it('agrees with the service — everything offered can actually be assigned', function () {
    // The property that matters. Asserting the picker alone would pass on a picker that had drifted
    // the other way and hidden something assignable.
    app(AssignRentableItemService::class)->assign($this->lease, $this->bay, [
        'effective_from' => '2026-08-01', 'monthly_rate' => 1500,
    ]);

    foreach (array_keys(RentableItemOptions::lettable($this->lease->fresh())) as $id) {
        app(AssignRentableItemService::class)->assign(
            $this->lease->fresh(),
            RentableItem::findOrFail($id),
            ['effective_from' => '2026-09-01', 'monthly_rate' => 1500],
        );
    }

    expect($this->lease->fresh()->rentableItems()->count())->toBe(2);
});

it('still refuses a crafted submit — the picker is UI, not a gate', function () {
    app(AssignRentableItemService::class)->assign($this->lease, $this->bay, [
        'effective_from' => '2026-08-01', 'monthly_rate' => 1500,
    ]);

    expect(fn () => app(AssignRentableItemService::class)->assign($this->lease->fresh(), $this->bay, [
        'effective_from' => '2026-09-01', 'monthly_rate' => 1500,
    ]))->toThrow(DomainException::class);
});

it('offers an item that has been given back', function () {
    // The reverse property: released items must return to the list, or a bay is lost the first time
    // it is let and given back.
    //
    // The release date is in the PAST on purpose. My first version released "end of month" and
    // expected the bay back immediately — but a holding that runs to the 31st is still a holding on
    // the 29th, and the picker was right to keep it out. The list answers "free NOW", which is the
    // question an assignment starting today is asking.
    app(AssignRentableItemService::class)->assign($this->lease, $this->bay, [
        'effective_from' => now()->subMonths(2)->startOfMonth()->toDateString(), 'monthly_rate' => 1500,
    ]);

    expect(RentableItemOptions::lettable($this->lease->fresh()))->not->toHaveKey($this->bay->id);

    app(AssignRentableItemService::class)->release(
        $this->lease->fresh(), $this->bay, now()->subDays(3)->toDateString(),
    );

    expect(RentableItemOptions::lettable($this->lease->fresh()))->toHaveKey($this->bay->id);
});
