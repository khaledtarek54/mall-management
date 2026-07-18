<?php

use App\Models\Area;
use App\Models\TenantRequest;
use App\Models\Unit;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * FR-REQ-08 — automatic assignment by area. A new request is auto-assigned to its zone's supervisor
 * when the zone has EXACTLY ONE (the unambiguous "designated supervisor" the FRD means). A zone with
 * several supervisors stays unassigned — they're all notified and a coordinator picks the owner
 * (manual assignment, FR-REQ-07). Enforced in TenantRequest::creating, so admin + portal + API all
 * inherit it, and it never overrides an explicit assignee.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset(['code' => 'AA']);
});

function aaZone(int $assetId, string $code): Area
{
    return Area::create(['asset_id' => $assetId, 'name' => "Zone {$code}", 'code' => $code]);
}

function aaRequest(Unit $unit, array $attrs = []): TenantRequest
{
    return TenantRequest::create(array_merge([
        'reference' => 'MR-'.uniqid(),
        'unit_id' => $unit->id,
        'tenant_id' => makeTenant()->id,
        'channel' => 'portal',
        'title' => 'Leak', 'description' => 'Water',
        'status' => 'submitted', 'priority' => 'medium', 'category' => 'plumbing',
        'submitted_at' => now(),
    ], $attrs));
}

it('auto-assigns to the sole supervisor of the request\'s zone', function () {
    $supervisor = makeUser('technician', [$this->asset->id]);
    $zone = aaZone($this->asset->id, 'FC');
    $zone->supervisors()->attach($supervisor->id);
    $unit = makeUnit($this->asset, ['area_id' => $zone->id]);

    expect(aaRequest($unit)->assigned_to)->toBe($supervisor->id);
});

it('does NOT auto-assign when the zone has several supervisors (coordinator decides)', function () {
    $zone = aaZone($this->asset->id, 'AT');
    $zone->supervisors()->attach([
        makeUser('technician', [$this->asset->id])->id,
        makeUser('technician', [$this->asset->id])->id,
    ]);
    $unit = makeUnit($this->asset, ['area_id' => $zone->id]);

    expect(aaRequest($unit)->assigned_to)->toBeNull();
});

it('does NOT auto-assign when the zone has no supervisor', function () {
    $zone = aaZone($this->asset->id, 'RF');
    $unit = makeUnit($this->asset, ['area_id' => $zone->id]);

    expect(aaRequest($unit)->assigned_to)->toBeNull();
});

it('does not auto-assign a request whose unit has no zone', function () {
    $unit = makeUnit($this->asset); // no area_id

    $r = aaRequest($unit);
    expect($r->area_id)->toBeNull()
        ->and($r->assigned_to)->toBeNull();
});

it('never overrides an explicit assignee', function () {
    $supervisor = makeUser('technician', [$this->asset->id]);
    $chosen = makeUser('technician', [$this->asset->id]);
    $zone = aaZone($this->asset->id, 'CT');
    $zone->supervisors()->attach($supervisor->id);
    $unit = makeUnit($this->asset, ['area_id' => $zone->id]);

    expect(aaRequest($unit, ['assigned_to' => $chosen->id])->assigned_to)->toBe($chosen->id);
});
