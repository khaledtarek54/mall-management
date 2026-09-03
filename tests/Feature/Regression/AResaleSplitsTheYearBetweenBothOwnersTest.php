<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\UnitOwnership;
use App\Services\CamReconciliationService;
use App\Services\TransferUnitOwnershipService;
use Carbon\CarbonImmutable;

/**
 * SW-220 — a mid-year resale billed the buyer for the whole year and the seller for nothing, and a
 * co-owned unit counted twice.
 *
 * Two defects, one cause: the ownership branch of the CAM reconciliation asked a POINT-IN-TIME
 * question and then used a FLAT area.
 *
 *  - **Membership.** `covering(31 December)` is true only of whoever holds the unit on the last day
 *    of the year. The lease branch beside it was deliberately changed to an OVERLAP on 2026-08-17,
 *    because a tenant who left in July still occupied common area for six months of the year being
 *    reconciled — and the ownership branch was left asking the older question. So the seller was not
 *    a participant at all.
 *  - **Weight.** `areaForPeriod()` returned `unit->area_sqm` for an ownership: the unit's CURRENT
 *    measurement, un-weighted by how long it was owned and un-weighted by `ownership_share_pct`,
 *    which the MONTHLY assessment has always applied (`BillUnitOwnershipsService`) and this did not.
 *    So the buyer carried twelve months of common
 *    cost for six months of ownership, and two 50% co-owners each carried a full unit.
 *
 * It moved money between the parties in the pool while the pool still tied out — Σ allocated =
 * actual expense holds however the shares are distributed, which is why nothing caught it.
 *
 * The fix keeps each participant answering for its own area: `Lease::totalAreaSqmForPeriod()` and
 * now `UnitOwnership::areaSqmForPeriod()`, both m²·days over the period's days, so the two
 * weightings compose without rounding in between.
 */
beforeEach(function () {
    $this->year = 2026;
    $this->start = CarbonImmutable::create($this->year, 1, 1);
    $this->end = $this->start->endOfYear()->startOfDay();

    // GLA deliberately ≠ the occupied sum (200), so these cases pin the `occupied` basis the pool
    // actually defaults to rather than passing identically under either.
    $this->asset = makeAsset(['code' => 'RSL', 'leasable_area_sqm' => 250]);

    // Two identical 100 m² units: one LET all year, one SOLD and then re-sold mid-year.
    $this->letUnit = makeUnit($this->asset, ['area_sqm' => 100]);
    $this->soldUnit = makeUnit($this->asset, ['area_sqm' => 100]);

    $this->lease = makeLease($this->letUnit, null, [
        'commencement_date' => '2025-01-01', 'expiry_date' => '2027-12-31', 'status' => 'active',
    ]);

    $this->pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => $this->year,
        'pool_code' => CamExpensePool::CODE_CAM,
        'name' => 'Common area '.$this->year,
        'status' => 'draft',
        'total_actual_expense' => 100000,
        'total_estimated_collected' => 0,
    ]);
});

function ownerOf(int $unitId, ?string $from, ?string $to, float $sharePct = 100): UnitOwnership
{
    return UnitOwnership::create([
        'asset_id' => test()->asset->id,
        'unit_id' => $unitId,
        'tenant_id' => makeTenant(['party_type' => PartyType::UnitOwner->value])->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => $from,
        'ended_at' => $to,
        'ownership_share_pct' => $sharePct,
    ]);
}

