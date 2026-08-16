<?php

/*
|--------------------------------------------------------------------------
| A lease's future rent exists somewhere an operator can see it (2026-08-16)
|--------------------------------------------------------------------------
| Three defects, all of which left a contracted increase invisible while the sweep applied it
| anyway — so the rent moved every year and nothing in the system said it would.
|
|  1. **The standard New-lease form projected no ladder at all.** `LeaseCreationService::create()`
|     has always projected the whole term, but that service is reached only from the "Quick new
|     lease" wizard on the list header. The ordinary New lease page runs Eloquent directly and
|     stopped at the three seeded rows — so the same deal produced a different lease depending on
|     which button was used.
|
|  2. **`projectTermEscalations()` refused `fixed_amount` outright**, so even the wizard and a
|     renewal left those leases flat. "+EGP 4,000 a month each year" is an ordinary anchor-tenant
|     term and is exactly as knowable at signing as a percentage — unlike CPI, which has no index
|     feed and must stay unprojected.
|
|  3. **The panel heading then reported "no further steps scheduled"** about that same lease, which
|     is not a hedge but a false statement about the contract.
*/

use App\Filament\Admin\RelationManagers\ChargeScheduleRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Charge;
use App\Models\Lease;
use App\Services\ChargeScheduleService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'HW']);
    $this->unit = makeUnit($this->asset, ['code' => 'HW-01', 'status' => 'vacant', 'area_sqm' => 120]);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(function () {
    Filament::setTenant(null, isQuiet: true);
    CarbonImmutable::setTestNow();
});

/** The panel headline, rendered the way the operator reads it. */
function chargeScheduleHeadingFor(Lease $lease): string
{
    return (string) Livewire::test(ChargeScheduleRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])->instance()->getTableDescription();
}

/** The rent rows of a lease, in date order. */
function rentLadder(Lease $lease): array
{
    return Charge::where('lease_id', $lease->id)
        ->where('type', 'base_rent')
        ->orderBy('start_date')
        ->get()
        ->map(fn (Charge $c) => $c->start_date->format('Y-m-d').' @ '.number_format((float) $c->amount, 2))
        ->all();
}

// ── 1. The form the operator actually uses ─────────────────────────────────────────────────────

