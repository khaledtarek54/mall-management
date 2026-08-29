<?php

/*
|--------------------------------------------------------------------------
| A rentable item says what kind of thing it is (2026-08-28)
|--------------------------------------------------------------------------
| Two reports from the panel, one root cause each.
|
| **The assign picker named no type.** A mall lets bays, signage, storage and kiosks from ONE
| register, and the option read "SGN-A · EGP 8,000.00" — which tells the operator what kind only
| through a code they chose themselves. Anyone who inherited the register could not tell a signage
| licence from a parking bay.
|
| The STATUS is deliberately still absent, which answers the other half of the question that was
| asked: the query already offers only what is lettable — out-of-service excluded, anything
| currently held rejected — so every option is available by construction, and printing "available"
| on all of them would be a column of one value.
|
| **And the billing forecast called the whole charge "Parking".** `ChargeCode::labelFor()` asked the
| LANG FILE first and fell back to the catalogue — the opposite of every other code catalogue here,
| where `IsCodeCatalogue::labelFor()` reads its rows and only then the group. So an operator
| renaming a shipped code changed nothing anywhere it is displayed, and `parking` — whose catalogue
| row is named "Parking & rentable items" precisely because it bills all four kinds — went on
| calling itself Parking. A signage licence appeared under a heading naming car parks.
*/

use App\Models\ChargeCode;
use App\Models\RentableItem;
use App\Support\RentableItemOptions;
use Database\Seeders\ChargeCodeSeeder;

beforeEach(function () {
    ensureAllPropertiesAsset();
    // The catalogue the labels come from — without it every assertion here is about the lang-file
    // floor and proves nothing about the row winning.
    $this->seed(ChargeCodeSeeder::class);
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
    $this->sign = RentableItem::create([
        'asset_id' => $this->asset->id, 'code' => 'SGN-A', 'name' => 'Main entrance sign',
        'type' => 'signage', 'status' => 'available', 'monthly_rate' => 8000,
    ]);
});

it('names the kind in every option', function () {
    $options = RentableItemOptions::lettable($this->lease);

    expect($options[$this->bay->id])->toContain(__('admin.enums.rentable_item_type.parking'))
        ->and($options[$this->sign->id])->toContain(__('admin.enums.rentable_item_type.signage'))
        // …and still says which one and what it costs.
        ->and($options[$this->sign->id])->toContain('SGN-A')
        ->and($options[$this->sign->id])->toContain('8,000.00');
});

it('offers only what can actually be let', function () {
    // The answer to "should it show rented ones too": no. An occupied bay is not a choice, and a
    // picker that offers one is a picker whose value will be refused.
    $this->sign->update(['status' => RentableItem::STATUS_OUT_OF_SERVICE]);

    $options = RentableItemOptions::lettable($this->lease);

    expect($options)->toHaveKey($this->bay->id)
        ->and($options)->not->toHaveKey($this->sign->id);
});

it('lets the CATALOGUE name a charge code, not the lang file', function () {
    // `parking` bills bays, signage, storage and kiosks — its catalogue row says so, and the label
    // must follow the row an operator can edit.
    expect(ChargeCode::labelFor('parking'))
        ->toBe(ChargeCode::where('code', 'parking')->first()->label());
});

it('follows a RENAME', function () {
    // The property the old order could not have: renaming a shipped code changed nothing.
    ChargeCode::where('code', 'parking')->update(['name_en' => 'Bays, signage and storage']);
    ChargeCode::flushLookupCaches();

    expect(ChargeCode::labelFor('parking'))->toBe('Bays, signage and storage');
});

it('still falls back to the lang file, then to the code', function () {
    // The floor is load-bearing twice: a fresh install has no catalogue rows, and a code the
    // accountant adds has no lang key and would otherwise render as
    // `admin.enums.invoice_item_type.chiller_charge` on the invoice.
    ChargeCode::where('code', 'base_rent')->delete();
    ChargeCode::flushLookupCaches();

    expect(ChargeCode::labelFor('base_rent'))->toBe(__('admin.enums.invoice_item_type.base_rent'))
        ->and(ChargeCode::labelFor('chiller_charge'))->toBe('Chiller Charge');
});
