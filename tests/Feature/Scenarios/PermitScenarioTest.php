<?php

use App\Enums\TenantRequestType;
use App\Filament\Admin\Resources\TenantRequests\Pages\CreateTenantRequest;
use App\Models\TenantRequest;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * FR-REQ-13 / FR-REQ-14 — Tenant Permits. A permit is a request of the new `permit` type that
 * carries a validity window (valid_from/valid_to). It captures the FRD's fields with existing
 * columns (tenant = tenant_id/caller, item/work = description, request date = submitted_at) plus the
 * validity window. There is NO approval workflow — a permit is a typed request with a window.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'PMT']);
    $this->unit = makeUnit($this->asset, ['code' => 'PMT-01']);
    $this->tenant = makeTenant();
});

/* ---- the request type --------------------------------------------------- */

it('exposes the permit type with a localized option and PM prefix', function () {
    expect(TenantRequestType::options())->toHaveKey('permit', 'Permit');

    $permit = TenantRequestType::Permit;
    expect($permit->referencePrefix())->toBe('PM')
        ->and($permit->hasSla())->toBeFalse()
        ->and($permit->slaHours())->toBe([])
        ->and($permit->defaultDepartmentSlug())->toBe('operations')
        ->and($permit->subcategories())->toBe(['fit_out', 'temporary_installation', 'signage', 'other']);
});

/* ---- the model: validity window + ordering invariant -------------------- */

it('persists a permit request together with its validity window', function () {
    $permit = TenantRequest::create([
        'reference' => 'PM-PMT-2026-0001',
        'unit_id' => $this->unit->id,
        'tenant_id' => $this->tenant->id,
        'request_type' => 'permit',
        'title' => 'Fit-out of new coffee kiosk',
        'description' => 'Install counter, shelving and signage over the launch week.',
        'status' => 'submitted',
        'priority' => 'medium',
        'category' => 'fit_out',
        'channel' => 'portal',
        'submitted_at' => now(),
        'valid_from' => '2026-08-01',
        'valid_to' => '2026-08-14',
    ])->fresh();

    expect($permit->request_type)->toBe(TenantRequestType::Permit)
        ->and($permit->valid_from->toDateString())->toBe('2026-08-01')
        ->and($permit->valid_to->toDateString())->toBe('2026-08-14');
});

it('rejects a validity window whose end predates its start (model guard)', function () {
    expect(fn () => TenantRequest::create([
        'reference' => 'PM-PMT-2026-0002',
        'unit_id' => $this->unit->id,
        'tenant_id' => $this->tenant->id,
        'request_type' => 'permit',
        'title' => 'Backwards window',
        'description' => 'valid_to is before valid_from',
        'status' => 'submitted',
        'priority' => 'medium',
        'channel' => 'portal',
        'submitted_at' => now(),
        'valid_from' => '2026-08-14',
        'valid_to' => '2026-08-01',
    ]))->toThrow(DomainException::class);
});

it('ignores validity on a non-permit request — no window is required', function () {
    $maintenance = makeTenantRequest(['request_type' => 'maintenance'])->fresh();

    expect($maintenance->request_type)->toBe(TenantRequestType::Maintenance)
        ->and($maintenance->valid_from)->toBeNull()
        ->and($maintenance->valid_to)->toBeNull();
});

/* ---- the admin form: dates required for a permit only ------------------- */

it('requires the validity dates on the admin form for a permit', function () {
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(CreateTenantRequest::class)
        // request_type first (its afterStateUpdated clears category + reworks the reference).
        ->fillForm(['request_type' => 'permit'])
        ->fillForm([
            'tenant_id' => $this->tenant->id,
            'unit_id' => $this->unit->id,
            'priority' => 'medium',
            'title' => 'Temporary pop-up installation',
            'description' => 'Seasonal display in the atrium.',
            // valid_from / valid_to deliberately omitted.
        ])
        ->call('create')
        ->assertHasFormErrors(['valid_from', 'valid_to']);

    Filament::setTenant(null, isQuiet: true);
});

it('creates a permit through the admin form when the window is supplied', function () {
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(CreateTenantRequest::class)
        ->fillForm(['request_type' => 'permit'])
        ->fillForm([
            'tenant_id' => $this->tenant->id,
            'unit_id' => $this->unit->id,
            'priority' => 'medium',
            'category' => 'fit_out',
            'title' => 'Fit-out of unit PMT-01',
            'description' => 'Shopfitting works.',
            'valid_from' => '2026-09-01',
            'valid_to' => '2026-09-30',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $permit = TenantRequest::where('request_type', 'permit')->latest('id')->first();
    expect($permit)->not->toBeNull()
        ->and($permit->valid_from->toDateString())->toBe('2026-09-01')
        ->and($permit->valid_to->toDateString())->toBe('2026-09-30');

    Filament::setTenant(null, isQuiet: true);
});

it('does not require validity dates on the admin form for a non-permit type', function () {
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(CreateTenantRequest::class)
        ->fillForm(['request_type' => 'maintenance'])
        ->fillForm([
            'tenant_id' => $this->tenant->id,
            'unit_id' => $this->unit->id,
            'priority' => 'medium',
            'category' => 'electrical',
            'title' => 'Flickering light',
            'description' => 'Light in the storeroom keeps flickering.',
            // no validity window — must be accepted for a non-permit type.
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Filament::setTenant(null, isQuiet: true);
});
