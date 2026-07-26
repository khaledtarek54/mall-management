<?php

use App\Filament\Portal\Resources\TenantSalesDeclarations\Pages\CreateTenantSalesDeclaration;
use App\Models\TenantRequest;
use App\Models\TenantSalesDeclaration;
use App\Services\TenantRequestService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Portal cross-tenant WRITE guards (security sweep, module 03)
|--------------------------------------------------------------------------
| The portal create forms scope their lease/unit <Select> options to the
| signed-in tenant — but options scope the RENDERING, not the payload. A
| crafted Livewire submit can post ANOTHER retailer's lease_id / unit_id.
| The mobile API already clamps both (CreateSalesDeclarationAction /
| CreateTenantRequestRequest); these pin the equivalent portal guards, which
| were missing (a portal user could plant a sales declaration on a competitor's
| lease — DoS'ing their reporting — or file a request against another tenant's
| unit — leaking that unit and misrouting to its property's staff).
*/

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    Storage::fake('local');

    $this->asset = makeAsset();
    $this->tenantA = makeTenant(['name' => 'Aroma Coffee Co.']);
    $this->tenantB = makeTenant(['name' => 'Borealis Books']);

    $this->unitA = makeUnit($this->asset);
    $this->unitB = makeUnit($this->asset);
    $this->leaseA = makeLease($this->unitA, $this->tenantA, ['status' => 'active', 'has_percentage_rent' => true]);
    $this->leaseB = makeLease($this->unitB, $this->tenantB, ['status' => 'active', 'has_percentage_rent' => true]);
});

it('refuses a portal sales declaration crafted onto another retailer\'s lease', function () {
    Filament::setCurrentPanel(Filament::getPanel('portal'));
    $this->actingAs(makeTenantUser($this->tenantA, isAdmin: true), 'portal');

    // Tenant A crafts a declaration submit pointed at tenant B's lease.
    try {
        Livewire::test(CreateTenantSalesDeclaration::class)
            ->fillForm([
                'lease_id' => $this->leaseB->id,
                'period_start' => '2026-05-01',
                'period_end' => '2026-05-31',
                'sales_report' => [UploadedFile::fake()->create('sales.pdf', 100, 'application/pdf')],
            ])
            ->call('create');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }

    // No declaration was planted on B's lease (whichever way the guard fired).
    expect(TenantSalesDeclaration::where('lease_id', $this->leaseB->id)->exists())->toBeFalse();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('still lets a portal admin declare sales on their OWN lease', function () {
    Filament::setCurrentPanel(Filament::getPanel('portal'));
    $this->actingAs(makeTenantUser($this->tenantA, isAdmin: true), 'portal');

    Livewire::test(CreateTenantSalesDeclaration::class)
        ->fillForm([
            'lease_id' => $this->leaseA->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'sales_report' => [UploadedFile::fake()->create('sales.pdf', 100, 'application/pdf')],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(TenantSalesDeclaration::where('lease_id', $this->leaseA->id)->count())->toBe(1);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('clamps a request-create unit_id/lease_id to the tenant\'s own — never another retailer\'s', function () {
    // Tenant A crafts a request pointed at tenant B's unit + lease (the raw-payload attack).
    $request = app(TenantRequestService::class)->create([
        'title' => 'Crafted',
        'description' => 'Filed against a competitor\'s unit',
        'unit_id' => $this->unitB->id,
        'lease_id' => $this->leaseB->id,
    ], $this->tenantA);

    // The service derives unit + lease from A's OWN lease — B's are never persisted.
    expect($request->tenant_id)->toBe($this->tenantA->id)
        ->and($request->unit_id)->toBe($this->unitA->id)
        ->and($request->lease_id)->toBe($this->leaseA->id);

    expect(TenantRequest::where('unit_id', $this->unitB->id)->exists())->toBeFalse();
});
