<?php

/*
|--------------------------------------------------------------------------
| The month rent commences is billed from the day it commences (2026-08-16)
|--------------------------------------------------------------------------
| `rent_commencement_date = 15 April` billed the tenant the WHOLE of April.
|
| `Lease::rentCommencesOn()` normalises to the 1st — correctly, because billing periods are whole
| months and April genuinely is the first rent month — and its own docblock called the remaining
| half "a proration question, not a period question". Nothing then answered that question:
| `planInvoiceForLease()` clipped the leading edge to `commencement_date` alone, which on a lease
| that commenced in January is long past, so the multiplier came out at 1.0.
|
| On a 100,000 rent that is 46,666.67 charged for a fortnight of a contractually rent-free period —
| on the first invoice a new tenant ever receives, which is the one they read most carefully.
|
| The clip is per charge TYPE, not per invoice: under net abatement the tenant has been paying the
| service charge and the marketing levy since handover, so those bill the full month while the rent
| beside them bills half. Under `gross` the whole invoice was abated, so the whole invoice is
| clipped.
*/

use App\Models\Lease;
use App\Services\LeaseCreationService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'HW']);
    $this->unit = makeUnit($this->asset, ['code' => 'HW-01', 'status' => 'vacant']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** A lease that commences in January with its rent-free period ending inside April. */
function graceLease(array $attrs, ?object $ctx = null): Lease
{
    $lease = makeLease($ctx->unit, makeTenant(), array_merge([
        'commencement_date' => '2027-01-01',
        'expiry_date' => '2029-12-31',
        'base_rent_monthly' => 100000,
        'service_charge_monthly' => 15000,
        'has_marketing_levy' => false,
        'rent_commencement_date' => '2027-04-15',
        'escalation_type' => 'none',
        'escalation_rate' => 0,
    ], $attrs));

    LeaseCreationService::seedStandardCharges($lease, rent: 100000, service: 15000);

    return $lease->fresh();
}

/** @return array<string, float> type => net amount */
function aprilLines(Lease $lease): array
{
    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2027-04-01'), prorate: true);

    expect($result['invoice'])->not->toBeNull('April must bill — reason: '.($result['reason'] ?? 'none'));

    return $result['invoice']->items
        ->mapWithKeys(fn ($item) => [$item->type => round((float) $item->amount, 2)])
        ->all();
}

it('bills rent from the 15th while the service charge — never abated — bills the whole month', function () {
    $lines = aprilLines(graceLease(['fit_out_scope' => Lease::FIT_OUT_RENT_ONLY], $this));

    // 16 of April's 30 days.
    expect($lines['base_rent'])->toBe(53333.33)
        ->and($lines['service_charge'])->toBe(15000.00);
});

it('clips the whole invoice under a gross abatement, because the whole invoice was free', function () {
    $lines = aprilLines(graceLease(['fit_out_scope' => Lease::FIT_OUT_GROSS], $this));

    expect($lines['base_rent'])->toBe(53333.33)
        ->and($lines['service_charge'])->toBe(8000.00);   // 15,000 × 16/30
});

it('marks only the prorated line, so a full-month line beside it is not mislabelled', function () {
    $lease = graceLease(['fit_out_scope' => Lease::FIT_OUT_RENT_ONLY], $this);

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2027-04-01'), prorate: true)['invoice'];

    $rent = $invoice->items->firstWhere('type', 'base_rent');
    $service = $invoice->items->firstWhere('type', 'service_charge');

    expect($rent->description)->toContain('pro-rated')
        ->and($service->description)->not->toContain('pro-rated');
});

it('changes nothing when rent commences on the first of a month', function () {
    $lines = aprilLines(graceLease([
        'fit_out_scope' => Lease::FIT_OUT_RENT_ONLY,
        'rent_commencement_date' => '2027-04-01',
    ], $this));

    expect($lines['base_rent'])->toBe(100000.00)
        ->and($lines['service_charge'])->toBe(15000.00);
});

it('changes nothing on a lease with no rent-free period at all', function () {
    $lines = aprilLines(graceLease(['rent_commencement_date' => null], $this));

    expect($lines['base_rent'])->toBe(100000.00)
        ->and($lines['service_charge'])->toBe(15000.00);
});

it('leaves the months BEFORE the crossover exactly as they were', function () {
    $lease = graceLease(['fit_out_scope' => Lease::FIT_OUT_RENT_ONLY], $this);

    $march = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2027-03-01'), prorate: true)['invoice'];

    // Still inside the rent-free window: service charge only, at its full amount.
    expect($march->items->pluck('type')->all())->toBe(['service_charge'])
        ->and(round((float) $march->items->first()->amount, 2))->toBe(15000.00);
});
