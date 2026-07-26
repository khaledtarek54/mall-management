<?php

use App\Filament\Admin\Resources\Areas\AreaResource;
use App\Filament\Admin\Resources\Areas\Schemas\AreaForm;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Models\Area;
use App\Models\MaintenanceWorkOrder;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\AreaRequestRaisedNotification;
use App\Notifications\AreaWorkOrderRaisedNotification;
use App\Services\TenantRequestService;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Area routing (module 30 → 11): a unit belongs to a zone, a request inherits its unit's zone on
 * intake (enforced in the model for admin + portal + API), and the zone's supervisors are notified
 * — informed, never auto-assigned. Plus the two review follow-ups: the supervisor picker is
 * property-scoped, and out-of-scope attaches are re-validated server-side after save.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'ZRA']);
});

function makeZone(int $assetId, array $attrs = []): Area
{
    return Area::create(array_merge([
        'asset_id' => $assetId,
        'code' => 'FC',
        'name' => 'Food Court',
    ], $attrs));
}

function makeZoneRequest(Unit $unit, ?Tenant $tenant, array $attrs = []): TenantRequest
{
    return TenantRequest::create(array_merge([
        'reference' => 'MR-'.uniqid(),
        'unit_id' => $unit->id,
        'tenant_id' => $tenant?->id,
        // Caller-only intake when there's no tenant (a staff channel logging a walk-in).
        'channel' => $tenant ? 'portal' : 'phone',
        'caller_name' => $tenant ? null : 'Walk-in caller',
        'title' => 'Leak',
        'description' => 'Water everywhere',
        'status' => 'submitted',
        'priority' => 'medium',
        'category' => 'plumbing',
        'submitted_at' => now(),
    ], $attrs));
}

/* ---- derivation: a request inherits its unit's zone --------------------- */

it('inherits the unit\'s zone on request creation', function () {
    $zone = makeZone($this->asset->id);
    $unit = makeUnit($this->asset, ['area_id' => $zone->id]);

    $request = makeZoneRequest($unit, makeTenant());

    expect($request->area_id)->toBe($zone->id);
});

it('never overrides an explicitly-set zone', function () {
    $unitZone = makeZone($this->asset->id, ['code' => 'FC']);
    $explicit = makeZone($this->asset->id, ['code' => 'PKG', 'name' => 'Parking']);
    $unit = makeUnit($this->asset, ['area_id' => $unitZone->id]);

    $request = makeZoneRequest($unit, makeTenant(), ['area_id' => $explicit->id]);

    expect($request->area_id)->toBe($explicit->id);
});

it('leaves a caller-only request on a zone-less unit with no zone', function () {
    Notification::fake();
    $unit = makeUnit($this->asset); // no area_id

    $request = makeZoneRequest($unit, null); // tenant-less caller intake

    expect($request->tenant_id)->toBeNull();
    expect($request->area_id)->toBeNull();
    Notification::assertNothingSent();
});

it('derives the zone through TenantRequestService (portal / API path)', function () {
    Notification::fake();
    $zone = makeZone($this->asset->id);
    $supervisor = makeUser('operations', [$this->asset->id]);
    $zone->supervisors()->attach($supervisor->id);
    $unit = makeUnit($this->asset, ['area_id' => $zone->id]);
    $tenant = makeTenant();
    makeLease($unit, $tenant);

    $request = app(TenantRequestService::class)->create([
        'title' => 'Leak',
        'description' => 'Water everywhere',
        'unit_id' => $unit->id,
    ], $tenant);

    expect($request->area_id)->toBe($zone->id);
    Notification::assertSentTo($supervisor, AreaRequestRaisedNotification::class);
});

/* ---- routing: notify the zone's supervisors ----------------------------- */

it('notifies the zone supervisors when a request lands in their area', function () {
    Notification::fake();
    $zone = makeZone($this->asset->id);
    $sup1 = makeUser('operations', [$this->asset->id]);
    $sup2 = makeUser('operations', [$this->asset->id]);
    $zone->supervisors()->sync([$sup1->id, $sup2->id]);
    $unit = makeUnit($this->asset, ['area_id' => $zone->id]);

    makeZoneRequest($unit, makeTenant());

    foreach ([$sup1, $sup2] as $sup) {
        Notification::assertSentTo($sup, AreaRequestRaisedNotification::class);
    }
});

