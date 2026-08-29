<?php

/*
|--------------------------------------------------------------------------
| A billed deposit must not silence the rent (2026-08-28)
|--------------------------------------------------------------------------
| `MonthlyBillingService::alreadyBilledForMonth()` keeps a list of one-off invoice types that must
| not suppress a month's recurring invoice, under a docblock that states the rule in writing:
|
|     "anything that raises its own invoice dated into a billed month belongs here, and belongs here
|      in the same commit that starts raising it."
|
| `security_deposit` was never added. It is the FIFTH instance of that class and the only one that
| costs more than a month: `BillSecurityDepositService` dates its invoice to the LEASE'S OWN TERM —
| commencement to expiry — so the overlap test matched every month of the lease.
|
| Measured: a three-year lease billed its deposit and then raised NO rent invoice for any month of
| its term. The run reported an ordinary `skipped`, indistinguishable in the summary from a lease
| that had been billed correctly. Eight invoices appeared the moment the type was registered.
|
| Found while preparing a termination exercise and noticing the lease had nothing to terminate — not
| by any test, because every billing test bills a lease that has never had a deposit raised on it.
*/

use App\Models\Invoice;
use App\Services\BillSecurityDepositService;
use App\Services\ChargeScheduleService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChargeCodeSeeder;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->seed(ChargeCodeSeeder::class);

    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset, ['area_sqm' => 110]), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-08-01',
        'expiry_date' => '2029-07-31',
        'base_rent_monthly' => 44000,
        'security_deposit' => 132000,
        'security_deposit_months' => 3,
    ]);

    app(ChargeScheduleService::class)->setAmount(
        $this->lease, 'base_rent', 44000, CarbonImmutable::parse('2026-08-01'),
    );
});

/** Bill one month and say what the run decided. */
function billMonth($lease, string $month): array
{
    return app(MonthlyBillingService::class)->generateForLease(
        $lease->fresh(), CarbonImmutable::parse($month), prorate: true,
    );
}

it('bills the rent in the month the deposit was billed', function () {
    app(BillSecurityDepositService::class)->bill($this->lease->fresh(), []);

    $result = billMonth($this->lease, '2026-08-01');

    expect($result['status'] ?? null)->not->toBe('skipped')
        ->and(Invoice::whereHas('items', fn ($i) => $i->where('type', 'base_rent'))->count())->toBe(1);
});

it('keeps billing for the REST of the term — the part that made this expensive', function () {
    // The deposit invoice's period is the whole lease term, so the overlap matched every month.
    // A single month would have passed on a one-month bug; this is the assertion that names the
    // real cost.
    app(BillSecurityDepositService::class)->bill($this->lease->fresh(), []);

    foreach (['2026-08-01', '2026-09-01', '2027-01-01', '2028-06-01'] as $month) {
        billMonth($this->lease, $month);
    }

    expect(Invoice::whereHas('items', fn ($i) => $i->where('type', 'base_rent'))->count())->toBe(4);
});

it('still refuses to bill the same month twice', function () {
    // The control, and the reason the check exists at all: a real duplicate must still be caught.
    billMonth($this->lease, '2026-08-01');
    $second = billMonth($this->lease, '2026-08-01');

    expect($second['status'] ?? null)->toBe('skipped')
        ->and($second['reason'] ?? null)->toBe('already_billed')
        ->and(Invoice::whereHas('items', fn ($i) => $i->where('type', 'base_rent'))->count())->toBe(1);
});

it('does not let a deposit invoice hide a duplicate either', function () {
    // Registering the type must not weaken the duplicate check for the months it covers.
    app(BillSecurityDepositService::class)->bill($this->lease->fresh(), []);
    billMonth($this->lease, '2026-09-01');
    billMonth($this->lease, '2026-09-01');

    expect(Invoice::whereHas('items', fn ($i) => $i->where('type', 'base_rent'))
        ->whereDate('period_start', '2026-09-01')->count())->toBe(1);
});
