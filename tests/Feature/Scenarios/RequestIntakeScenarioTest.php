<?php

use App\Filament\Admin\Resources\MaintenanceRequests\Pages\CreateMaintenanceRequest;
use App\Models\TenantRequest;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * FR-REQ intake (Phase 9a) — a staff channel (phone / walk-in / email) may log a request from a
 * caller who is NOT a registered tenant. `tenant_id` becomes optional, and `caller_*` fields record
 * who reported it instead. The invariant is enforced in TenantRequest::booted so admin + portal +
 * API all inherit it: a tenant-less request needs a caller_name and a staff channel; a portal
 * request always has a tenant.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'INT']);
    $this->unit = makeUnit($this->asset, ['code' => 'INT-01']);
});

function intakeRequest(array $attrs = []): TenantRequest
{
    return TenantRequest::create(array_merge([
        'reference' => 'MR-'.uniqid(),
        'unit_id' => test()->unit->id,
        'title' => 'Broken corridor tile',
        'description' => 'Reported at the front desk',
        'status' => 'submitted',
        'priority' => 'medium',
        'category' => 'other',
        'channel' => 'phone',
        'submitted_at' => now(),
    ], $attrs));
}

/* ---- the model invariant ------------------------------------------------ */

it('logs a staff-channel request from an unregistered caller', function () {
    $r = intakeRequest(['tenant_id' => null, 'channel' => 'walk_in', 'caller_name' => 'Passer-by', 'caller_phone' => '0100']);

    expect($r->exists)->toBeTrue()
        ->and($r->tenant_id)->toBeNull()
        ->and($r->caller_name)->toBe('Passer-by')
        ->and($r->caller_phone)->toBe('0100');
});

it('refuses a tenant-less request that does not say who reported it', function () {
    expect(fn () => intakeRequest(['tenant_id' => null, 'channel' => 'phone', 'caller_name' => null]))
        ->toThrow(DomainException::class);
});

it('refuses a tenant-less PORTAL request — the portal is always a known tenant', function () {
    expect(fn () => intakeRequest(['tenant_id' => null, 'channel' => 'portal', 'caller_name' => 'Someone']))
        ->toThrow(DomainException::class);
});

it('still accepts a normal request that carries its tenant', function () {
    $tenant = makeTenant();
    $r = intakeRequest(['tenant_id' => $tenant->id, 'channel' => 'portal']);

    expect($r->tenant_id)->toBe($tenant->id);
});

/* ---- the intake form ---------------------------------------------------- */

it('creates a caller-only request through the admin form', function () {
    $this->actingAs(makeUser('operations', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(CreateMaintenanceRequest::class)
        ->fillForm([
            'request_type' => 'maintenance',
            'channel' => 'phone',
            'caller_name' => 'Mr Walk-in',
            'caller_phone' => '01000000000',
            'unit_id' => $this->unit->id,
            'priority' => 'medium',
            'category' => 'other',
            'title' => 'Leaking tap in the food court',
            'description' => 'Reported by a shopper at the desk',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $r = TenantRequest::where('caller_name', 'Mr Walk-in')->first();
    expect($r)->not->toBeNull()
        ->and($r->tenant_id)->toBeNull()
        ->and($r->channel)->toBe('phone');

    Filament::setTenant(null, isQuiet: true);
});

it('requires a tenant on the admin form when the channel is the portal', function () {
    $this->actingAs(makeUser('operations', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(CreateMaintenanceRequest::class)
        ->fillForm([
            'request_type' => 'maintenance',
            'channel' => 'portal',
            'unit_id' => $this->unit->id,
            'priority' => 'medium',
            'category' => 'other',
            'title' => 'Something',
            'description' => 'No tenant picked',
        ])
        ->call('create')
        ->assertHasFormErrors(['tenant_id']);

    Filament::setTenant(null, isQuiet: true);
});