it('is a safe no-op when the zone has no supervisors', function () {
    Notification::fake();
    $zone = makeZone($this->asset->id); // no supervisors attached
    $unit = makeUnit($this->asset, ['area_id' => $zone->id]);

    makeZoneRequest($unit, makeTenant());

    Notification::assertNotSentTo(makeUser('operations', [$this->asset->id]), AreaRequestRaisedNotification::class);
    Notification::assertNothingSent();
});

it('is a safe no-op when the unit has no zone', function () {
    Notification::fake();
    $unit = makeUnit($this->asset); // no area_id

    $request = makeZoneRequest($unit, makeTenant());

    expect($request->area_id)->toBeNull();
    Notification::assertNothingSent();
});

/* ---- follow-up 6b: the supervisor picker is property-scoped -------------- */

it('offers the supervisor picker only the property\'s own (or property-less) staff', function () {
    $other = makeAsset(['code' => 'ZRB']);
    $mallAStaff = makeUser('operations', [$this->asset->id]);
    $mallBStaff = makeUser('operations', [$other->id]);
    $propertyLess = makeUser('super_admin'); // no asset assignment

    // The exact predicate the picker's modifyQueryUsing applies.
    $offered = AreaForm::applySupervisorScope(User::query(), $this->asset->id)->pluck('id')->all();

    expect($offered)
        ->toContain($mallAStaff->id)
        ->toContain($propertyLess->id)
        ->not->toContain($mallBStaff->id);

    // A null property (none chosen / out of scope) offers nothing.
    expect(AreaForm::applySupervisorScope(User::query(), null)->pluck('id')->all())->toBe([]);
});

/* ---- follow-up 6a: server-side supervisor re-validation ----------------- */

it('strips and rejects an out-of-scope supervisor attach on save', function () {
    $other = makeAsset(['code' => 'ZRB']);
    $zone = makeZone($this->asset->id);
    $mallBStaff = makeUser('operations', [$other->id]); // cannot service mall A's zones

    // Simulate the relationship Select syncing a tampered id the picker never offered.
    $zone->supervisors()->attach($mallBStaff->id);

    expect(fn () => AreaResource::assertSupervisorsInScope($zone->fresh()))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    // The tampered attach is stripped (a 403, never a silent 500 or a leaked pivot).
    expect($zone->fresh()->supervisors()->count())->toBe(0);
});

it('accepts in-scope + property-less supervisors on save', function () {
    $zone = makeZone($this->asset->id);
    $mallAStaff = makeUser('operations', [$this->asset->id]);
    $propertyLess = makeUser('super_admin');

    $zone->supervisors()->sync([$mallAStaff->id, $propertyLess->id]);

    AreaResource::assertSupervisorsInScope($zone->fresh()); // no throw
    expect($zone->fresh()->supervisors()->count())->toBe(2);
});

/* ---- review finding: a unit's zone must belong to its own property (server guard) ------ */

it('refuses to tag a unit with a zone from another property, even via a crafted request', function () {
    // The UnitForm picker only OFFERS the unit's own property's zones, but that's UX — a crafted
    // Livewire request can submit any area_id (Filament's Select adds no exists/in rule). The
    // server-side guard is UnitResource::assertAreaInScope, wired on the create + edit pages.
    // Without it, a mall-A unit tagged with a mall-B zone would leak mall-A request data to mall-B
    // supervisors via the routing fan-out. (Adversarial-review finding on Phase 9b-2.)
    $mallA = makeAsset(['code' => 'GRA']);
    $mallB = makeAsset(['code' => 'GRB']);
    $zoneB = Area::create(['asset_id' => $mallB->id, 'name' => 'B Zone', 'code' => 'BZ']);
    $zoneA = Area::create(['asset_id' => $mallA->id, 'name' => 'A Zone', 'code' => 'AZ']);

    // Cross-property zone → 403.
    expect(fn () => UnitResource::assertAreaInScope($zoneB->id, $mallA->id))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

    // Same-property zone, and "no zone", both pass.
    UnitResource::assertAreaInScope($zoneA->id, $mallA->id);
    UnitResource::assertAreaInScope(null, $mallA->id);
    expect(true)->toBeTrue();
});

