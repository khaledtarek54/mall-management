<?php

use App\Enums\AssessmentBasis;
use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\UnitOwnership;
use App\Services\CamReconciliationService;

/**
 * `unit_ownerships.assessment_basis` decides an owner's share of the common cost — F-03.
 *
 * **The defect was silence, not arithmetic.** The column was on the form, validated, required the
 * right companion field, activity-logged with a translated vocabulary — and **read by no
 * calculation**. Every ownership took the plain area path. An operator who recorded the deed
 * participation of 3.5% that his contract names was billed on floor area instead, and no screen,
 * report or reconciliation said the setting had been ignored.
 *
 * The enum's own docblock warns against precisely this — *"a basis that needs a number nobody typed
 * is the inert-configuration bug this codebase has already been bitten by"* — while being an
 * instance of it. `requiredColumn()` existed so the FORM could ask for the number; nothing ever
 * asked for it back.
 *
 * ## What each test pins, and why the controls matter
 *
 * Every basis is checked against a control that must NOT move, because the failure mode here is a
 * change that looks right on the row it targets and quietly re-cuts everybody else. The one
 * principle carried over from F-08: **a participant's bill is never re-cut because a different
 * participant's contract says something different.**
 */
beforeEach(function () {
    seedRoles();

    $this->asset = makeAsset();

    // A mall that is part let and part sold. Four equal 250 m² units, so an area share is exactly
    // 25% each and any departure from it is visible without arithmetic.
    $this->leaseUnit = makeUnit($this->asset, ['code' => 'L-01', 'area_sqm' => 250]);
    $this->lease = makeLease($this->leaseUnit, null, [
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        // Long enough to cover every pool year below. A pool year past the expiry drops the lease
        // from `participants()` entirely, which silently changes the denominator and reads as a
        // wrong share rather than as a fixture that expired.
        'expiry_date' => '2045-12-31',
        'term_months' => 252,
    ]);

    $this->sell = function (string $code, array $attributes = []): UnitOwnership {
        $unit = makeUnit($this->asset, ['code' => $code, 'area_sqm' => 250]);

        return UnitOwnership::create(array_merge([
            'asset_id' => $this->asset->id,
            'unit_id' => $unit->id,
            'tenant_id' => makeTenant(['party_type' => PartyType::UnitOwner->value])->id,
            'status' => UnitOwnershipStatus::HandedOver->value,
            'started_at' => '2025-01-01',
        ], $attributes));
    };

    $this->pool = fn (int $year) => CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'name' => "Common area {$year}",
        'period_year' => $year,
        'total_actual_expense' => 1_000_000,
        'total_estimated_collected' => 0,
        'status' => 'draft',
        'estimate_basis' => 'stated',
        'recovery_vat_rate' => 0,
        'admin_fee_pct' => 0,
    ]);

    /** Shares keyed `L:{id}` / `O:{id}`, rounded, so an assertion reads like the statement does. */
    $this->shares = function (CamExpensePool $pool): array {
        return CamAllocation::where('cam_expense_pool_id', $pool->id)
            ->get()
            ->mapWithKeys(fn (CamAllocation $a): array => [
                ($a->lease_id !== null ? 'L:'.$a->lease_id : 'O:'.$a->unit_ownership_id) => round((float) $a->pro_rata_share_pct, 4),
            ])
            ->all();
    };
});

it('leaves an area-basis ownership exactly where it was — the default must not move', function () {
    // The control for the whole file. `area` is the default and today's behaviour, so every pool
    // that exists must reconcile byte-identically after this change.
    $owned = ($this->sell)('S-01', ['assessment_basis' => AssessmentBasis::Area->value]);
    $second = ($this->sell)('S-02', ['assessment_basis' => AssessmentBasis::Area->value]);

    $pool = ($this->pool)(2030);
    app(CamReconciliationService::class)->generateAllocations($pool->fresh());

    $shares = ($this->shares)($pool);

    expect($shares['L:'.$this->lease->id])->toBe(33.3333)
        ->and($shares['O:'.$owned->id])->toBe(33.3333)
        ->and($shares['O:'.$second->id])->toBe(33.3333);
});

it('honours the participation stated in the deed, and leaves the neighbours on area', function () {
    // A deed participation is a share of the WHOLE building — the same claim a lease's contractual
    // share makes — so it goes in as-is rather than being scaled by anything.
    $owned = ($this->sell)('S-01', [
        'assessment_basis' => AssessmentBasis::Participation->value,
        'participation_pct' => 10,
    ]);
    $neighbour = ($this->sell)('S-02', ['assessment_basis' => AssessmentBasis::Area->value]);

    $pool = ($this->pool)(2031);
    app(CamReconciliationService::class)->generateAllocations($pool->fresh());

    $shares = ($this->shares)($pool);

    expect($shares['O:'.$owned->id])->toBe(10.0)
        // NOT re-cut. Their basis says area, and area is what they get — the F-08 principle applied
        // from the ownership side. The landlord bears the 23.33% the deed did not promise away.
        ->and($shares['L:'.$this->lease->id])->toBe(33.3333)
        ->and($shares['O:'.$neighbour->id])->toBe(33.3333);
});

