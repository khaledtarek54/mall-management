<?php

use App\Filament\Portal\Resources\TenantSalesDeclarations\Pages\CreateTenantSalesDeclaration;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| GUARD: PortalSalesDeclarationNonNegative
|--------------------------------------------------------------------------
| Portal TenantSalesDeclaration form rules:
|   • declared_sales must be >= 0 (TextInput->minValue(0))
|   • period_end >= period_start (DatePicker->afterOrEqual('period_start'))
|
| The portal scopes via the logged-in TenantUser (the 'portal' guard), not
| Filament tenancy — so we set the portal as the current panel (so Livewire
| mounts the portal Create page) and authenticate an ADMIN tenant user (only
| admins may submit, per TenantSalesDeclarationResource::canCreate()).
|
| The lease <Select> only offers ACTIVE leases for the portal tenant that have
| percentage rent, so the fixture lease must satisfy all three.
*/

beforeEach(function () {
    // Successful create fans out operator-staff notifications via spatie roles,
    // so seed the role/permission catalogue (mirrors the other portal tests).
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    Filament::setCurrentPanel(Filament::getPanel('portal'));

    $this->tenant = makeTenant();
    $this->lease = makeLease(
        makeUnit(makeAsset()),
        $this->tenant,
        ['status' => 'active', 'has_percentage_rent' => true],
    );

    // Admin tenant user — only admins may submit declarations.
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: true), 'portal');
});

afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

/** A valid declaration payload; override individual fields per case. */
function fillDeclaration(array $overrides = []): array
{
    return array_merge([
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
        'declared_sales' => 50000,
    ], $overrides);
}

it('rejects a negative declared_sales', function () {
    Livewire::test(CreateTenantSalesDeclaration::class)
        ->fillForm(fillDeclaration([
            'lease_id' => $this->lease->id,
            'declared_sales' => -1,
        ]))
        ->call('create')
        ->assertHasFormErrors(['declared_sales' => 'min']);
});

it('accepts a zero declared_sales', function () {
    Livewire::test(CreateTenantSalesDeclaration::class)
        ->fillForm(fillDeclaration([
            'lease_id' => $this->lease->id,
            'declared_sales' => 0,
        ]))
        ->call('create')
        ->assertHasNoFormErrors(['declared_sales']);
});

it('accepts a positive declared_sales (successful create)', function () {
    Livewire::test(CreateTenantSalesDeclaration::class)
        ->fillForm(fillDeclaration([
            'lease_id' => $this->lease->id,
            'declared_sales' => 50000,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();
});

it('rejects a period_end before period_start', function () {
    Livewire::test(CreateTenantSalesDeclaration::class)
        ->fillForm(fillDeclaration([
            'lease_id' => $this->lease->id,
            'period_start' => '2026-05-31',
            'period_end' => '2026-05-01',
        ]))
        ->call('create')
        ->assertHasFormErrors(['period_end' => 'after_or_equal']);
});

it('accepts a period_end equal to period_start', function () {
    Livewire::test(CreateTenantSalesDeclaration::class)
        ->fillForm(fillDeclaration([
            'lease_id' => $this->lease->id,
            'period_start' => '2026-05-15',
            'period_end' => '2026-05-15',
        ]))
        ->call('create')
        ->assertHasNoFormErrors(['period_end']);
});
