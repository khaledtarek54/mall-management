<?php

use App\Models\FacilityWorkOrder;
use App\Models\SlaPolicy;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Services\AssessSlaPenaltyService;
use App\Support\OpsLog;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Log;

/**
 * Regression — the SLA scans must leave a durable trace, especially when they fail.
 *
 * THE BUG (fixed 2026-07-16). Both SLA scans reported exclusively through `$this->info()` /
 * `$this->warn()`. `routes/console.php` configures no `appendOutputTo`, so under
 * `schedule:run` that output is **discarded**. Combined with per-row containment — a failure
 * is caught, counted, and the command still returns SUCCESS — every failure was silent:
 *
 *   - `facility:scan-sla-breaches` runs **hourly** and calls AssessSlaPenaltyService.
 *     A throw there means **the vendor is never charged for missing its SLA**, and the only
 *     evidence went to a stdout nobody reads.
 *   - A failed breach alert meant an engineer was never told a job was late.
 *
 * Both now mirror MonthlyBillingService: OpsLog::error per failed row, an OpsLog::info
 * summary every run, and an OpsLog::warning when the run had any failures at all. The
 * console lines stay for a human running the command by hand.
 *
 * These assert on the OPS CHANNEL (via the OpsLog seam), not on console output, because the
 * console is exactly what production throws away.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset(['code' => 'OBS']);
    $this->vendor = Vendor::create(['name' => 'CoolAir', 'category' => 'hvac', 'status' => 'active']);
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 1]);
    VendorContract::create([
        'vendor_id' => $this->vendor->id, 'asset_id' => $this->asset->id,
        'name' => 'HVAC SLA', 'status' => 'active',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'value' => 100000,
        'sla_penalty_basis' => 'flat', 'sla_penalty_rate' => 500,
    ]);
});

/** An open, external CM whose SLA target is already in the past. */
function overdueWorkOrder(): FacilityWorkOrder
{
    return FacilityWorkOrder::create([
        'asset_id' => test()->asset->id, 'work_order_type' => 'cm', 'execution_type' => 'external',
        'vendor_id' => test()->vendor->id, 'description' => 'Chiller down', 'title' => 'Fix chiller',
        'category' => 'hvac', 'priority' => 'urgent', 'scheduled_for' => '2026-07-01',
        'status' => 'in_progress', 'target_resolution_at' => now()->subHours(6),
    ]);
}

it('logs a durable summary of every work-order SLA scan', function () {
    overdueWorkOrder();

    $events = [];
    Log::shouldReceive('channel')->with('ops')->andReturnSelf();
    Log::shouldReceive('info', 'warning', 'error')->andReturnUsing(function (string $event) use (&$events) {
        $events[] = $event;
    });

    $this->artisan('facility:scan-sla-breaches')->assertExitCode(0);

    expect($events)->toContain('Work-order SLA scan complete');
});

it('logs a failed penalty assessment loudly — the vendor going uncharged is not silent', function () {
    overdueWorkOrder();

    // The exact silent-money case: assess() blows up mid-scan. Containment keeps the scan
    // green, so OpsLog is the ONLY evidence this happened.
    $this->app->bind(AssessSlaPenaltyService::class, function () {
        return new class extends AssessSlaPenaltyService
        {
            public function assess(FacilityWorkOrder $order): ?\App\Models\SlaPenalty
            {
                throw new RuntimeException('contract terms unreadable');
            }
        };
    });

    $errors = [];
    $warnings = [];
    Log::shouldReceive('channel')->with('ops')->andReturnSelf();
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('error')->andReturnUsing(function (string $event, array $context) use (&$errors) {
        $errors[] = [$event, $context];
    });
    Log::shouldReceive('warning')->andReturnUsing(function (string $event) use (&$warnings) {
        $warnings[] = $event;
    });

    // Still exits 0 — per-row containment is deliberate. That is precisely why the log matters.
    $this->artisan('facility:scan-sla-breaches')->assertExitCode(0);

    expect($errors)->not->toBeEmpty('a failed penalty assessment must reach the ops channel');
    expect($errors[0][0])->toBe('SLA penalty assessment failed — vendor not charged');
    expect($errors[0][1])->toHaveKeys(['work_order_id', 'vendor_id', 'error']);
    expect($warnings)->toContain('Work-order SLA scan had failures');
});

it('logs a durable summary of every maintenance SLA scan', function () {
    makeTenantRequest([
        'status' => 'submitted',
        'target_resolution_at' => now()->subHours(6),
    ]);

    $events = [];
    Log::shouldReceive('channel')->with('ops')->andReturnSelf();
    Log::shouldReceive('info', 'warning', 'error')->andReturnUsing(function (string $event) use (&$events) {
        $events[] = $event;
    });

    $this->artisan('requests:scan-sla-breaches')->assertExitCode(0);

    expect($events)->toContain('Maintenance SLA scan complete');
});

it('keeps redacting secrets on the scan path', function () {
    // The scans log vendor/order context; OpsLog's redaction must still apply to whatever
    // a future context key carries.
    Log::shouldReceive('channel')->with('ops')->andReturnSelf();
    Log::shouldReceive('error')->once()->withArgs(function (string $event, array $context) {
        expect($context['token'])->toBe('[redacted]');
        expect($context['work_order_id'])->toBe(3);

        return true;
    });

    OpsLog::error('SLA penalty assessment failed — vendor not charged', [
        'work_order_id' => 3,
        'token' => 'super-secret',
    ]);
});
