<?php

declare(strict_types=1);

use App\Models\Charge;
use App\Models\Lease;
use App\Services\LeaseRenewalService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

/**
 * A RENEWAL'S RENT AND SERVICE CHARGE ARE NEGOTIATED TERMS, NOT COPIES.
 *
 * `LeaseRenewalService` CARRIES one charge row per type from the original and rewrites the amount
 * of `base_rent` / `service_charge` as it goes. So the two figures the caller states reached the
 * schedule only by amending a row the original already had — and when the original had no row of
 * that type, the figure was written to `leases.*_monthly` and the schedule got nothing.
 *
 * The schedule is what bills. Measured on the demo books: a lease renewed at 110,000 rent plus
 * 12,000 service charge produced a lease record reading 12,000, no service-charge row at all, and a
 * first invoice of 115,500 instead of 129,180. 144,000 a year — and the lease's own screen shows
 * the agreed figure, so there is nothing for the operator to notice.
 *
 * Base rent fails the same way and worse: an original whose rent row had been closed carries no
 * row, and the renewal then bills the marketing levy alone.
 *
 * The third test is the one that keeps the fix honest — the ordinary case, where the carry loop
 * already produced the row, must not gain a second one.
 */
function renewalFixture(float $rent, float $service, bool $closeRentRow = false): Lease
{
    $lease = Lease::factory()->create([
        'status' => 'active',
        'commencement_date' => now()->subYear()->startOfMonth(),
        'expiry_date' => now()->addMonth()->endOfMonth(),
        'base_rent_monthly' => $rent,
        'service_charge_monthly' => $service,
        'escalation_type' => 'none',
    ]);

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => $rent,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'start_date' => $lease->commencement_date,
        'is_active' => ! $closeRentRow,
    ]);

    if ($service > 0) {
        Charge::create([
            'lease_id' => $lease->id,
            'name' => 'Service Charge',
            'type' => 'service_charge',
            'amount' => $service,
            'currency' => 'EGP',
            'frequency' => 'monthly',
            'start_date' => $lease->commencement_date,
            'is_active' => true,
        ]);
    }

    return $lease->fresh();
}

it('writes a service-charge row the original never had', function (): void {
    $lease = renewalFixture(rent: 50_000, service: 0);

    $renewal = app(LeaseRenewalService::class)->renew($lease, [
        'new_term_months' => 12,
        'new_rent' => 110_000,
        'new_service_charge' => 12_000,
    ]);

    $row = $renewal->charges()->where('type', 'service_charge')->first();

    expect($row)->not->toBeNull()
        ->and((float) $row->amount)->toBe(12_000.0);

    // The lease record and the schedule must agree — the whole defect was that they did not.
    expect((float) $renewal->service_charge_monthly)->toBe((float) $row->amount);
});

it('writes a base-rent row when the original had no live one', function (): void {
    $lease = renewalFixture(rent: 50_000, service: 0, closeRentRow: true);

    $renewal = app(LeaseRenewalService::class)->renew($lease, [
        'new_term_months' => 12,
        'new_rent' => 80_000,
        'new_service_charge' => 0,
    ]);

    expect((float) $renewal->charges()->where('type', 'base_rent')->value('amount'))->toBe(80_000.0);

    // And it BILLS — the row existing is not the claim, the invoice is. Without the fix this
    // invoice held the marketing levy alone.
    $result = app(MonthlyBillingService::class)->generateForLease(
        $renewal,
        CarbonImmutable::parse($renewal->commencement_date)->startOfMonth(),
    );

    expect($result['invoice'])->not->toBeNull();
    expect($result['invoice']->items->firstWhere('type', 'base_rent'))->not->toBeNull();
});

it('does not double a row the carry loop already wrote', function (): void {
    $lease = renewalFixture(rent: 50_000, service: 4_000);

    $renewal = app(LeaseRenewalService::class)->renew($lease, [
        'new_term_months' => 12,
        'new_rent' => 70_000,
        'new_service_charge' => 8_000,
    ]);

    expect($renewal->charges()->where('type', 'service_charge')->count())->toBe(1);
    expect($renewal->charges()->where('type', 'base_rent')->count())->toBe(1);
    expect((float) $renewal->charges()->where('type', 'service_charge')->value('amount'))->toBe(8_000.0);
});
