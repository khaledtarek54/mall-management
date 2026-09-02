<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\UnitOwnership;
use App\Enums\UnitOwnershipStatus;

/**
 * **CAM had no API surface at all**, while the annual reconciliation puts `cam_recovery` and
 * `cam_admin_fee` lines straight onto the tenant's invoice. The portal has a full resource — list,
 * detail, and the statement PDF — and the app had nothing opposite it, so a large once-a-year
 * charge appeared with no way to see the pool, the share, the estimates already paid, or the
 * statement that explains it. Every one of those is a telephone call.
 */
beforeEach(function () {
    $this->asset = makeAsset();

    $this->tenant = makeTenant();
    $this->other = makeTenant();

    $this->lease = makeLease(makeUnit($this->asset, ['code' => 'A-01']), $this->tenant, ['status' => 'active']);
    $otherLease = makeLease(makeUnit($this->asset), $this->other, ['status' => 'active']);

    $this->pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2025,
        'total_actual_expense' => 100000,
        'total_estimated_collected' => 90000,
        'status' => 'reconciled',
    ]);

    $this->mine = CamAllocation::create([
        'cam_expense_pool_id' => $this->pool->id,
        'lease_id' => $this->lease->id,
        'pro_rata_share_pct' => 60,
        'allocated_amount' => 60000,
        'estimated_paid' => 54000,
        'true_up_amount' => 6000,
        'status' => 'billed',
    ]);

    $this->theirs = CamAllocation::create([
        'cam_expense_pool_id' => $this->pool->id,
        'lease_id' => $otherLease->id,
        'pro_rata_share_pct' => 40,
        'allocated_amount' => 40000,
        'estimated_paid' => 36000,
        'true_up_amount' => 4000,
        'status' => 'billed',
    ]);
});

it('shows the tenant the basis of their own service charge', function () {
    $rows = $this->getJson('/api/v1/me/cam-allocations', apiHeaders($this->tenant))
        ->assertOk()->json('data');

    expect($rows)->toHaveCount(1);

    $row = $rows[0];

    // The three figures are one subtraction the tenant must be able to check: their share of the
    // pool, what they already paid on account across the year, and the difference.
    expect((float) $row['allocatedAmount'])->toBe(60000.0)
        ->and((float) $row['estimatedPaid'])->toBe(54000.0)
        ->and((float) $row['trueUpAmount'])->toBe(6000.0)
        ->and((float) $row['proRataSharePct'])->toBe(60.0)
        // The denominator the share is a percentage OF. Without it "your share is 60%" is a number
        // the tenant cannot check against anything.
        ->and((float) $row['totalActualExpense'])->toBe(100000.0)
        ->and($row['periodYear'])->toBe(2025)
        ->and($row['unit']['code'])->toBe('A-01')
        ->and($row['agreement']['kind'])->toBe('lease');
});

it('never shows another tenant their neighbour\'s share', function () {
    // Paired with the control above: a scope that returned nothing would satisfy this alone.
    $this->getJson("/api/v1/me/cam-allocations/{$this->theirs->id}", apiHeaders($this->tenant))
        ->assertNotFound();

    $this->getJson("/api/v1/me/cam-allocations/{$this->mine->id}", apiHeaders($this->tenant))
        ->assertOk();
});

it('reaches a UNIT OWNER, whose allocation has no lease at all', function () {
    $owner = makeTenant();
    $unit = makeUnit($this->asset, ['code' => 'B-07']);

    $ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $unit->id,
        'tenant_id' => $owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'ownership_share_pct' => 100,
        'purchase_date' => '2025-01-01',
        'handover_date' => '2025-02-01',
        'started_at' => '2025-02-01',
        'currency' => 'EGP',
    ]);

    $allocation = CamAllocation::create([
        'cam_expense_pool_id' => $this->pool->id,
        'unit_ownership_id' => $ownership->id,
        'pro_rata_share_pct' => 10,
        'allocated_amount' => 10000,
        'estimated_paid' => 9000,
        'true_up_amount' => 1000,
        'status' => 'billed',
    ]);

    // The whole reason `ownedBy()` groups an OR: an owner is a CAM participant in his own right,
    // and a scope through `lease` alone returns NOTHING for him — so he was billed a true-up whose
    // basis he could not see. The portal's own resource carries the same note.
    $rows = $this->getJson('/api/v1/me/cam-allocations', apiHeaders($owner))->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($allocation->id)
        // …and the shop resolves from the OWNERSHIP, not from a lease that does not exist.
        ->and($rows[0]['unit']['code'])->toBe('B-07')
        ->and($rows[0]['agreement']['kind'])->toBe('ownership');
});

it('hands the tenant their own service-charge statement, and 404s somebody else\'s', function () {
    $response = $this->get("/api/v1/me/cam-allocations/{$this->mine->id}/statement", apiHeaders($this->tenant))
        ->assertOk();

    // A real PDF, not an empty 200 — the magic bytes are the cheapest proof the service rendered
    // rather than returning an empty string. `streamPdf()` returns a plain response, not a
    // streamed one, so read the content directly.
    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');

    $this->get("/api/v1/me/cam-allocations/{$this->theirs->id}/statement", apiHeaders($this->tenant))
        ->assertNotFound();
});

it('filters by year and by status', function () {
    $older = CamExpensePool::create([
        'asset_id' => $this->asset->id, 'period_year' => 2024,
        'total_actual_expense' => 80000, 'total_estimated_collected' => 80000, 'status' => 'reconciled',
    ]);
    CamAllocation::create([
        'cam_expense_pool_id' => $older->id, 'lease_id' => $this->lease->id,
        'pro_rata_share_pct' => 60, 'allocated_amount' => 48000,
        'estimated_paid' => 48000, 'true_up_amount' => 0, 'status' => 'closed',
    ]);

    expect($this->getJson('/api/v1/me/cam-allocations?period_year=2024', apiHeaders($this->tenant))
        ->assertOk()->json('data'))->toHaveCount(1);

    // `status` is qualified as `cam_allocations.status` in the controller: the query joins nothing
    // today, but `pool` has a `status` column too and an unqualified column is how that breaks.
    expect($this->getJson('/api/v1/me/cam-allocations?status=billed', apiHeaders($this->tenant))
        ->assertOk()->json('data'))->toHaveCount(1);
});
