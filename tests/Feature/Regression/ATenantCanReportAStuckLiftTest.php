<?php

/*
|--------------------------------------------------------------------------
| A tenant can report a stuck lift, and the work order knows which trade it is
|--------------------------------------------------------------------------
| `TenantRequestType::subcategories()` returned seven maintenance values; `trades` seeds fourteen.
| `RaiseCorrectiveWorkOrderService::tradeForRequest()` bridged them by comparing
| `tenant_requests.category` against `trades.code` — a string match between two lists nothing kept
| in step. So seven trades the operator dispatches every week could not be REPORTED: elevator,
| generator, fire safety, pest control, security, landscaping, waste.
|
| The tenant picked "other", and the corrective work order was raised with NO trade at all —
| invisible to every by-trade report, to the craft rate the cost object reads, and to vendor
| eligibility. The fault was visible; its classification was not.
|
| The fix is the foreign key, not the row: the registers cannot drift again, and a naming mismatch
| stops mattering.
*/

use App\Enums\TenantRequestType;
use App\Models\TenantRequestSubcategory;
use App\Models\Trade;
use App\Services\RaiseCorrectiveWorkOrderService;
use Database\Seeders\TenantRequestSubcategorySeeder;

beforeEach(function () {
    $this->seed(TenantRequestSubcategorySeeder::class);
    $this->asset = makeAsset();
});

it('offers every trade the operator can dispatch, not half of them', function () {
    $offered = array_keys(TenantRequestSubcategory::optionsFor(TenantRequestType::Maintenance));
    $trades = Trade::query()->pluck('code')->all();

    // The seven that were unreportable. Named individually rather than counted, because a count
    // passes just as happily if the register gains an unrelated row.
    //
    // Collected and compared as a SET, not asserted with `toContain($code, $message)` — Pest's
    // `toContain()` takes VARIADIC needles, so a "message" second argument silently becomes a
    // second thing it looks for and the failure output blames the wrong value.
    $wanted = ['elevator', 'generator', 'fire_safety', 'pest_control', 'security', 'landscaping', 'waste'];
    $missing = array_values(array_diff($wanted, $offered));

    expect($missing)->toBe([], 'A tenant still cannot report: '.implode(', ', $missing));

    // The premise: the trade register really does have more than the enum's seven, so this is
    // measuring a closed gap and not an equality that was always true.
    expect(count($trades))->toBeGreaterThan(7);
});

it('resolves the trade by link, across a code that does not match', function () {
    // `fire_safety` (subcategory) against `fire-safety` (trade) — a hyphen apart. The old string
    // match resolved this to NULL even if the subcategory had existed, which is the sharpest
    // illustration of why a foreign key and not a name.
    $tradeId = Trade::query()->where('code', 'fire-safety')->value('id');

    expect($tradeId)->not->toBeNull()
        ->and(TenantRequestSubcategory::tradeIdFor('fire_safety', TenantRequestType::Maintenance))
        ->toBe($tradeId);
});

it('gives a corrective work order the trade the tenant reported', function () {
    $unit = makeUnit($this->asset);
    $tenant = makeTenant(['asset_id' => $this->asset->id]);
    makeLease($unit, $tenant, ['status' => 'active']);

    // `fire_safety`, NOT `elevator`, and the choice is the whole test. Most subcategory codes equal
    // their trade code, so the old string match resolved them and this case would pass with the fix
    // reverted — which is exactly what it did on the first attempt. `fire_safety` against the
    // `fire-safety` trade is one hyphen apart, so only the foreign key can bridge it.
    $request = reportFaultThroughTheService($tenant, $unit, 'fire_safety');

    $order = app(RaiseCorrectiveWorkOrderService::class)->fromTenantRequest($request, ['execution_type' => 'internal']);

    expect($order->trade_id)->toBe(Trade::query()->where('code', 'fire-safety')->value('id'),
        'The fire-safety fault reached the work order with no trade — invisible to every by-trade report.');

    // The control: a code that DOES match by name still resolves, so the link did not replace one
    // working path with another.
    $lift = reportFaultThroughTheService($tenant, $unit, 'elevator');
    $liftOrder = app(RaiseCorrectiveWorkOrderService::class)->fromTenantRequest($lift, ['execution_type' => 'internal']);

    expect($liftOrder->trade_id)->toBe(Trade::query()->where('code', 'elevator')->value('id'));
});

it('still gives a complaint no trade at all', function () {
    // The control, and the rule the trade register exists to protect: a tenant picks a PROBLEM, not
    // a craft. Copying the category across is what put `noise`, `parking` and `lease_copy` in the
    // trade column for the whole of module 26's life.
    expect(TenantRequestSubcategory::tradeIdFor('noise', TenantRequestType::Complaint))->toBeNull()
        ->and(TenantRequestSubcategory::tradeIdFor('lease_copy', TenantRequestType::Document))->toBeNull();
});

it('refuses a subcategory that is in neither the floor nor the catalogue', function () {
    // `tenant_requests.category` had NO value set, so a typo'd or imported value saved cleanly and
    // then resolved to no trade — the same silence, arriving from a different direction.
    $unit = makeUnit($this->asset);
    $tenant = makeTenant(['asset_id' => $this->asset->id]);
    makeLease($unit, $tenant, ['status' => 'active']);

    expect(fn () => reportFaultThroughTheService($tenant, $unit, 'nonsense'))
        ->toThrow(DomainException::class);
});
