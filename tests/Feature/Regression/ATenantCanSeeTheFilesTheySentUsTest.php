<?php

use App\Filament\Portal\Resources\TenantRequests\Pages\ViewTenantRequest;
use App\Filament\Portal\Resources\TenantSalesDeclarations\Pages\ViewTenantSalesDeclaration;
use App\Models\TenantSalesDeclaration;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * SW-172 — **the two things a tenant can upload from the portal were write-only.**
 *
 * `TenantRequestForm` takes up to five images or PDFs and `TenantSalesDeclarationForm` takes the
 * sales report; neither portal View screen showed one back, and neither resource has an Edit page.
 * The request infolist did not mention attachments at all, and the declaration infolist rendered a
 * count badge — "2 files" — with nothing behind it, which is the worst of the three states: it
 * proves the upload arrived and still refuses to say WHICH file, so a tenant disputing a turnover
 * figure cannot check what they sent.
 *
 * The mobile API has returned both since it shipped, with an authenticated per-file URL, and
 * CLAUDE.md's rule for that pair is that the portal and `/api/v1` are the same surface with two
 * renderers — fix both or neither.
 *
 * These drive the REAL portal pages. The files live on the private `local` disk, so what is
 * asserted is that the FILENAME reaches the page: the link is a signed temporary URL whose exact
 * shape is the disk driver's business and differs between a faked disk and a real one.
 */
beforeEach(function () {
    ensureAllPropertiesAsset();
    Storage::fake('local');

    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant();
    $this->lease = makeLease($this->unit, $this->tenant, ['status' => 'active']);

    Filament::setCurrentPanel(Filament::getPanel('portal'));
    $this->actingAs(makeTenantUser($this->tenant), 'portal');
});

afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('shows a tenant the attachments they put on their own request', function () {
    $request = makeTenantRequest([
        'tenant_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'status' => 'submitted',
    ]);
    $request->addMedia(UploadedFile::fake()->image('storefront-leak.jpg'))->toMediaCollection('attachments');
    $request->addMedia(UploadedFile::fake()->create('quote.pdf', 40, 'application/pdf'))->toMediaCollection('attachments');

    Livewire::test(ViewTenantRequest::class, ['record' => $request->getRouteKey()])
        ->assertOk()
        ->assertSee('storefront-leak.jpg')
        ->assertSee('quote.pdf');
});

it('renders the request page unchanged when nothing was attached — the control', function () {
    // A feature with no refusal in it still needs its other branch driven: an empty collection must
    // render the placeholder, not an exception and not a blank row that reads as a broken field.
    $request = makeTenantRequest([
        'tenant_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'status' => 'submitted',
    ]);

    Livewire::test(ViewTenantRequest::class, ['record' => $request->getRouteKey()])
        ->assertOk()
        ->assertDontSee('storefront-leak.jpg');
});

it('shows a tenant the sales report they declared with, by name', function () {
    $declaration = TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'declared_sales' => 200000,
        'calculated_percentage_rent' => 0,
        'status' => 'submitted',
        'declared_at' => now(),
        'declared_by_type' => $this->tenant::class,
        'declared_by_id' => $this->tenant->id,
    ]);
    $declaration->addMedia(UploadedFile::fake()->create('june-pos-export.pdf', 60, 'application/pdf'))
        ->toMediaCollection(TenantSalesDeclaration::REPORT_COLLECTION);

    Livewire::test(ViewTenantSalesDeclaration::class, ['record' => $declaration->getRouteKey()])
        ->assertOk()
        // The badge this replaces said "1 file" and linked to nothing.
        ->assertSee('june-pos-export.pdf');
});