/* ---- work-order routing (module 30 → 11): the work-order half of the zone routing ------- */

function makeZoneWorkOrder(int $assetId, array $attrs = []): MaintenanceWorkOrder
{
    return MaintenanceWorkOrder::create(array_merge([
        'asset_id' => $assetId,
        'work_order_type' => MaintenanceWorkOrder::TYPE_PPM,
        'title' => 'Filter swap',
        'category' => 'hvac',
        'scheduled_for' => '2026-07-01',
        'status' => 'open',
    ], $attrs));
}

it('derives a work order\'s zone from its unit on creation', function () {
    Notification::fake();
    $zone = makeZone($this->asset->id);
    $unit = makeUnit($this->asset, ['area_id' => $zone->id]);

    $order = makeZoneWorkOrder($this->asset->id, ['unit_id' => $unit->id]);

    expect($order->area_id)->toBe($zone->id);
});

it('derives a work order\'s zone from its linked tenant request (preferred over the unit)', function () {
    Notification::fake();
    $unitZone = makeZone($this->asset->id, ['code' => 'FC']);
    $requestZone = makeZone($this->asset->id, ['code' => 'PKG', 'name' => 'Parking']);
    // The request carries a DIFFERENT (explicitly-set) zone than the unit — the WO follows the request.
    $unit = makeUnit($this->asset, ['area_id' => $unitZone->id]);
    $request = makeZoneRequest($unit, makeTenant(), ['area_id' => $requestZone->id]);

    $order = makeZoneWorkOrder($this->asset->id, [
        'unit_id' => $unit->id,
        'tenant_request_id' => $request->id,
    ]);

    expect($order->area_id)->toBe($requestZone->id);
});

it('never overrides a work order\'s explicitly-set zone (e.g. the plan\'s area)', function () {
    Notification::fake();
    $unitZone = makeZone($this->asset->id, ['code' => 'FC']);
    $planZone = makeZone($this->asset->id, ['code' => 'ROOF', 'name' => 'Roof Plant']);
    $unit = makeUnit($this->asset, ['area_id' => $unitZone->id]);

    $order = makeZoneWorkOrder($this->asset->id, [
        'unit_id' => $unit->id,
        'area_id' => $planZone->id, // as GeneratePreventiveWorkOrdersService passes the plan's area
    ]);

    expect($order->area_id)->toBe($planZone->id);
});

it('notifies the zone supervisors when a work order lands in their area', function () {
    Notification::fake();
    $zone = makeZone($this->asset->id);
    $sup1 = makeUser('operations', [$this->asset->id]);
    $sup2 = makeUser('operations', [$this->asset->id]);
    $zone->supervisors()->sync([$sup1->id, $sup2->id]);
    $unit = makeUnit($this->asset, ['area_id' => $zone->id]);

    makeZoneWorkOrder($this->asset->id, ['unit_id' => $unit->id]);

    foreach ([$sup1, $sup2] as $sup) {
        Notification::assertSentTo($sup, AreaWorkOrderRaisedNotification::class);
    }
});

it('is a safe no-op when the work order has no zone or the zone has no supervisors', function () {
    Notification::fake();
    // No zone on the unit → no area, no notification.
    $unitNoZone = makeUnit($this->asset);
    makeZoneWorkOrder($this->asset->id, ['unit_id' => $unitNoZone->id]);

    // A zone with no supervisors → derived area, still no notification.
    $zone = makeZone($this->asset->id, ['code' => 'EMPTY']);
    $unit = makeUnit($this->asset, ['area_id' => $zone->id]);
    $order = makeZoneWorkOrder($this->asset->id, ['unit_id' => $unit->id]);

    expect($order->area_id)->toBe($zone->id);
    Notification::assertNothingSent();
});
