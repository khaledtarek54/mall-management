<?php

use App\Models\Vendor;
use App\Models\VendorContract;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
});

it('expires active contracts past their end_date', function () {
    Carbon::setTestNow('2026-06-01');

    $vendor = Vendor::create([
        'name' => 'Cool-Air HVAC',
        'slug' => 'cool-air-hvac-'.uniqid(),
        'type' => 'service_provider',
        'status' => 'active',
    ]);

    $expired = VendorContract::create([
        'vendor_id' => $vendor->id,
        'asset_id' => $this->asset->id,
        'name' => 'HVAC Annual Maintenance',
        'status' => 'active',
        'start_date' => '2025-05-01',
        'end_date' => '2026-04-30', // ended in the past
        'currency' => 'EGP',
    ]);

    $stillRunning = VendorContract::create([
        'vendor_id' => $vendor->id,
        'asset_id' => $this->asset->id,
        'name' => 'Quarterly Tune-up',
        'status' => 'active',
        'start_date' => '2025-12-01',
        'end_date' => '2026-11-30',
        'currency' => 'EGP',
    ]);

    $this->artisan('vendors:expire-contracts')
        ->expectsOutputToContain('Expired 1 vendor contract')
        ->assertExitCode(0);

    expect($expired->fresh()->status)->toBe('expired');
    expect($stillRunning->fresh()->status)->toBe('active');

    Carbon::setTestNow();
});

it('--dry-run reports candidates without writing', function () {
    Carbon::setTestNow('2026-06-01');

    $vendor = Vendor::create([
        'name' => 'BrightSpark',
        'slug' => 'brightspark-'.uniqid(),
        'type' => 'contractor',
        'status' => 'active',
    ]);

    $expired = VendorContract::create([
        'vendor_id' => $vendor->id,
        'asset_id' => $this->asset->id,
        'name' => 'Lobby Lighting Refresh',
        'status' => 'active',
        'start_date' => '2025-01-01',
        'end_date' => '2026-03-31',
        'currency' => 'EGP',
    ]);

    $this->artisan('vendors:expire-contracts --dry-run')
        ->expectsOutputToContain('Would expire 1 vendor contract')
        ->assertExitCode(0);

    expect($expired->fresh()->status)->toBe('active');

    Carbon::setTestNow();
});

it('is idempotent and leaves an activity-log trail for the auto-expiry', function () {
    Carbon::setTestNow('2026-06-01');

    $vendor = Vendor::create([
        'name' => 'SteadyState Facilities',
        'slug' => 'steadystate-'.uniqid(),
        'type' => 'service_provider',
        'status' => 'active',
    ]);

    $contract = VendorContract::create([
        'vendor_id' => $vendor->id,
        'asset_id' => $this->asset->id,
        'name' => 'Security Coverage',
        'status' => 'active',
        'start_date' => '2025-01-01',
        'end_date' => '2026-04-30', // past
        'currency' => 'EGP',
    ]);

    $this->artisan('vendors:expire-contracts')
        ->expectsOutputToContain('Expired 1 vendor contract')
        ->assertExitCode(0);
    expect($contract->fresh()->status)->toBe('expired');

    // Re-run: nothing left to flip (idempotent — no double action under overlap).
    $this->artisan('vendors:expire-contracts')
        ->expectsOutputToContain('No active vendor contracts past their end_date.')
        ->assertExitCode(0);

    // The auto-expiry is audit-logged — a mass update() would have bypassed model
    // events entirely, so no 'updated' activity would exist for the contract.
    $hasUpdateLog = Activity::query()
        ->where('log_name', 'vendor_contract')
        ->where('subject_type', $contract->getMorphClass())
        ->where('subject_id', $contract->id)
        ->where('description', 'updated')
        ->exists();

    expect($hasUpdateLog)->toBeTrue();

    Carbon::setTestNow();
});

it('exits clean when no candidates exist', function () {
    $this->artisan('vendors:expire-contracts')
        ->expectsOutputToContain('No active vendor contracts past their end_date.')
        ->assertExitCode(0);
});
