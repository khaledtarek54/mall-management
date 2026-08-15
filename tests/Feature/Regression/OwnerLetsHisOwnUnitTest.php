<?php

use App\Enums\PartyType;
use App\Enums\UnitManagementMode;
use App\Enums\UnitOwnershipStatus;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\UnitOwnership;
use App\Services\BillUnitOwnershipsService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

/**
 * An owner lets his own unit — Yardi's lessee-under-owner record (plan 08 §5.6).
 *
 * In Voyager Condo/Co-Op & HOA, a lessee in a sold unit is a sub-record under the OWNER's unit. Two
 * consequences, and this file exists for both:
 *
 *   1. **The owner remains liable for the assessment.** Letting the unit does not move the service
 *      charge onto the tenant, and it does not suspend it.
 *   2. **The lessee is a real occupant.** Access, violations, SLA, fit-out and every mall rule apply
 *      to a tenant the mall did not sign. Owner of record is not occupant of record.
 *
 * There is deliberately NO `revenue_mode` flag on the lease. Whether the operator collects the rent
 * is a term of the management agreement — held on the ownership — and a tenancy the mall does not
 * bill rent on simply carries no rent charge, which the billing engine already handles by raising
 * nothing. That last part is asserted below rather than assumed.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'SLF']);
    $this->unit = makeUnit($this->asset, ['area_sqm' => 60, 'status' => 'occupied']);

    $this->owner = makeTenant(['party_type' => PartyType::UnitOwner->value, 'name' => 'Ashraf El-Gindy']);

    $this->ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $this->unit->id,
        'tenant_id' => $this->owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'management_mode' => UnitManagementMode::SelfLet->value,
        'started_at' => '2026-01-01',
    ]);

    Charge::create([
        'unit_ownership_id' => $this->ownership->id,
        'name' => 'Service charge', 'type' => 'service_charge',
        'amount' => 3300, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'is_active' => true, 'start_date' => '2026-01-01',
    ]);

    // The OWNER's tenant. Recorded by the mall, signed by the owner — so it carries no rent charge.
    $this->retailer = makeTenant(['name' => 'Owner\'s Retailer']);
    $this->lease = makeLease($this->unit, $this->retailer, [
        'commencement_date' => '2026-01-01', 'expiry_date' => '2027-12-31', 'status' => 'active',
        'unit_ownership_id' => $this->ownership->id,
    ]);
});

it('records the tenancy under the ownership, both ways round', function () {
    expect($this->lease->isUnderOwnership())->toBeTrue()
        ->and($this->lease->unitOwnership->is($this->ownership))->toBeTrue()
        ->and($this->ownership->leases->contains($this->lease))->toBeTrue()
        // ...and an ordinary lease of space the mall still owns is untouched.
        ->and(makeLease(makeUnit($this->asset))->isUnderOwnership())->toBeFalse();
});

it('keeps the assessment on the OWNER, not on his tenant', function () {
    app(BillUnitOwnershipsService::class)->runForPeriod(CarbonImmutable::parse('2026-03-01'), $this->asset->id);

    $assessment = Invoice::whereNotNull('unit_ownership_id')->firstOrFail();

    // Yardi's rule, and the one an operator would most expect to get wrong: letting the unit does
    // not hand the service charge to the occupant.
    expect($assessment->tenant_id)->toBe($this->owner->id)
        ->and($assessment->tenant_id)->not->toBe($this->retailer->id)
        ->and(round((float) $assessment->subtotal, 2))->toBe(3300.00);
});

it('bills the owner\'s tenant no rent, without needing a flag to say so', function () {
    // The reason `revenue_mode` was not built. The lease carries no rent charge, and the monthly
    // run already raises nothing for a lease with no applicable charges — so a predicate keyed on a
    // flag would have been dead code.
    $stats = app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-03-01'), $this->asset->id);

    expect(Invoice::where('lease_id', $this->lease->id)->count())->toBe(0)
        ->and($stats['created'])->toBe(0);

    // Paired control: the SAME run does bill an ordinary lease that has a rent charge, so the zero
    // above is about this tenancy and not about the run doing nothing at all.
    $ordinary = makeLease(makeUnit($this->asset), null, [
        'commencement_date' => '2026-01-01', 'expiry_date' => '2027-12-31', 'status' => 'active',
    ]);
    Charge::create([
        'lease_id' => $ordinary->id, 'name' => 'Base rent', 'type' => 'base_rent',
        'amount' => 9000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'is_active' => true, 'start_date' => '2026-01-01',
    ]);

    app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-04-01'), $this->asset->id);

    expect(Invoice::where('lease_id', $ordinary->id)->count())->toBe(1);
});

it('leaves the owner\'s tenant a real occupant of the mall', function () {
    // The half that must NOT change, and the easier mistake: a self-let tenant is governed like any
    // other. The lease is active, it holds the unit, and the unit reads occupied — which is what
    // violations, SLA, fit-out and the occupancy figure all key on.
    expect($this->lease->status)->toBe('active')
        ->and($this->lease->isActive())->toBeTrue()
        ->and($this->unit->fresh()->status)->toBe('occupied')
        ->and($this->unit->isActivelyLeased())->toBeTrue()
        // ...and the unit is simultaneously owned. Both are true at once, which is the whole point.
        ->and($this->unit->isOwned())->toBeTrue();
});
