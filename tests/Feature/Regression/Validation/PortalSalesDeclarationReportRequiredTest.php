<?php

use App\Filament\Portal\Resources\TenantSalesDeclarations\Pages\CreateTenantSalesDeclaration;
use App\Models\TenantSalesDeclaration;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| GUARD: PortalSalesDeclarationReportRequired
|--------------------------------------------------------------------------
| The portal sales-declaration form is now FILE-FIRST: the tenant attaches a
| sales report (image/PDF) instead of typing a figure; the property team reads
| the number off it and locks to bill. Form rules:
|   • sales_report is required (SpatieMediaLibraryFileUpload->required())
|   • only image/PDF files are accepted
|   • period_end >= period_start (DatePicker->afterOrEqual('period_start'))
|
| The portal scopes via the logged-in TenantUser (the 'portal' guard), not
| Filament tenancy — so we set the portal as the current panel and authenticate
| an ADMIN tenant user (only admins may submit, per the resource's canCreate()).
|
| The lease <Select> only offers ACTIVE leases for the portal tenant that have
| percentage rent, so the fixture lease must satisfy all three.
*/

beforeEach(function () {
    // Successful create fans out operator-staff notifications via spatie roles,
    // so seed the role/permission catalogue (mirrors the other portal tests).
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    Storage::fake('local');
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
    ], $overrides);
}

it('requires a sales report file', function () {
    Livewire::test(CreateTenantSalesDeclaration::class)
        ->fillForm(fillDeclaration(['lease_id' => $this->lease->id]))
        ->call('create')
        ->assertHasFormErrors(['sales_report' => 'required']);
});

it('accepts a PDF sales report (successful create, no figure yet)', function () {
    Livewire::test(CreateTenantSalesDeclaration::class)
        ->fillForm(fillDeclaration([
            'lease_id' => $this->lease->id,
            'sales_report' => [UploadedFile::fake()->create('may-sales.pdf', 100, 'application/pdf')],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $declaration = TenantSalesDeclaration::first();
    expect($declaration->declared_sales)->toBeNull();
    expect($declaration->getMedia('sales_report'))->toHaveCount(1);
});

it('rejects a non image/PDF report file', function () {
    Livewire::test(CreateTenantSalesDeclaration::class)
        ->fillForm(fillDeclaration([
            'lease_id' => $this->lease->id,
            'sales_report' => [UploadedFile::fake()->create('clip.mp4', 200, 'video/mp4')],
        ]))
        ->call('create')
        ->assertHasFormErrors(['sales_report']);
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
            'sales_report' => [UploadedFile::fake()->create('may-sales.pdf', 100, 'application/pdf')],
        ]))
        ->call('create')
        ->assertHasNoFormErrors(['period_end']);
});
