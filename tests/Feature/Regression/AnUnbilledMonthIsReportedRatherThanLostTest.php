<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\ScanUnbilledPeriodsService;
use App\Support\ScheduledModules;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/**
 * A month the lease was billable for and nothing invoiced must be REPORTED, not lost.
 *
 * `RunMonthlyBilling` bills exactly one period — `now()->startOfMonth()` — and no caller loops over
 * missed ones. That is deliberate: posting a burst of back-dated entries into possibly-closed
 * periods is the trap `expenses:generate-recurring` states in writing. The consequence nobody had
 * closed is that every route which leaves a month behind leaves it behind SILENTLY.
 *
 * It became reachable in the ordinary course of business on 2026-09-10, when a lease whose term had
 * ended became renewable: such a renewal is dated back to the day after the old term so the tenancy
 * has no gap, and the elapsed months were then nobody's job. The renewal modal now says so — but a
 * sentence in a modal is not a control, and the operator who reads it still has nothing telling them
 * WHICH months, on which leases, are outstanding.
 *
 * The same hole swallows a back-dated commencement, a failed billing night whose catch-up covered
 * one period and not the others, and a month whose only invoice was cancelled or left in draft
 * (which `alreadyBilledForMonth()` correctly does not count as billed).
 *
 * **It reports and does not bill**, and that is the load-bearing decision: raising an invoice sets a
 * posting date, `Invoice` carries `#[PostingDateGuardedBy]` so a closed period is refused, and a
 * scheduled job silently choosing that date for months of history is precisely what the one-period
 * rule exists to prevent. Yardi is the same — Voyager posts charges per period, and a back-dated
 * lease means running that post.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'UNB']);
});

/** A lease running for months, with a rent schedule the billing planner will actually price. */
function billableSince(CarbonImmutable $start, array $attrs = []): Lease
{
    $lease = makeLease(makeUnit(test()->asset, ['status' => 'occupied']), null, array_merge([
        'status' => 'active',
        'commencement_date' => $start->toDateString(),
        'expiry_date' => CarbonImmutable::now()->addYear()->endOfMonth()->toDateString(),
        'base_rent_monthly' => 50000,
        'service_charge_monthly' => 0,
    ], $attrs));

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => 50000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'start_date' => $start->toDateString(),
        'is_active' => true,
    ]);

    return $lease->fresh();
}

it('reports the months a back-dated lease was never invoiced for', function () {
    // The renewal shape: the term started three months ago and nothing has billed since.
    $lease = billableSince(CarbonImmutable::now()->subMonths(3)->startOfMonth());

    $result = app(ScanUnbilledPeriodsService::class)->run(6);

    $periods = collect($result['rows'])->where('lease_id', $lease->id)->pluck('period');

    expect($result['gaps'])->toBeGreaterThan(0)
        ->and($periods)->toContain(CarbonImmutable::now()->subMonths(3)->format('Y-m'))
        ->and($periods)->toContain(CarbonImmutable::now()->subMonth()->format('Y-m'))
        // …and it says how much, because "some months are missing" is not actionable.
        ->and($result['total'])->toBeGreaterThan(0.0);
});

it('never reports the CURRENT month, which has not been billed yet', function () {
    // Not an off-by-one: a property may bill on the 25th (`BillingDay`), so the current month is
    // legitimately uninvoiced for most of every month. Flagging it would fire on the whole
    // portfolio almost always — an alert that is usually noise is one nobody opens.
    $lease = billableSince(CarbonImmutable::now()->subMonths(2)->startOfMonth());

    $result = app(ScanUnbilledPeriodsService::class)->run(6);

    expect(collect($result['rows'])->pluck('period'))
        ->not->toContain(CarbonImmutable::now()->format('Y-m'));
});

it('says nothing about a lease whose months really were invoiced', function () {
    // The control. A scan that reported everything would satisfy the assertions above and be
    // useless — and worse than useless, because it would be switched off.
    $start = CarbonImmutable::now()->subMonths(2)->startOfMonth();
    $lease = billableSince($start);

    foreach ([2, 1] as $back) {
        $period = CarbonImmutable::now()->subMonths($back)->startOfMonth();
        makeInvoice($lease, [
            'status' => 'issued',
            'period_start' => $period->toDateString(),
            'period_end' => $period->endOfMonth()->toDateString(),
        ]);
    }

    $result = app(ScanUnbilledPeriodsService::class)->run(6);

    expect(collect($result['rows'])->where('lease_id', $lease->id))->toBeEmpty();
});

it('still reports a month whose only invoice was cancelled', function () {
    // `alreadyBilledForMonth()` deliberately does not count a cancelled or draft invoice as billed
    // — that document left the books, or never reached them. The scan must agree with it, or the
    // two would disagree about the same month and the operator could not reconcile them.
    $period = CarbonImmutable::now()->subMonth()->startOfMonth();
    $lease = billableSince($period);

    makeInvoice($lease, [
        'status' => 'cancelled',
        'period_start' => $period->toDateString(),
        'period_end' => $period->endOfMonth()->toDateString(),
    ]);

    $result = app(ScanUnbilledPeriodsService::class)->run(3);

    expect(collect($result['rows'])->where('lease_id', $lease->id)->pluck('period'))
        ->toContain($period->format('Y-m'));
});

it('exits clean by default and non-zero only under --strict', function () {
    // A permanently red step is one people stop reading, so an install carrying historical gaps
    // still gets a green exit and a printed table. `--strict` is for a cutover, where the answer
    // has to be none.
    billableSince(CarbonImmutable::now()->subMonths(2)->startOfMonth());

    expect(Artisan::call('billing:scan-unbilled-periods', ['--months' => 3]))->toBe(0)
        ->and(Artisan::call('billing:scan-unbilled-periods', ['--months' => 3, '--strict' => true]))->toBe(1);

    expect(Artisan::output())->toContain('Billing forecast');
});

it('is scheduled, and classified as core rather than gated behind a module', function () {
    // An unscheduled scan is the original bug exactly — the mechanism exists and never runs. And a
    // key outside `Modules::KEYS` is a guard that can never refuse, so billing scans are CORE with
    // the reason written down rather than pointed at a flag that does nothing.
    // Matched to a WORD BOUNDARY, not `str_contains`: the loose version passed against a mutant
    // scheduling `billing:scan-unbilled-periods-DISABLED`, which is exactly the drift a schedule
    // check exists to notice.
    $scheduled = collect(app(Schedule::class)->events())
        ->contains(fn ($e) => preg_match('/billing:scan-unbilled-periods(?![\w-])/', (string) $e->command) === 1);

    expect($scheduled)->toBeTrue()
        ->and(ScheduledModules::CORE)->toHaveKey('billing:scan-unbilled-periods')
        ->and(ScheduledModules::CORE['billing:scan-unbilled-periods'])->not->toBe('');
});
