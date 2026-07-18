<?php

use App\Models\TenantRequest;
use App\Models\Tenant;
use App\Models\Unit;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant();
});

function makeResolvedTenantRequest(Unit $unit, Tenant $tenant, array $overrides = []): TenantRequest
{
    return TenantRequest::create(array_merge([
        'reference' => 'MR-' . uniqid(),
        'tenant_id' => $tenant->id,
        'unit_id' => $unit->id,
        'title' => 'Old ticket',
        'description' => 'description',
        'status' => 'resolved',
        'priority' => 'medium',
        'category' => 'cleaning',
        'submitted_at' => now()->subDays(20),
        'resolved_at' => now()->subDays(15),
    ], $overrides));
}

it('closes resolved requests older than the cutoff', function () {
    $oldEnough = makeResolvedTenantRequest($this->unit, $this->tenant, [
        'resolved_at' => now()->subDays(10),
    ]);
    $tooRecent = makeResolvedTenantRequest($this->unit, $this->tenant, [
        'resolved_at' => now()->subDays(2),
    ]);

    $this->artisan('requests:auto-close --days=7')
        ->expectsOutputToContain('Closed 1 of 1 maintenance request')
        ->assertExitCode(0);

    expect($oldEnough->fresh()->status)->toBe('closed');
    expect($tooRecent->fresh()->status)->toBe('resolved');
});

it('leaves still-open statuses alone', function () {
    $stillInProgress = makeResolvedTenantRequest($this->unit, $this->tenant, [
        'status' => 'in_progress',
        'resolved_at' => null,
    ]);

    $this->artisan('requests:auto-close --days=7')
        ->expectsOutputToContain('No resolved maintenance requests older than 7 days.')
        ->assertExitCode(0);

    expect($stillInProgress->fresh()->status)->toBe('in_progress');
});

it('--dry-run reports counts without changing status', function () {
    $candidate = makeResolvedTenantRequest($this->unit, $this->tenant, [
        'resolved_at' => now()->subDays(30),
    ]);

    $this->artisan('requests:auto-close --days=7 --dry-run')
        ->expectsOutputToContain('Would close 1 maintenance request')
        ->assertExitCode(0);

    expect($candidate->fresh()->status)->toBe('resolved');
});

it('rejects --days=0 with a clear error', function () {
    $this->artisan('requests:auto-close --days=0')->assertExitCode(1);
});
