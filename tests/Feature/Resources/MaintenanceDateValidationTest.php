<?php

use App\Filament\Admin\Resources\MaintenanceRequests\Pages\CreateMaintenanceRequest;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->hw = makeAsset(['code' => 'HW']);
    $this->unit = makeUnit($this->hw, ['code' => 'HW-01']);
    $this->tenant = makeTenant();

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->hw);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function fillMaintenance(array $overrides = []): array
{
    return array_merge([
        'tenant_id' => null,
        'unit_id' => null,
        'priority' => 'medium',
        'category' => 'hvac',
        'channel' => 'portal',
        'status' => 'submitted',
        'title' => 'AC not cooling',
        'description' => 'Storefront unit stopped cooling.',
    ], $overrides);
}

it('rejects a resolution target earlier than the request creation date', function () {
    Livewire::test(CreateMaintenanceRequest::class)
        ->fillForm(fillMaintenance([
            'tenant_id' => $this->tenant->id,
            'unit_id' => $this->unit->id,
            'target_resolution_at' => now()->subDays(5)->format('Y-m-d H:i:s'),
        ]))
        ->call('create')
        ->assertHasFormErrors(['target_resolution_at' => 'after_or_equal']);
});

it('accepts a resolution target in the future', function () {
    Livewire::test(CreateMaintenanceRequest::class)
        ->fillForm(fillMaintenance([
            'tenant_id' => $this->tenant->id,
            'unit_id' => $this->unit->id,
            'target_resolution_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
        ]))
        ->call('create')
        ->assertHasNoFormErrors();
});
