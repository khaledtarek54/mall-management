<?php

use App\Enums\TenantRequestType;
use App\Filament\Admin\Resources\MaintenanceRequests\Pages\CreateMaintenanceRequest;
use App\Models\TenantRequest;
use App\Services\TenantRequestService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Regression: defects found by the pre-prod review of the tenant-request
 * generalization.
 *  - Admin-created Complaint/Access requests must get THEIR per-type SLA window,
 *    not the maintenance one (the admin create page used to call the maintenance
 *    helper for every SLA type).
 *  - The mobile API must reject a sub-category sent for a type that has none.
 */

it('computes the per-type SLA window from one shared helper', function () {
    $svc = app(TenantRequestService::class);

    // maintenance urgent = 4h, complaint urgent = 8h, access urgent = 4h.
    expect($svc->targetResolutionFor(TenantRequestType::Maintenance, 'urgent')->diffInHours(now(), true))->toEqualWithDelta(4, 0.5)
        ->and($svc->targetResolutionFor(TenantRequestType::Complaint, 'urgent')->diffInHours(now(), true))->toEqualWithDelta(8, 0.5)
        ->and($svc->targetResolutionFor(TenantRequestType::Access, 'urgent')->diffInHours(now(), true))->toEqualWithDelta(4, 0.5);

    // No-SLA types get no deadline.
    expect($svc->targetResolutionFor(TenantRequestType::Inquiry, 'urgent'))->toBeNull()
        ->and($svc->targetResolutionFor(TenantRequestType::Billing, 'high'))->toBeNull();
});

it('gives an admin-created complaint its own SLA window, not the maintenance one', function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $asset = makeAsset(['code' => 'HW']);
    $unit = makeUnit($asset);
    $tenant = makeTenant();

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($asset);

    Livewire::test(CreateMaintenanceRequest::class)
        // request_type first (its afterStateUpdated clears category), then the rest.
        ->fillForm(['request_type' => 'complaint'])
        ->fillForm([
            'tenant_id' => $tenant->id,
            'unit_id' => $unit->id,
            'category' => 'noise',
            'priority' => 'urgent',
            'title' => 'Loud music next door',
            'description' => 'Disturbing our evening diners after hours.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $request = TenantRequest::latest('id')->first();
    expect($request->request_type)->toBe(TenantRequestType::Complaint)
        // complaint-urgent = 8h; the maintenance bug would have made this 4h.
        ->and($request->target_resolution_at->diffInHours(now(), true))->toEqualWithDelta(8, 0.5);

    Filament::setTenant(null, isQuiet: true);
});

it('rejects a sub-category sent to the API for a type that has none', function () {
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant);

    $this->postJson('/api/v1/me/maintenance-requests', [
        'request_type' => 'inquiry',
        'title' => 'Hours?',
        'description' => 'What are the Eid hours?',
        'category' => 'electrical', // inquiry has no sub-categories — must be rejected
    ], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors('category');
});
