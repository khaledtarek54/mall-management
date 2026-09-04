<?php

use App\Filament\Admin\Resources\TenantRequests\Pages\EditTenantRequest;
use App\Models\Area;
use App\Models\FacilityWorkOrder;
use Database\Seeders\RolesPermissionsSeeder;
use Database\Seeders\TenantRequestSubcategorySeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * SW-155 — A ZONE FOLLOWS THE UNIT IT WAS INHERITED FROM.
 *
 * `TenantRequest` and `FacilityWorkOrder` both derive `area_id` from `units.area_id` in a
 * `creating` hook, and neither ever looked again. So correcting the unit on the Edit page — the
 * ordinary fix for a request logged against the wrong shop — left the zone pointing at the shop it
 * used to be about, under a field the form renders DISABLED with the placeholder
 * `admin.fields.area_auto` ("auto") beside a comment saying "the derivation owns the value".
 *
 * The zone is the ROUTING dimension: `NotifyAreaSupervisorsService` tells that zone's supervisors,
 * both lists filter and group on it, and a corrective work order copies the request's zone — so a
 * stale one sends a technician to the wrong part of the mall.
 *
 * `App\Models\Concerns\InheritsAreaFromUnit` is the one seam; it re-inherits only what it gave (the
 * OLD unit's zone, or nothing at all), because the creating hook's own rule is that an explicitly
 * targeted zone is never overridden — an area-scoped PPM order carries the SERVICE PLAN's zone.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(TenantRequestSubcategorySeeder::class);

    $this->asset = makeAsset(['code' => 'ZN']);
    $this->zoneA = Area::create(['asset_id' => $this->asset->id, 'name' => 'Ground Floor', 'code' => 'GF']);
    $this->zoneB = Area::create(['asset_id' => $this->asset->id, 'name' => 'Food Court', 'code' => 'FC']);

    $this->unitA = makeUnit($this->asset, ['area_id' => $this->zoneA->id]);
    $this->unitB = makeUnit($this->asset, ['area_id' => $this->zoneB->id]);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('moves a request to the new unit\'s zone when the unit is corrected on the Edit page', function () {
    $request = makeTenantRequest([
        'unit_id' => $this->unitA->id,
        'asset_id' => $this->asset->id,
        'request_type' => 'maintenance',
        'category' => 'electrical',
    ]);

    // The control: intake got it right, which is why the staleness is invisible.
    expect((int) $request->area_id)->toBe($this->zoneA->id);

    asTenant($this->asset, function () use ($request) {
        Livewire::test(EditTenantRequest::class, ['record' => $request->getRouteKey()])
            ->fillForm(['unit_id' => $this->unitB->id])
            ->call('save')
            ->assertHasNoFormErrors()
            // The disabled "auto" field must not go on showing the old zone under a success
            // toast — the shape RefreshesRecordState exists for. The ARRAY form of assertFormSet,
            // because the closure form ignores what its closure returns.
            ->assertFormSet(['area_id' => $this->zoneB->id]);
    });

    expect((int) $request->fresh()->area_id)->toBe($this->zoneB->id);
});

it('leaves a zone somebody targeted deliberately alone', function () {
    // The creating hook's own rule — "an explicitly-set area_id is never overridden (a caller may
    // target a zone directly)" — has to keep holding after intake, or this fix trades one silent
    // overwrite for another.
    $request = makeTenantRequest([
        'unit_id' => $this->unitA->id,
        'asset_id' => $this->asset->id,
        // Filed against the food court although the shop is on the ground floor: a flood in the
        // unit that came from the food court above it.
        'area_id' => $this->zoneB->id,
    ]);

    expect((int) $request->area_id)->toBe($this->zoneB->id);

    $request->update(['unit_id' => $this->unitB->id]);

    expect((int) $request->fresh()->area_id)->toBe($this->zoneB->id);
});

it('fills in a zone that was never stated at all', function () {
    // Null is NOT STATED, not "the operator chose no zone" — so it is this hook's to fill. The QA
    // baseline is full of exactly these rows (10 of 10 requests, zone null against a zoned unit).
    $unzoned = makeUnit($this->asset);

    $request = makeTenantRequest([
        'unit_id' => $unzoned->id,
        'asset_id' => $this->asset->id,
    ]);

    expect($request->area_id)->toBeNull();

    $request->update(['unit_id' => $this->unitB->id]);

    expect((int) $request->fresh()->area_id)->toBe($this->zoneB->id);
});

it('moves a work order to the new unit\'s zone too', function () {
    // The same defect in the module that dispatches the technician. Enumerated by grepping
    // `area_id`, not from the row — a fix written from a diff certifies only the coverage it added.
    $order = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id, 'unit_id' => $this->unitA->id,
        'work_order_type' => 'cm', 'execution_type' => 'internal',
        'title' => 'Fix lights', 'description' => 'Lights out in the unit',
        'trade_id' => tradeId('electrical'), 'scheduled_for' => now()->toDateString(),
    ]);

    expect((int) $order->area_id)->toBe($this->zoneA->id);

    $order->update(['unit_id' => $this->unitB->id]);

    expect((int) $order->fresh()->area_id)->toBe($this->zoneB->id);
});

it('does not take a work order off a zone that was stated rather than inherited', function () {
    // The case that makes "re-inherit only what it gave" load-bearing rather than tidy. A route PPM
    // plan is filed against a ZONE and carries no unit, so `GeneratePreventiveWorkOrdersService`
    // stamps the PLAN's `area_id` onto the order and the unit never gave it anything. Adding a unit
    // later must not silently re-home the job.
    $order = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id, 'area_id' => $this->zoneB->id,
        'work_order_type' => 'ppm', 'execution_type' => 'internal',
        'title' => 'Food court deep clean', 'description' => 'Monthly route',
        'trade_id' => tradeId('cleaning'), 'scheduled_for' => now()->toDateString(),
    ]);

    expect((int) $order->area_id)->toBe($this->zoneB->id);

    // A unit is added to the job — the zone stays the one the plan named.
    $order->update(['unit_id' => $this->unitA->id]);

    expect((int) $order->fresh()->area_id)->toBe($this->zoneB->id);
});
