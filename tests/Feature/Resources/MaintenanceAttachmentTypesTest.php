<?php

use App\Filament\Portal\Resources\MaintenanceRequests\Pages\CreateMaintenanceRequest;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    ensureAllPropertiesAsset();
    Storage::fake('public');

    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant();
    $this->lease = makeLease($this->unit, $this->tenant);

    Filament::setCurrentPanel(Filament::getPanel('portal'));
    $this->actingAs(makeTenantUser($this->tenant), 'portal');
});

afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

function fillPortalMaintenance(array $overrides = []): array
{
    return array_merge([
        'title' => 'AC not cooling',
        'category' => 'hvac',
        'priority' => 'medium',
        'description' => 'Storefront unit stopped cooling.',
    ], $overrides);
}

it('rejects a non image/PDF attachment (e.g. video)', function () {
    Livewire::test(CreateMaintenanceRequest::class)
        ->fillForm(fillPortalMaintenance([
            'unit_id' => $this->unit->id,
            'attachments' => [UploadedFile::fake()->create('clip.mp4', 200, 'video/mp4')],
        ]))
        ->call('create')
        ->assertHasFormErrors(['attachments']);
});

it('accepts an image attachment', function () {
    Livewire::test(CreateMaintenanceRequest::class)
        ->fillForm(fillPortalMaintenance([
            'unit_id' => $this->unit->id,
            'attachments' => [UploadedFile::fake()->image('photo.jpg')],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();
});

it('accepts a PDF attachment', function () {
    Livewire::test(CreateMaintenanceRequest::class)
        ->fillForm(fillPortalMaintenance([
            'unit_id' => $this->unit->id,
            'attachments' => [UploadedFile::fake()->create('report.pdf', 100, 'application/pdf')],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();
});
