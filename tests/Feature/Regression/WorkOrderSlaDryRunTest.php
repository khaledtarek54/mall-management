<?php

use App\Models\MaintenancePenalty;
use App\Models\MaintenanceWorkOrder;
use App\Models\SlaPolicy;
use App\Models\Vendor;
use App\Models\VendorContract;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Regression — gap-analysis **F-96** (module 26): `maintenance:scan-wo-sla-breaches --dry-run`
 * still wrote.
 *
 * THE BUG. `assessPenalties()` ran BEFORE the dry-run check, so a "preview" documented as
 * "print what would be alerted WITHOUT writing" created/updated real `maintenance_penalties`
 * (financial) rows. An operator sizing up impact on a fresh install got live penalty records.
 *
 * THE FIX. The command returns on `--dry-run` before assessPenalties() and before the alert
 * loop — it previews both, writes neither. A real (non-dry) run still assesses, so accrual is
 * unaffected.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset(['code' => 'DRY']);
    $this->vendor = Vendor::create(['name' => 'CoolAir', 'category' => 'hvac', 'status' => 'active']);
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 1]);
    VendorContract::create([
        'vendor_id' => $this->vendor->id, 'asset_id' => $this->asset->id,
        'name' => 'HVAC SLA', 'status' => 'active',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'value' => 100000,
        'sla_penalty_basis' => 'flat', 'sla_penalty_rate' => 500,
    ]);
    $this->order = MaintenanceWorkOrder::create([
        'asset_id' => $this->asset->id, 'work_order_type' => 'cm', 'execution_type' => 'external',
        'vendor_id' => $this->vendor->id, 'description' => 'Chiller down', 'title' => 'Fix chiller',
        'category' => 'hvac', 'priority' => 'urgent', 'scheduled_for' => '2026-07-01',
        'status' => 'in_progress', 'target_resolution_at' => now()->subHours(6),
    ]);
});

it('writes no penalty row (and stamps nothing) on --dry-run', function () {
    $this->artisan('maintenance:scan-wo-sla-breaches', ['--dry-run' => true])->assertExitCode(0);

    expect(MaintenancePenalty::count())->toBe(0, 'a preview must not create a financial record')
        ->and($this->order->fresh()->sla_breach_notified_at)->toBeNull('a preview must not stamp the alert');
});

it('still assesses the penalty on a real (non-dry) run', function () {
    // Proves the fix moved the write behind the flag WITHOUT disabling real accrual.
    $this->artisan('maintenance:scan-wo-sla-breaches')->assertExitCode(0);

    expect(MaintenancePenalty::where('maintenance_work_order_id', $this->order->id)->count())->toBe(1);
});
