<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Bug B (lease→invoice sweep 2026-07-19): the per-lease "Generate Invoice" action
 * (generateForLease) took no lock, while the bulk runForPeriod holds a period lock. With
 * idempotency being a check-then-create and no DB unique key, a manual generate racing the
 * scheduled run (or a second admin / double-click) could each pass the probe and mint a
 * duplicate invoice. generateForLease now contends on the SAME period lock the bulk run holds.
 */
function lockTestLease(): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'commencement_date' => '2026-01-01', 'expiry_date' => '2027-12-31', 'payment_terms_days' => 7,
    ]);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 10000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0, 'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    return $lease->fresh();
}

it('refuses to generate a single-lease invoice while the period lock is held, then succeeds once released', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10'));
    $lease = lockTestLease();
    $service = app(MonthlyBillingService::class);

    // Simulate the bulk run (or a concurrent single generate) holding the period lock.
    $lock = Cache::lock('billing:run:2026-03', 900);
    expect($lock->get())->toBeTrue();

    try {
        $blocked = $service->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));
    } finally {
        $lock->release();
    }

    // Under contention: skipped, no invoice minted (the guard, not a duplicate).
    expect($blocked['status'])->toBe('skipped')
        ->and($blocked['reason'])->toBe('run_in_progress')
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(0);

    // Lock free → the same call now creates exactly one invoice (proves the lock was the gate).
    $ok = $service->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));
    expect($ok['status'])->toBe('created')
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(1);
});