it('splits a resale year between the seller and the buyer', function () {
    // **Through the real transfer service.** The first version of this test wrote the seller as
    // `handed_over` with an `ended_at` — a state nothing produces — and so was green over a path
    // production never takes. `TransferUnitOwnershipService` sets the seller `Transferred`, which
    // is what the reconciliation's `where('status', HandedOver)` was excluding BEFORE the tenure was
    // ever consulted: the fix without this is not a fix, and measured, it moved the seller's share
    // onto the shop next door (50,000 → 66,484.50) instead of onto the seller.
    $seller = ownerOf($this->soldUnit->id, '2025-01-01', null);

    app(TransferUnitOwnershipService::class)->transfer(
        $seller,
        makeTenant(['party_type' => PartyType::UnitOwner->value]),
        CarbonImmutable::create($this->year, 7, 1),
    );

    $seller = $seller->fresh();
    $buyer = UnitOwnership::where('unit_id', $this->soldUnit->id)->whereKeyNot($seller->id)->firstOrFail();

    expect($seller->status)->toBe(UnitOwnershipStatus::Transferred)
        ->and($seller->ended_at->toDateString())->toBe($this->year.'-06-30');

    app(CamReconciliationService::class)->generateAllocations($this->pool);

    $allocations = CamAllocation::where('cam_expense_pool_id', $this->pool->id)->get();
    $byOwner = fn (UnitOwnership $o) => $allocations->firstWhere('unit_ownership_id', $o->id);

    // Measured before the fix: the seller had NO allocation row at all, and the buyer carried the
    // whole 50,000 — twelve months of common cost for six months of ownership.
    expect($byOwner($seller))->not->toBeNull('the seller sold mid-year and was left out of the pool entirely')
        ->and($byOwner($buyer))->not->toBeNull();

    $sellerShare = round((float) $byOwner($seller)->allocated_amount, 2);
    $buyerShare = round((float) $byOwner($buyer)->allocated_amount, 2);

    // **The exact split, not merely "both non-zero and one smaller"** — which a 1%/99% share would
    // have satisfied. 2026 is 365 days; the seller held 1 Jan–30 Jun (181) and the buyer 1 Jul–31 Dec
    // (184), against the unit's 50,000 half of the mall.
    expect($sellerShare)->toEqual(24794.50)
        ->and($buyerShare)->toEqual(25205.50)
        ->and(round($sellerShare + $buyerShare, 2))->toEqual(50000.0);

    // And the tenant next door is untouched: half the mall, all year.
    $tenant = $allocations->firstWhere('lease_id', $this->lease->id);

    expect(round((float) $tenant->allocated_amount, 2))->toEqual(50000.0);
});

it('counts a co-owned unit once, not once per co-owner', function () {
    // `ownership_share_pct` is applied by the MONTHLY assessment and was not applied here, so the
    // annual true-up disagreed with the monthly bill: each half-owner contributed
    // the unit's FULL area — to their own share AND to the `occupied` denominator, which is summed
    // from the same method.
    $a = ownerOf($this->soldUnit->id, '2025-01-01', null, sharePct: 50);
    $b = ownerOf($this->soldUnit->id, '2025-01-01', null, sharePct: 50);

    app(CamReconciliationService::class)->generateAllocations($this->pool);

    $allocations = CamAllocation::where('cam_expense_pool_id', $this->pool->id)->get();

    $shareOf = fn (UnitOwnership $o) => round((float) $allocations->firstWhere('unit_ownership_id', $o->id)?->allocated_amount, 2);

    // Measured before the fix: 33,333.33 each and 33,333.33 for the tenant — the co-owned unit
    // counted as 200 m² of a 300 m² mall, so the tenant next door was under-charged by a third.
    expect($shareOf($a))->toEqual(25000.0)
        ->and($shareOf($b))->toEqual(25000.0)
        ->and(round((float) $allocations->firstWhere('lease_id', $this->lease->id)->allocated_amount, 2))
        ->toEqual(50000.0);
});

it('still ties out — the split moves money between parties, never out of the pool', function () {
    // The control, and the reason neither defect was caught: Σ allocated = the actual expense by
    // construction on the occupied basis, so the books-check was satisfied throughout while the
    // distribution between the parties was wrong.
    ownerOf($this->soldUnit->id, '2025-01-01', '2026-06-30');
    ownerOf($this->soldUnit->id, '2026-07-01', null);

    app(CamReconciliationService::class)->generateAllocations($this->pool);

    expect(round((float) CamAllocation::where('cam_expense_pool_id', $this->pool->id)->sum('allocated_amount'), 2))
        ->toEqual(100000.0)
        ->and(round((float) $this->pool->fresh()->landlord_unrecovered, 2))->toEqual(0.00);
});