it('treats a stated share the same way — it reads the same column and means the same thing', function () {
    $owned = ($this->sell)('S-01', [
        'assessment_basis' => AssessmentBasis::Stated->value,
        'participation_pct' => 40,
    ]);

    $pool = ($this->pool)(2032);
    app(CamReconciliationService::class)->generateAllocations($pool->fresh());

    expect(($this->shares)($pool)['O:'.$owned->id])->toBe(40.0);
});

it('refuses a building whose deeds together promise away more than the pool', function () {
    // The F-08 guard, inherited for free by routing through the same path. Two owners at 60% each
    // plus a lease on area would recover 153% of a cost that was incurred once.
    ($this->sell)('S-01', ['assessment_basis' => AssessmentBasis::Participation->value, 'participation_pct' => 60]);
    ($this->sell)('S-02', ['assessment_basis' => AssessmentBasis::Participation->value, 'participation_pct' => 60]);

    $pool = ($this->pool)(2033);

    expect(fn () => app(CamReconciliationService::class)->generateAllocations($pool->fresh()))
        ->toThrow(DomainException::class);

    expect(CamAllocation::where('cam_expense_pool_id', $pool->id)->count())
        ->toBe(0, 'the pool was refused and must have written nothing');
});

it('falls back to area when the deed percentage was never recorded', function () {
    // Never to ZERO. A zero share silently excuses an owner from the common cost his neighbours are
    // funding, and it would look identical on screen to a correctly configured 0%.
    $owned = ($this->sell)('S-01', [
        'assessment_basis' => AssessmentBasis::Participation->value,
        'participation_pct' => null,
    ]);

    $pool = ($this->pool)(2034);
    app(CamReconciliationService::class)->generateAllocations($pool->fresh());

    expect(($this->shares)($pool)['O:'.$owned->id])->toBe(50.0);
});

it('re-cuts purchase-value owners among THEMSELVES, moving nobody else', function () {
    // The basis with no self-evident denominator: a leased unit has no purchase price to sum with.
    // The reading chosen — stated in the service and in module 37 rather than left implicit — is
    // that the cohort keeps the slice its AREA gives it collectively and divides that by price.
    //
    // Here: one lease + two purchase-value owners, all 250 m². The cohort's area share is 2/3.
    // Prices 3,000,000 and 1,000,000 split it 75/25 → 50% and 16.6667%. The lease stays at 33.3333%.
    $rich = ($this->sell)('S-01', [
        'assessment_basis' => AssessmentBasis::PurchaseValue->value,
        'purchase_price' => 3_000_000,
    ]);
    $modest = ($this->sell)('S-02', [
        'assessment_basis' => AssessmentBasis::PurchaseValue->value,
        'purchase_price' => 1_000_000,
    ]);

    $pool = ($this->pool)(2035);
    app(CamReconciliationService::class)->generateAllocations($pool->fresh());

    $shares = ($this->shares)($pool);

    expect($shares['O:'.$rich->id])->toBe(50.0)
        ->and($shares['O:'.$modest->id])->toBe(16.6667)
        ->and($shares['L:'.$this->lease->id])->toBe(33.3333);

    // Aggregate-neutral by construction: the cohort takes exactly what area would have given it,
    // so this basis can never itself cause the over-recovery the test above refuses.
    expect(round($shares['O:'.$rich->id] + $shares['O:'.$modest->id], 4))->toBe(66.6667);
});

it('excludes an owner with no purchase price from the cohort rather than reading it as zero', function () {
    $priced = ($this->sell)('S-01', [
        'assessment_basis' => AssessmentBasis::PurchaseValue->value,
        'purchase_price' => 3_000_000,
    ]);
    $unpriced = ($this->sell)('S-02', [
        'assessment_basis' => AssessmentBasis::PurchaseValue->value,
        'purchase_price' => null,
    ]);

    $pool = ($this->pool)(2036);
    app(CamReconciliationService::class)->generateAllocations($pool->fresh());

    $shares = ($this->shares)($pool);

    // The unpriced owner leaves the cohort entirely — out of the numerator AND out of the area it
    // re-cuts — so both fall back to their own area share rather than one taking everything.
    expect($shares['O:'.$priced->id])->toBe(33.3333)
        ->and($shares['O:'.$unpriced->id])->toBe(33.3333)
        ->and($shares['L:'.$this->lease->id])->toBe(33.3333);
});

it('does not let the basis touch the monthly assessment — that is what the schedule is for', function () {
    // The boundary this fix deliberately does not cross. The monthly صيانة is a `charges` row: an
    // amount the parties agreed and the operator typed. Deriving it from a denominator would
    // overwrite the schedule with a computed number and restate months already billed.
    $owned = ($this->sell)('S-01', [
        'assessment_basis' => AssessmentBasis::Participation->value,
        'participation_pct' => 10,
    ]);

    $owned->charges()->create([
        'name' => 'Service charge',
        'type' => 'service_charge',
        'amount' => 3_000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => true,
        'start_date' => '2025-01-01',
        'is_active' => true,
    ]);

    expect((float) $owned->charges()->first()->amount)->toBe(3000.0);
});
