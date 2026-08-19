<?php

use App\Enums\PartyType;
use App\Filament\Admin\RelationManagers\UnitOwnershipChargesRelationManager;
use App\Filament\Admin\Resources\UnitOwnerships\UnitOwnershipResource;
use App\Models\Charge;
use App\Models\UnitOwnership;
use App\Services\BillUnitOwnershipsService;
use Carbon\CarbonImmutable;

/**
 * A unit ownership can be given an assessment schedule, and the run says so when it has none.
 *
 * **F-01, pre-staging QA 2026-08-19.** `BillUnitOwnershipsService` bills an ownership from its
 * `charges` rows and skips it when there are none — and no surface in the application created such
 * a row. `UnitOwnershipResource` had no relation managers, its form has no repeater,
 * `ChargeScheduleRelationManager` is mounted only on `LeaseResource`, and `ChargeImporter` resolves
 * a `lease_reference` only. The only ownerships with a schedule were the ones `DemoSeeder` wrote
 * directly.
 *
 * So an operator registered a sold unit, the ownership read `handed_over`, `isBillableForPeriod()`
 * returned true — and every month the run reported it as an unremarkable `skipped`. Every owner
 * onboarded through the panel went un-billed, permanently and silently.
 *
 * This is the third instance of the pattern the project has already named twice (the un-scheduled
 * assessment run itself; `RemeasureUnitService` before the Remeasure action). `ServiceReachability`
 * proves a SERVICE can be started; nothing proved the DATA it needs can be created — which is the
 * gap this test stands in for.
 *
 * Two guards, both here because they fail independently:
 *  1. the screen exists and is gated;
 *  2. the run distinguishes "nothing to bill" from "misconfigured".
 */
beforeEach(function () {
    seedRoles();

    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset, ['code' => 'C-90']);
    $this->owner = makeTenant(['party_type' => PartyType::UnitOwner->value]);

    $this->ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $this->unit->id,
        'tenant_id' => $this->owner->id,
        'tenure_type' => 'freehold',
        'status' => 'handed_over',
        'assessment_basis' => 'area',
        'ownership_share_pct' => 100,
        'started_at' => '2026-01-01',
        'handover_date' => '2026-01-01',
        'payment_terms_days' => 15,
        'currency' => 'EGP',
    ]);
});

it('mounts an assessment schedule on the ownership resource', function () {
    // The reachability half. Without this the service below has nothing to read, whatever it does.
    expect(UnitOwnershipResource::getRelations())
        ->toContain(UnitOwnershipChargesRelationManager::class);
});

it('reports a handed-over ownership with no schedule as unconfigured, not skipped', function () {
    $period = CarbonImmutable::parse('2026-09-01');

    $stats = app(BillUnitOwnershipsService::class)->runForPeriod($period, $this->asset->id);

    // The distinction IS the fix. `{"considered":8,"created":6,"skipped":2}` reads like success
    // while two owners go un-billed; a separate counter is what puts it in front of someone.
    expect($stats)->toHaveKey('unconfigured')
        ->and($stats['unconfigured'])->toBe(1)
        ->and($stats['created'])->toBe(0);
});

it('bills the owner once a schedule exists', function () {
    Charge::create([
        'unit_ownership_id' => $this->ownership->id,
        'name' => 'صيانة',
        'type' => 'service_charge',
        'amount' => 2500,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => true,
        'vat_rate' => null,
        'start_date' => '2026-01-01',
        'is_active' => true,
    ]);

    $period = CarbonImmutable::parse('2026-09-01');
    $invoice = app(BillUnitOwnershipsService::class)
        ->billOne($this->ownership->fresh(), $period, $period->endOfMonth());

    expect($invoice)->not->toBeNull()
        ->and((float) $invoice->subtotal)->toBe(2500.0)
        ->and((int) $invoice->tenant_id)->toBe($this->owner->id);

    // …and it stops being reported as misconfigured.
    $stats = app(BillUnitOwnershipsService::class)->runForPeriod($period, $this->asset->id);
    expect($stats['unconfigured'])->toBe(0);
});

it('refuses two overlapping rows on an OWNERSHIP schedule, not only on a lease', function () {
    // `assertNoScheduleOverlap()` returned early on `blank($lease_id)`, so an ownership's schedule
    // was exempt from the one guard that stops a charge being billed twice. Unreachable while
    // module 37 had no schedule screen; adding one is what made it reachable.
    $row = fn (string $from) => [
        'unit_ownership_id' => $this->ownership->id,
        'name' => 'صيانة',
        'type' => 'service_charge',
        'amount' => 2500,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => true,
        'vat_rate' => null,
        'start_date' => $from,
        'is_active' => true,
    ];

    Charge::create($row('2026-01-01'));

    expect(fn () => Charge::create($row('2026-06-01')))->toThrow(DomainException::class);

    // The control: a one-off legitimately shares a month with a recurring row, and must still pass.
    expect(fn () => Charge::create([
        'unit_ownership_id' => $this->ownership->id,
        'name' => 'One-off levy',
        'type' => 'other',
        'amount' => 500,
        'currency' => 'EGP',
        'frequency' => 'one_time',
        'vat_applicable' => false,
        'vat_rate' => null,
        'start_date' => '2026-06-01',
        'is_active' => true,
    ]))->not->toThrow(DomainException::class);
});