it('writes the whole contracted ladder from the standard New lease form, not just the opening row', function () {
    Livewire::test(CreateLease::class)
        ->fillForm([
            'unit_id' => $this->unit->id,
            'tenant_id' => makeTenant()->id,
            'status' => 'active',
            'commencement_date' => '2026-10-01',
            'term_months' => 36,
            'expiry_date' => '2029-09-30',
            'base_rent_monthly' => 100000,
            'service_charge_monthly' => 9000,
            'escalation_type' => 'fixed_percent',
            'escalation_rate' => 10,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $lease = Lease::latest('id')->firstOrFail();

    expect(rentLadder($lease))->toBe([
        '2026-10-01 @ 100,000.00',
        '2027-10-01 @ 110,000.00',
        '2028-10-01 @ 121,000.00',
    ]);
});

// ── 2. An amount clause is as knowable as a percentage one ─────────────────────────────────────

it('projects a fixed-amount ladder — the step is added, and no percent collar touches it', function () {
    $lease = makeLease($this->unit, makeTenant(), [
        'commencement_date' => '2026-10-01',
        'expiry_date' => '2029-09-30',
        'term_months' => 36,
        'base_rent_monthly' => 72000,
        'escalation_type' => 'fixed_amount',
        'escalation_amount' => 4000,
        // A collar stated in percent must be ignored for a step stated in pounds.
        'escalation_floor_rate' => 5,
        'escalation_ceiling_rate' => 6,
    ]);

    app(ChargeScheduleService::class)->setAmount(
        $lease, 'base_rent', 72000, CarbonImmutable::parse('2026-10-01'), ['name' => 'Base Rent'], Charge::ORIGIN_SEED,
    );

    expect(app(ChargeScheduleService::class)->projectTermEscalations($lease->fresh()))->toBeGreaterThan(0)
        ->and(rentLadder($lease))->toBe([
            '2026-10-01 @ 72,000.00',
            '2027-10-01 @ 76,000.00',
            '2028-10-01 @ 80,000.00',
        ]);
});

it('still projects nothing for CPI — there is no index feed to read', function () {
    $lease = makeLease($this->unit, makeTenant(), [
        'commencement_date' => '2026-10-01',
        'expiry_date' => '2029-09-30',
        'base_rent_monthly' => 72000,
        'escalation_type' => 'cpi',
        'escalation_rate' => 7,
    ]);

    expect(app(ChargeScheduleService::class)->projectTermEscalations($lease->fresh()))->toBe(0);
});

// ── 3. The heading tells the truth about all of it ─────────────────────────────────────────────

it('does not call a lease that has not commenced "billing now", nor its opening rent a step', function () {
    CarbonImmutable::setTestNow('2026-08-16');

    $lease = makeLease($this->unit, makeTenant(), [
        'commencement_date' => '2026-10-01',
        'expiry_date' => '2029-09-30',
        'base_rent_monthly' => 72000,
        'escalation_type' => 'none',
        'escalation_rate' => 0,
    ]);

    app(ChargeScheduleService::class)->setAmount(
        $lease, 'base_rent', 72000, CarbonImmutable::parse('2026-10-01'), ['name' => 'Base Rent'], Charge::ORIGIN_SEED,
    );

    $heading = chargeScheduleHeadingFor($lease->fresh());

    expect($heading)->toContain('Not billing yet')
        ->and($heading)->toContain('01/10/2026')
        ->and($heading)->not->toContain('Billing now');
});

it('reads the rent row in force, never the service charge or the levy', function () {
    CarbonImmutable::setTestNow('2026-11-15');

    $lease = makeLease($this->unit, makeTenant(), [
        'commencement_date' => '2026-10-01',
        'expiry_date' => '2029-09-30',
        'base_rent_monthly' => 72000,
        'escalation_type' => 'none',
        'escalation_rate' => 0,
    ]);

    $svc = app(ChargeScheduleService::class);
    $svc->setAmount($lease, 'base_rent', 72000, CarbonImmutable::parse('2026-10-01'), ['name' => 'Base Rent'], Charge::ORIGIN_SEED);
    // A service charge scheduled to rise — it must never be announced as a RENT step.
    $svc->setAmount($lease, 'service_charge', 9000, CarbonImmutable::parse('2026-10-01'), ['name' => 'Service Charge'], Charge::ORIGIN_SEED);
    $svc->setAmount($lease, 'service_charge', 11000, CarbonImmutable::parse('2027-01-01'), ['name' => 'Service Charge'], Charge::ORIGIN_MANUAL);

    $heading = chargeScheduleHeadingFor($lease->fresh());

    expect($heading)->toContain('72,000.00')
        ->and($heading)->not->toContain('11,000.00')
        ->and($heading)->toContain('no further steps');
});

it('names a contracted amount increase that is not yet in the schedule, instead of denying it', function () {
    CarbonImmutable::setTestNow('2026-11-15');

    $lease = makeLease($this->unit, makeTenant(), [
        'commencement_date' => '2026-10-01',
        'expiry_date' => '2029-09-30',
        'base_rent_monthly' => 72000,
        'escalation_type' => 'fixed_amount',
        'escalation_amount' => 4000,
    ]);

    app(ChargeScheduleService::class)->setAmount(
        $lease, 'base_rent', 72000, CarbonImmutable::parse('2026-10-01'), ['name' => 'Base Rent'], Charge::ORIGIN_SEED,
    );

    $heading = chargeScheduleHeadingFor($lease->fresh());

    expect($heading)->toContain('EGP 4,000.00')
        ->and($heading)->toContain('01/10/2027')
        ->and($heading)->not->toContain('no further steps');
});
