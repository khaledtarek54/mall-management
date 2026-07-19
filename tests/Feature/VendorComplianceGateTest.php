<?php

use App\Models\MaintenanceWorkOrder;
use App\Models\Vendor;

/**
 * Vendor compliance / COI gate (strengthen item #5): a mall must not dispatch a blacklisted /
 * inactive vendor, or one whose insurance (COI) has lapsed, to maintenance work. The gate is the
 * MaintenanceWorkOrder saving hook (the single server-side choke point); the pickers filter to
 * Vendor::assignable() too.
 */
function complianceVendor(array $attrs = []): Vendor
{
    return Vendor::create(array_merge([
        'name' => 'Acme '.uniqid(),
        'type' => 'contractor',
        'status' => Vendor::STATUS_ACTIVE,
    ], $attrs));
}

function externalCmWorkOrder(int $assetId, int $vendorId): MaintenanceWorkOrder
{
    return MaintenanceWorkOrder::create([
        'asset_id' => $assetId,
        'title' => 'Fix the lift',
        'category' => 'elevator',
        'status' => 'open',
        'scheduled_for' => now()->toDateString(),
        'work_order_type' => MaintenanceWorkOrder::TYPE_CM,
        'execution_type' => MaintenanceWorkOrder::EXECUTION_EXTERNAL,
        'description' => 'Lift is stuck between floors',
        'vendor_id' => $vendorId,
    ]);
}

it('dispatches a compliant vendor (active + valid COI)', function () {
    $asset = makeAsset();
    $vendor = complianceVendor(['coi_expires_at' => now()->addYear()->toDateString()]);

    expect($vendor->isDispatchable())->toBeTrue();
    $wo = externalCmWorkOrder($asset->id, $vendor->id);
    expect($wo->vendor_id)->toBe($vendor->id);
});

it('blocks dispatching a blacklisted vendor', function () {
    $asset = makeAsset();
    $vendor = complianceVendor(['status' => Vendor::STATUS_BLACKLISTED]);

    expect($vendor->isDispatchable())->toBeFalse();
    expect(fn () => externalCmWorkOrder($asset->id, $vendor->id))->toThrow(DomainException::class);
});

it('blocks dispatching a vendor whose COI has lapsed', function () {
    $asset = makeAsset();
    $vendor = complianceVendor(['coi_expires_at' => now()->subDay()->toDateString()]);

    expect($vendor->isDispatchable())->toBeFalse();
    expect(fn () => externalCmWorkOrder($asset->id, $vendor->id))->toThrow(DomainException::class);
});

it('allows a vendor with no COI recorded (v1 does not force a cert on every vendor)', function () {
    $asset = makeAsset();
    $vendor = complianceVendor(['coi_expires_at' => null]);

    expect($vendor->isDispatchable())->toBeTrue();
    $wo = externalCmWorkOrder($asset->id, $vendor->id);
    expect($wo->vendor_id)->toBe($vendor->id);
});

it('excludes non-dispatchable vendors from the assignable scope + keeps the current one in options (flagged)', function () {
    $active = complianceVendor(['coi_expires_at' => now()->addYear()->toDateString()]);
    $blacklisted = complianceVendor(['status' => Vendor::STATUS_BLACKLISTED]);
    $expired = complianceVendor(['coi_expires_at' => now()->subDay()->toDateString()]);
    $inactive = complianceVendor(['status' => Vendor::STATUS_INACTIVE]);

    $assignable = Vendor::assignable()->pluck('id');
    expect($assignable->contains($active->id))->toBeTrue()
        ->and($assignable->contains($blacklisted->id))->toBeFalse()
        ->and($assignable->contains($expired->id))->toBeFalse()
        ->and($assignable->contains($inactive->id))->toBeFalse();

    // An edit form still shows the currently-assigned vendor even if it's since become non-compliant.
    $opts = Vendor::assignableOptions($expired->id);
    expect($opts)->toHaveKey($expired->id)
        ->and($opts[$expired->id])->toContain('⚠');
});

it('does not retroactively block an existing order when the vendor later lapses', function () {
    $asset = makeAsset();
    $vendor = complianceVendor(['coi_expires_at' => now()->addYear()->toDateString()]);
    $wo = externalCmWorkOrder($asset->id, $vendor->id);

    // The vendor's insurance lapses AFTER assignment.
    $vendor->update(['coi_expires_at' => now()->subDay()->toDateString()]);

    // A save that doesn't touch vendor_id must NOT be blocked (the guard is assignment-time only).
    $wo->update(['title' => 'Renamed job']);
    expect($wo->fresh()->title)->toBe('Renamed job');
});

it('keeps the COI collection on the private disk', function () {
    $vendor = complianceVendor();
    // registerMediaCollections declares useDisk('local') — MediaPrivacyConformanceTest enforces it.
    $vendor->registerMediaCollections();
    $collection = collect($vendor->getRegisteredMediaCollections())->firstWhere('name', Vendor::COI_COLLECTION);
    expect($collection?->diskName)->toBe('local');
});
