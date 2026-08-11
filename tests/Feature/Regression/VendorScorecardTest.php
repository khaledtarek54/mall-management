<?php

use App\Models\MaintenanceWorkOrder;
use App\Models\Vendor;
use App\Services\Reports\VendorScorecardService;
use Carbon\CarbonImmutable;

/**
 * Vendor scorecards — how each vendor actually performed, from data already recorded.
 *
 * Nothing here is new information; the gap was that none of it was ever brought together per vendor,
 * so "who is any good" was answered from memory at renewal time.
 *
 * The tests that matter are the ones about **absence**: a vendor who never acknowledged a job must
 * not average zero hours and read as instant, and a vendor with no activity must not appear as a row
 * of zeroes. Both are ways a report flatters the wrong people.
 */
function scorecardVendor(string $name = 'Cool Air'): Vendor
{
    return Vendor::create(['name' => $name.' '.uniqid(), 'status' => Vendor::STATUS_ACTIVE]);
}

function scorecardOrder(Vendor $vendor, int $assetId, array $attrs = []): MaintenanceWorkOrder
{
    // `created_at` is not mass-assignable, so a fixture that passes it in the create array is
    // silently ignored and every order lands at "now" — which is how the first run of these tests
    // produced a NEGATIVE response time. Stamped after the fact instead.
    $createdAt = $attrs['created_at'] ?? null;
    unset($attrs['created_at']);

    $order = MaintenanceWorkOrder::create(array_merge([
        'asset_id' => $assetId,
        'vendor_id' => $vendor->id,
        'work_order_type' => 'cm',
        'execution_type' => 'external',
        'title' => 'Fix it',
        'description' => 'Not cooling',
        'category' => 'hvac',
        'priority' => 'medium',
        'status' => 'open',
        'scheduled_for' => now()->toDateString(),
    ], $attrs));

    if ($createdAt !== null) {
        $order->forceFill(['created_at' => $createdAt])->saveQuietly();
    }

    return $order->refresh();
}

beforeEach(function () {
    $this->asset = makeAsset();
    $this->from = CarbonImmutable::now()->subDays(30);
    $this->to = CarbonImmutable::now();
});

it('counts the work, the completions and what is still open', function () {
    $vendor = scorecardVendor();
    scorecardOrder($vendor, $this->asset->id, ['completed_at' => now()->subDay()]);
    scorecardOrder($vendor, $this->asset->id);

    $row = app(VendorScorecardService::class)->for($this->from, $this->to)->firstWhere('vendor.id', $vendor->id);

    expect($row['work_orders'])->toBe(2)
        ->and($row['completed'])->toBe(1)
        ->and($row['open'])->toBe(1);
});

it('reports NO response time rather than zero when a vendor never acknowledged', function () {
    // The failure this guards: averaging a missing stamp as zero makes the worst vendor look
    // instant. "Never picked it up" and "picked it up immediately" are opposite answers.
    $vendor = scorecardVendor();
    scorecardOrder($vendor, $this->asset->id); // never acknowledged

    $row = app(VendorScorecardService::class)->for($this->from, $this->to)->firstWhere('vendor.id', $vendor->id);

    expect($row['avg_response_hours'])->toBeNull();
});

it('averages response time over the jobs that were acknowledged', function () {
    $vendor = scorecardVendor();
    $created = now()->subDays(3);

    scorecardOrder($vendor, $this->asset->id, [
        'created_at' => $created,
        'acknowledged_at' => $created->copy()->addHours(2),
    ]);
    scorecardOrder($vendor, $this->asset->id, [
        'created_at' => $created,
        'acknowledged_at' => $created->copy()->addHours(4),
    ]);

    $row = app(VendorScorecardService::class)->for($this->from, $this->to)->firstWhere('vendor.id', $vendor->id);

    expect($row['avg_response_hours'])->toBe(3.0);
});

it('counts a breach whether or not anyone penalised it', function () {
    // A vendor is not owed the benefit of a breach nobody chased — the SLA miss and the penalty are
    // different facts, and the report shows both.
    $vendor = scorecardVendor();

    scorecardOrder($vendor, $this->asset->id, [
        'target_resolution_at' => now()->subDays(2),
        'completed_at' => now()->subDay(),          // late
    ]);
    scorecardOrder($vendor, $this->asset->id, [
        'target_resolution_at' => now()->subDay(),  // still open, already past target
    ]);
    scorecardOrder($vendor, $this->asset->id, [
        'target_resolution_at' => now()->addDays(3),
        'completed_at' => now(),                    // on time
    ]);

    $row = app(VendorScorecardService::class)->for($this->from, $this->to)->firstWhere('vendor.id', $vendor->id);

    expect($row['sla_breaches'])->toBe(2)
        ->and($row['penalties_applied'])->toBe(0);
});

it('leaves a vendor with no activity off the report entirely', function () {
    // "No jobs" is not a performance record. Padding the report with zero rows buries the vendors it
    // is actually about.
    $busy = scorecardVendor('Busy');
    scorecardVendor('Idle');
    scorecardOrder($busy, $this->asset->id);

    $rows = app(VendorScorecardService::class)->for($this->from, $this->to);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['vendor']->id)->toBe($busy->id);
});

it('counts only the window asked for', function () {
    $vendor = scorecardVendor();
    scorecardOrder($vendor, $this->asset->id, ['created_at' => now()->subDays(200)]);

    expect(app(VendorScorecardService::class)->for($this->from, $this->to))->toHaveCount(0);
});

it('surfaces lapsed compliance and whether the vendor may be dispatched at all', function () {
    $vendor = scorecardVendor();
    scorecardOrder($vendor, $this->asset->id);

    $row = app(VendorScorecardService::class)->for($this->from, $this->to)->firstWhere('vendor.id', $vendor->id);

    // No documents on file yet — the count is 0 and the vendor is dispatchable. The value of the
    // column is that it sits beside the performance figures at renewal time.
    expect($row['expired_documents'])->toBe(0)
        ->and($row['dispatchable'])->toBeTrue();
});
