<?php

use App\Models\TenantRequest;
use App\Notifications\MaintenanceSlaBreachedNotification;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset);
    $this->manager = makeUser('manager', [$this->asset->id]);
});

it('alerts on a breached request and stamps sla_breach_notified_at', function () {
    Notification::fake();

    $request = TenantRequest::create([
        'reference' => 'MR-' . uniqid(),
        'tenant_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'title' => 'Late',
        'description' => 'past target',
        'status' => 'in_progress',
        'priority' => 'high',
        'category' => 'plumbing',
        'submitted_at' => now()->subDays(5),
        'target_resolution_at' => now()->subHours(3),
    ]);

    $this->artisan('maintenance:scan-sla-breaches')
        ->expectsOutputToContain('Alerted on 1 of 1 breach')
        ->assertExitCode(0);

    Notification::assertSentTo($this->manager, MaintenanceSlaBreachedNotification::class);
    expect($request->fresh()->sla_breach_notified_at)->not->toBeNull();
});

it('does not re-alert a request that was already notified', function () {
    Notification::fake();

    TenantRequest::create([
        'reference' => 'MR-' . uniqid(),
        'tenant_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'title' => 'Already alerted',
        'description' => '',
        'status' => 'in_progress',
        'priority' => 'medium',
        'category' => 'cleaning',
        'submitted_at' => now()->subDays(10),
        'target_resolution_at' => now()->subHours(5),
        'sla_breach_notified_at' => now()->subHours(1),
    ]);

    $this->artisan('maintenance:scan-sla-breaches')
        ->expectsOutputToContain('No new SLA breaches.')
        ->assertExitCode(0);

    Notification::assertNothingSent();
});

it('--dry-run does not write or notify', function () {
    Notification::fake();

    $request = TenantRequest::create([
        'reference' => 'MR-' . uniqid(),
        'tenant_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'title' => 'Dry-run',
        'description' => '',
        'status' => 'in_progress',
        'priority' => 'high',
        'category' => 'hvac',
        'submitted_at' => now()->subDays(2),
        'target_resolution_at' => now()->subHours(1),
    ]);

    $this->artisan('maintenance:scan-sla-breaches --dry-run')
        ->expectsOutputToContain('Would alert on 1 breach')
        ->assertExitCode(0);

    Notification::assertNothingSent();
    expect($request->fresh()->sla_breach_notified_at)->toBeNull();
});

it('skips closed/resolved/cancelled requests even if past the target', function () {
    Notification::fake();

    TenantRequest::create([
        'reference' => 'MR-' . uniqid(),
        'tenant_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'title' => 'Resolved',
        'description' => '',
        'status' => 'resolved',
        'priority' => 'high',
        'category' => 'cleaning',
        'submitted_at' => now()->subDays(10),
        'target_resolution_at' => now()->subHours(20),
        'resolved_at' => now()->subHours(1),
    ]);

    $this->artisan('maintenance:scan-sla-breaches')
        ->expectsOutputToContain('No new SLA breaches.')
        ->assertExitCode(0);

    Notification::assertNothingSent();
});
