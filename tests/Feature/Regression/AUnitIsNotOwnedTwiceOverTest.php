<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Models\Tenant;
use App\Models\UnitOwnership;
use App\Services\TransferUnitOwnershipService;
use Carbon\CarbonImmutable;

/**
 * Regression — SW-229. A unit may not be owned more than once over.
 *
 * `UnitOwnership::saving` checked a row's own two dates ("a tenure cannot run backwards") and never
 * its siblings', and there is no unique index that could. `TransferUnitOwnershipService` writes
 * `ended_at = $on->subDay()` so the service never produces an overlap; the register's own
 * Create/Edit form has no such rule, and there is no unit-ownership importer, so the form is THE
 * door.
 *
 * The day is then counted twice, in both directions the money runs:
 *
 *   * `areaSqmForPeriod()` weights m²·days, so a seller ending 1 July beside a buyer starting
 *     1 July gives the unit 366 owner-days of a 365-day year. Against an identical full-year unit
 *     that is 50.068% of a CAM pool versus 49.932% — the row's own measurement, reproduced below
 *     as arithmetic rather than asserted from a pool, because it is the m²·days that are wrong and
 *     the pool merely divides them.
 *   * `BillUnitOwnershipsService` prorates each tenure over the days it held, so both owners are
 *     billed for the shared day.
 *
 * **NOT "no two tenures may overlap".** Overlapping is the ordinary state of a CO-OWNED unit —
 * `ownership_share_pct` exists for it and `DemoSeeder` seeds a 60/40 pair — and a `contracted` sale
 * running alongside the current owner's live tenure is the ordinary shape of a pending resale. The
 * rule is that the shares IN FORCE on any one day must add up to 100% or less, over the states that
 * took possession. Each of those is a control here, because a naive overlap ban passes every
 * refusal assertion in this file while breaking all three.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'TWO']);
    $this->unit = makeUnit($this->asset, ['area_sqm' => 100]);

    $this->buyer = fn (): Tenant => makeTenant(['party_type' => PartyType::UnitOwner->value]);

    $this->own = fn (?string $from, ?string $to, float $share = 100, ?string $status = null): UnitOwnership => UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $this->unit->id,
        'tenant_id' => ($this->buyer)()->id,
        'status' => $status ?? UnitOwnershipStatus::HandedOver->value,
        'started_at' => $from,
        'ended_at' => $to,
        'ownership_share_pct' => $share,
    ]);
});

it('refuses a buyer whose tenure opens on the day the seller is still holding', function () {
    ($this->own)('2026-01-01', '2026-07-01');

    expect(fn () => ($this->own)('2026-07-01', null))
        ->toThrow(DomainException::class);

    expect(UnitOwnership::where('unit_id', $this->unit->id)->count())
        ->toBe(1, 'the refusal must not leave the second tenure half-written');
});

it('accepts the resale the day after — the CONTROL, and the arithmetic the guard protects', function () {
    $seller = ($this->own)('2026-01-01', '2026-06-30');
    $buyer = ($this->own)('2026-07-01', null);

    $from = CarbonImmutable::parse('2026-01-01');
    $to = CarbonImmutable::parse('2026-12-31');

    // 181 days + 184 days of a 365-day year, on a 100 m² unit: the unit contributes its own area
    // to the pool exactly once.
    expect(round($seller->areaSqmForPeriod($from, $to) + $buyer->areaSqmForPeriod($from, $to), 4))
        ->toBe(100.0);
});

it('measures what the overlap actually costs, so the refusal is not an opinion', function () {
    $seller = ($this->own)('2026-01-01', '2026-06-30');
    $buyer = ($this->own)('2026-07-01', null);

    // Re-opened by a raw builder update — no model events. That is the legacy shape the guard
    // cannot reach backwards into, and the only way to hold the broken state now that a create
    // cannot produce it. The seller now runs to 1 July, the day the buyer opens: SW-229's own
    // arrangement.
    UnitOwnership::query()->whereKey($seller->getKey())->update(['ended_at' => '2026-07-01']);

    $from = CarbonImmutable::parse('2026-01-01');
    $to = CarbonImmutable::parse('2026-12-31');

    $doubled = round($seller->fresh()->areaSqmForPeriod($from, $to)
        + $buyer->fresh()->areaSqmForPeriod($from, $to), 4);

    // 182 + 184 = 366 owner-days of a 365-day year on a 100 m² unit. Against an identical
    // full-year neighbour that is 100.274 / 200.274 of the pool.
    expect($doubled)->toBe(100.274)
        ->and(round($doubled / ($doubled + 100) * 100, 3))->toBe(50.068)
        ->and(round(100 / ($doubled + 100) * 100, 3))->toBe(49.932);
});

it('still lets two people co-own a unit on the same days', function () {
    // The case a "no overlapping tenures" rule would have broken. 50 + 50 is 100, not 200.
    ($this->own)('2026-01-01', null, share: 50);
    ($this->own)('2026-01-01', null, share: 50);

    expect(UnitOwnership::where('unit_id', $this->unit->id)->count())->toBe(2);
});

it('still lets a sale be CONTRACTED while the current owner is still in possession', function () {
    // A pending resale: the buyer's row exists before handover, and neither bills nor reaches a
    // pool. Only the possession states are counted, which is why this saves.
    ($this->own)('2026-01-01', null);
    ($this->own)('2026-01-01', null, status: UnitOwnershipStatus::Contracted->value);

    expect(UnitOwnership::where('unit_id', $this->unit->id)->count())->toBe(2);
});

it('still lets the transfer service resell a unit', function () {
    // The guard runs on the seller's own update and again on the buyer's create, so the service
    // that produces the correct shape has to survive it — the "#[NeverDeletable] trap" this
    // codebase already records, where a guard breaks the workflow it was meant to protect.
    $seller = ($this->own)('2026-01-01', null);

    app(TransferUnitOwnershipService::class)->transfer(
        $seller,
        ($this->buyer)(),
        CarbonImmutable::parse('2026-07-01'),
    );

    $seller->refresh();
    $bought = UnitOwnership::where('unit_id', $this->unit->id)->whereKeyNot($seller->getKey())->sole();

    expect($seller->ended_at->toDateString())->toBe('2026-06-30')
        ->and($bought->started_at->toDateString())->toBe('2026-07-01');
});

it('does not lock an operator out of a row that was already overlapping', function () {
    // The ESCAPE. A pre-existing overlap must not make either row unsavable — refused only on a
    // write that touches the tenure, so an unrelated edit still goes through and the corrections
    // that clear the overlap are themselves the write the guard evaluates.
    $seller = ($this->own)('2026-01-01', '2026-06-30');
    ($this->own)('2026-07-01', null);

    UnitOwnership::query()->whereKey($seller->getKey())->update(['ended_at' => '2026-07-01']);

    $seller->refresh()->update(['notes' => 'Chased the deed office for the corrected handover date.']);

    expect($seller->fresh()->notes)->toContain('deed office');

    // …and a correction that KEEPS the overlap is still refused, while the one that clears it is
    // accepted — which is what makes the message's escape a real one.
    expect(fn () => $seller->fresh()->update(['ended_at' => '2026-07-02']))
        ->toThrow(DomainException::class);

    $seller->fresh()->update(['ended_at' => '2026-06-30']);

    expect($seller->fresh()->ended_at->toDateString())->toBe('2026-06-30');
});
