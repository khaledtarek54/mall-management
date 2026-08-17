<?php

/*
|--------------------------------------------------------------------------
| A deposit agreed as "three months' rent" stays three months' rent (2026-08-17)
|--------------------------------------------------------------------------
| `security_deposit` is a flat figure and rent escalates. On a 7% clause a deposit agreed at 3×
| covers 2.62 months by year three and 2.29 by year five: the landlord's security against a
| defaulting tenant erodes by nearly a quarter over a term — silently, and precisely as the tenant
| becomes more likely to default. Yardi tracks the requirement against rent; the Yardi gap analysis
| had this as a 🟡 "note only".
|
| Derived in `Lease::saving`, beside the rate-priced rent derivation and for the same reason: the
| escalation sweep, the Change Rent action, a renewal (which copies `security_deposit` forward while
| setting a NEW rent — the same erosion, one renewal at a time), the importer and the API all write
| leases, and only one of them is a form.
|
| Null means FLAT and nothing moves. A deposit agreed as a sum unrelated to rent is a real deal, and
| inferring a multiple by dividing the deposit by the rent would invent a term nobody agreed to.
*/

use App\Models\Lease;
use App\Services\LeaseRenewalService;
use App\Services\LeaseRentChangeService;
use App\Services\RentEscalationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function depositMultipleLease($ctx, array $overrides = []): Lease
{
    return makeLease($ctx->unit, $ctx->tenant, array_merge([
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2030-12-31',
        'base_rent_monthly' => 100000,
        'security_deposit' => 300000,
        'security_deposit_months' => 3,
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 7,
    ], $overrides));
}

it('derives the deposit from the rent the moment a multiple is stated', function () {
    $lease = depositMultipleLease($this, ['security_deposit' => 1]);   // a wrong figure is simply corrected

    expect((float) $lease->fresh()->security_deposit)->toBe(300000.0);
});

it('tops the deposit up when the rent is changed', function () {
    $lease = depositMultipleLease($this);

    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 107000,
        'effective_from' => '2027-01-01',
        'reason' => 'Year-2 escalation',
    ]);

    expect((float) $lease->fresh()->security_deposit)->toBe(321000.0);
});

it('tops it up through the nightly escalation sweep, which is where the erosion happened', function () {
    CarbonImmutable::setTestNow('2027-01-02');

    $lease = depositMultipleLease($this, ['next_escalation_date' => '2027-01-01']);

    app(RentEscalationService::class)->runForToday(CarbonImmutable::parse('2027-01-02'));

    $lease = $lease->fresh();

    // 100,000 → 107,000, so 3× moves with it. Nobody typed either number.
    expect((float) $lease->base_rent_monthly)->toBe(107000.0)
        ->and((float) $lease->security_deposit)->toBe(321000.0);
});

it('leaves a flat deposit exactly where it is', function () {
    $lease = depositMultipleLease($this, ['security_deposit_months' => null, 'security_deposit' => 250000]);

    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 107000,
        'effective_from' => '2027-01-01',
        'reason' => 'Year-2 escalation',
    ]);

    // A deposit agreed as a sum unrelated to rent is a real deal — it must not be re-derived.
    expect((float) $lease->fresh()->security_deposit)->toBe(250000.0);
});

it('carries the multiple onto a renewal, so the new term does not restart the erosion', function () {
    $lease = depositMultipleLease($this);

    $renewal = app(LeaseRenewalService::class)->renew($lease->fresh(), [
        'new_term_months' => 36,
        'new_rent' => 130000,
    ]);

    expect((float) $renewal->security_deposit_months)->toBe(3.0)
        // The renewal copies `security_deposit` forward; the derivation re-prices it to the new rent
        // in the same save. Without it the renewal would carry a 300,000 deposit against 130,000 rent.
        ->and((float) $renewal->security_deposit)->toBe(390000.0);
});

it('measures the erosion the change exists to stop', function () {
    // Five 7% steps on a flat deposit: what a 3× deposit is actually worth by year five.
    $rent = 100000 * (1.07 ** 4);
    $flatCover = 300000 / $rent;

    expect(round($flatCover, 2))->toBe(2.29);
});
