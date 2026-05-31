<?php

use App\Jobs\ApplyLateFees;
use App\Jobs\RunMonthlyBilling;
use App\Models\Charge;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

// Freeze "now" so Carbon::createFromFormat('Y-m', 'YYYY-MM') doesn't overflow
// when the suite runs on a day-of-month that doesn't exist in the target
// month (e.g. running on the 31st with target month February → day-of-month
// rolls into March 3 → period drifts to YYYY-03).
beforeEach(fn () => Carbon::setTestNow('2026-02-15 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

/* ───────── billing:apply-late-fees ───────── */

it('runs apply-late-fees synchronously and prints the stats table', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset, ['status' => 'occupied']);
    $lease = makeLease($unit);

    // One overdue invoice that is eligible for a late fee.
    makeInvoice($lease, [
        'status' => 'overdue',
        'issue_date' => '2026-01-01',
        'due_date' => '2026-01-08',
        'balance' => 1000,
        'total' => 1000,
    ]);

    $this->artisan('billing:apply-late-fees', ['--date' => '2026-02-15'])
        ->expectsOutputToContain('Applying late fees as of 2026-02-15')
        ->assertExitCode(0);
});

it('apply-late-fees dispatches a job when --queue is passed', function () {
    Bus::fake();

    $this->artisan('billing:apply-late-fees', ['--queue' => true, '--date' => '2026-02-15'])
        ->expectsOutputToContain('Late-fee job dispatched')
        ->assertExitCode(0);

    Bus::assertDispatched(ApplyLateFees::class, fn ($job) => $job->date === '2026-02-15');
});

/* ───────── billing:run-monthly ───────── */

it('runs monthly billing synchronously and prints the stats table', function () {
    // No active leases → considered=0, no failures, SUCCESS.
    $this->artisan('billing:run-monthly', ['--period' => '2026-02'])
        ->expectsOutputToContain('Running monthly billing for February 2026')
        ->assertExitCode(0);
});

it('billing:run-monthly --queue dispatches a job and exits 0', function () {
    Bus::fake();

    $this->artisan('billing:run-monthly', ['--queue' => true, '--period' => '2026-04'])
        ->expectsOutputToContain('Monthly billing job dispatched')
        ->assertExitCode(0);

    Bus::assertDispatched(RunMonthlyBilling::class, fn ($job) => $job->period === '2026-04');
});

/* ───────── cam:reconcile ───────── */

it('cam:reconcile warns and exits SUCCESS when no pools exist for the year', function () {
    $this->artisan('cam:reconcile', ['--year' => 2099])
        ->expectsOutputToContain('No CAM pools found for 2099')
        ->assertExitCode(0);
});

it('cam:reconcile generates allocations for draft pools', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset, ['status' => 'occupied', 'area_sqm' => 100]);
    $lease = makeLease($unit);

    \App\Models\CamExpensePool::create([
        'asset_id' => $asset->id,
        'period_year' => 2026,
        'total_actual_expense' => 50000,
        'total_estimated_collected' => 40000,
        'status' => 'draft',
    ]);

    $this->artisan('cam:reconcile', ['--year' => 2026])
        ->expectsOutputToContain('1 allocations')
        ->assertExitCode(0);

    expect(\App\Models\CamAllocation::where('lease_id', $lease->id)->count())->toBe(1);
});

it('cam:reconcile --auto-bill also creates charges', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset, ['status' => 'occupied', 'area_sqm' => 100]);
    $lease = makeLease($unit);

    \App\Models\CamExpensePool::create([
        'asset_id' => $asset->id,
        'period_year' => 2026,
        'total_actual_expense' => 50000,
        'total_estimated_collected' => 40000,
        'status' => 'draft',
    ]);

    $this->artisan('cam:reconcile', ['--year' => 2026, '--auto-bill' => true])
        ->expectsOutputToContain('1 billed')
        ->assertExitCode(0);

    expect(Charge::where('lease_id', $lease->id)->where('type', 'other')->count())->toBe(1);
});

/* ───────── Jobs (drive handle()) ───────── */

it('ApplyLateFees job handle() runs the service for an explicit date', function () {
    $stats = (new ApplyLateFees('2026-02-15'))->handle(app(\App\Services\LateFeeService::class));

    expect($stats)->toHaveKeys(['considered', 'applied', 'skipped', 'failed']);
});

it('RunMonthlyBilling job handle() runs the service for a YYYY-MM period', function () {
    $stats = (new RunMonthlyBilling('2026-02'))->handle(app(\App\Services\MonthlyBillingService::class));

    expect($stats)->toHaveKeys(['period', 'leases_considered', 'created', 'skipped', 'failed']);
    expect($stats['period'])->toBe('2026-02');
});