it('leaves an owner whose tenure ended BEFORE the year out of the pool', function () {
    // The overlap must widen membership to the year, not to everybody who ever owned the unit.
    $old = ownerOf($this->soldUnit->id, '2023-01-01', '2025-03-31');
    $current = ownerOf($this->soldUnit->id, '2025-04-01', null);

    app(CamReconciliationService::class)->generateAllocations($this->pool);

    $allocations = CamAllocation::where('cam_expense_pool_id', $this->pool->id)->get();

    expect($allocations->firstWhere('unit_ownership_id', $old->id))->toBeNull()
        ->and(round((float) $allocations->firstWhere('unit_ownership_id', $current->id)->allocated_amount, 2))
        ->toEqual(50000.0);

    // **And asserted on the SCOPE itself**, because the absence of a row proves nothing on its own:
    // an out-of-period owner also returns 0 m², and the allocator skips a zero share anyway. Mutate
    // `scopeOverlapping()` to match everything and the assertions above stay green.
    $inPool = UnitOwnership::query()
        ->overlapping($this->start, $this->end)
        ->pluck('id');

    expect($inPool)->toContain($current->id)
        ->and($inPool)->not->toContain($old->id);
});

it('splits a DEED share across a resale too, instead of promising it twice', function () {
    // **The half that would have broken the whole reconciliation** once the seller was let back in.
    // A `participation` share is a percentage of the BUILDING, and `TransferUnitOwnershipService`
    // copies `participation_pct` verbatim to the buyer — so both tenures claimed the full deed.
    // Measured before the weighting: `projectedTotalShare` reached **150%** and the pool was REFUSED
    // outright by the over-recovery guard, which `autoTrueUpForYear()` swallows into an ops log —
    // so the scheduled `cam:reconcile` would have gone quiet on that mall for good.
    //
    // The area basis has always been m²·days; this makes the deed basis agree, through one
    // definition of "how much of the period was this owner's".
    $letOwner = ownerOf($this->letUnit->id, '2025-01-01', null);
    $letOwner->update(['assessment_basis' => 'participation', 'participation_pct' => 50]);

    $seller = ownerOf($this->soldUnit->id, '2025-01-01', null);
    $seller->update(['assessment_basis' => 'participation', 'participation_pct' => 50]);

    app(TransferUnitOwnershipService::class)->transfer(
        $seller->fresh(),
        makeTenant(['party_type' => PartyType::UnitOwner->value]),
        CarbonImmutable::create($this->year, 7, 1),
    );

    // The lease on the other unit would otherwise share the pool with these deeds; this case is
    // about the deeds adding to 100, so it is the only participant set that matters.
    $this->lease->update(['status' => 'cancelled']);

    app(CamReconciliationService::class)->generateAllocations($this->pool);

    $rows = CamAllocation::where('cam_expense_pool_id', $this->pool->id)->get();

    // Three tenures, two deeds, 100% of the pool between them — not 150%.
    expect(round((float) $rows->sum('allocated_amount'), 2))->toEqual(100000.0)
        ->and($rows)->toHaveCount(3);

    $seller = $seller->fresh();
    $buyer = UnitOwnership::where('unit_id', $this->soldUnit->id)->whereKeyNot($seller->id)->firstOrFail();

    $shareOf = fn (UnitOwnership $o) => round((float) $rows->firstWhere('unit_ownership_id', $o->id)?->allocated_amount, 2);

    // The unsold deed keeps its whole 50%; the sold one is split by days held.
    expect($shareOf($letOwner))->toEqual(50000.0)
        ->and(round($shareOf($seller) + $shareOf($buyer), 2))->toEqual(50000.0)
        ->and($shareOf($seller))->toBeLessThan($shareOf($buyer));
});
