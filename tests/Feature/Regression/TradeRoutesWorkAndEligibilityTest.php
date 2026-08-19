<?php

/*
|--------------------------------------------------------------------------
| The trade register — close-out step 1 (2026-08-20)
|--------------------------------------------------------------------------
| Benchmark: ServiceChannel makes the trade the spine of the model — it routes the work, decides
| which providers are eligible, and is the axis every spend report groups by
| (`docs/benchmarks/fm/02-servicechannel-contractor-loop.md` §2).
|
| Before this, `category` was a `Select` fed from a TRANSLATION ARRAY: unenforced (not in
| `ValueSets`), un-extendable without a deploy in two languages, and hardcoded a second and third
| time in the equipment form and table with four trades missing from both. `vendors` carried no
| trade at all, so nothing could answer "who may we dispatch?".
|
| These pin the three properties that make it a spine rather than a dropdown.
*/

use App\Models\Trade;
use App\Models\Vendor;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    $this->hvac = Trade::where('code', 'hvac')->firstOrFail();
});

function dispatchableVendor(string $name, array $tradeIds = []): Vendor
{
    $v = Vendor::create([
        'name' => $name, 'legal_name' => $name.' LLC', 'status' => 'active', 'type' => 'contractor',
    ]);
    $v->trades()->sync($tradeIds);

    return $v;
}

/* ---- 1. the register is data, in both languages ------------------------- */

it('ships the operator vocabulary as rows, named in both languages', function () {
    expect(Trade::count())->toBe(14);

    $hvac = $this->hvac;
    app()->setLocale('en');
    expect($hvac->label())->toBe('HVAC');
    app()->setLocale('ar');
    expect($hvac->label())->toBe('التكييف والتهوية');
    app()->setLocale('en');
});

/**
 * The point of it being a row: an operator adds a trade without a deploy, and it is named
 * correctly in both languages immediately.
 */
it('lets the operator add a trade without a deploy', function () {
    $t = Trade::create([
        'code' => 'glazing', 'name_en' => 'Glazing', 'name_ar' => 'الزجاج', 'is_active' => true,
    ]);

    expect(Trade::options())->toHaveKey($t->id);
    app()->setLocale('ar');
    expect(Trade::options()[$t->id])->toBe('الزجاج');
    app()->setLocale('en');
});

/* ---- 2. eligibility — a SUGGESTION, never a filter ---------------------- */

/**
 * **The distinction this rests on.** Filament validates a `Select` by checking the submitted value
 * against its options with `Rule::in`, so removing the ineligible vendors would REFUSE a legitimate
 * pick — and the day the usual HVAC contractor is unavailable is a real day. So the picker groups.
 */
it('opens the vendor picker on the contractors who do the trade, without hiding the rest', function () {
    $cools = dispatchableVendor('Cool Air', [$this->hvac->id]);
    $paper = dispatchableVendor('Paper Supplies');   // the stationery supplier

    $grouped = Vendor::assignableOptions(null, $this->hvac->id);

    expect(array_keys($grouped))->toBe([
        __('admin.facility.vendor_groups.for_this_trade'),
        __('admin.facility.vendor_groups.other'),
    ])
        ->and($grouped[__('admin.facility.vendor_groups.for_this_trade')])->toHaveKey($cools->id)
        // …and the stationery supplier is STILL PICKABLE, just not suggested. This is the assertion
        // that distinguishes a suggestion from a filter, and the one a hard filter would fail.
        ->and($grouped[__('admin.facility.vendor_groups.other')])->toHaveKey($paper->id);
});

/** With no trade in hand the picker is a plain flat list — no empty headings. */
it('does not group the vendor picker when no trade has been chosen', function () {
    $v = dispatchableVendor('Cool Air', [$this->hvac->id]);

    expect(Vendor::assignableOptions())->toHaveKey($v->id)
        ->and(Vendor::assignableOptions())->not->toHaveKey(__('admin.facility.vendor_groups.other'));
});

/** A heading with nothing under it reads as a bug, so an empty group is dropped. */
it('drops an empty group rather than rendering a heading with nothing under it', function () {
    dispatchableVendor('Cool Air', [$this->hvac->id]);

    $grouped = Vendor::assignableOptions(null, $this->hvac->id);

    expect(array_keys($grouped))->toBe([__('admin.facility.vendor_groups.for_this_trade')]);
});

/**
 * **Eligibility is about capability; compliance is about permission.** A blacklisted contractor who
 * does the trade must not be suggested — or offered at all — because `assignable()` is the real
 * gate and it runs first.
 */
it('never offers a non-dispatchable contractor, however eligible', function () {
    $blacklisted = Vendor::create([
        'name' => 'Risky Contracting', 'legal_name' => 'Risky Contracting LLC',
        'status' => 'blacklisted', 'type' => 'contractor',
    ]);
    $blacklisted->trades()->sync([$this->hvac->id]);

    // The CONTROL, and it is load-bearing: without a vendor who does get offered, the picker
    // would be empty, the assertion below would sit in a loop that never runs, and the test would
    // pass whether or not the guard existed. (It did — PHPUnit flagged it risky, which is the only
    // reason this comment exists.)
    $good = dispatchableVendor('Cool Air', [$this->hvac->id]);

    // `+` rather than collapse()/merge(): the ids are numeric keys, and both of those REINDEX
    // them — which turned this assertion into a lookup for vendor 0, 1, 2.
    $flattened = array_reduce(Vendor::assignableOptions(null, $this->hvac->id), fn (array $c, array $g): array => $c + $g, []);

    expect($flattened)->toHaveKey($good->id)
        ->and($flattened)->not->toHaveKey($blacklisted->id);
});

/* ---- 3. a vendor does MANY trades -------------------------------------- */

it('lets one company hold several trades rather than being registered twice', function () {
    $electrical = Trade::where('code', 'electrical')->firstOrFail();
    $delta = dispatchableVendor('Delta FM', [$this->hvac->id, $electrical->id]);

    expect($delta->trades()->count())->toBe(2)
        ->and(Vendor::assignableOptions(null, $this->hvac->id)[__('admin.facility.vendor_groups.for_this_trade')])
        ->toHaveKey($delta->id)
        ->and(Vendor::assignableOptions(null, $electrical->id)[__('admin.facility.vendor_groups.for_this_trade')])
        ->toHaveKey($delta->id);
});

/* ---- 4. the register refuses to lose its own history -------------------- */

it('refuses to delete a trade that has routed work, and says what to do instead', function () {
    $trade = Trade::create(['code' => 'glazing', 'name_en' => 'Glazing', 'name_ar' => 'الزجاج']);
    dispatchableVendor('Glass Co', [$trade->id]);

    expect(fn () => $trade->delete())->toThrow(DomainException::class);

    // The documented alternative works, and is what makes RetiredTradeStillEditableTest matter.
    $trade->update(['is_active' => false]);
    expect($trade->fresh()->is_active)->toBeFalse();
});

/** The control: an unused trade IS ordinary cleanup. */
it('allows deleting a trade nothing has ever used', function () {
    $trade = Trade::create(['code' => 'glazing', 'name_en' => 'Glazing', 'name_ar' => 'الزجاج']);

    $trade->delete();

    expect(Trade::find($trade->id))->toBeNull();
});
