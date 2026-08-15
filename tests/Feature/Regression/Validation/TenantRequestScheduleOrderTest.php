<?php

use App\Filament\Admin\Resources\TenantRequests\Pages\CreateTenantRequest;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

// Guard: TenantRequest scheduled_to must be >= scheduled_from.
// The form field `scheduled_to` carries ->afterOrEqual('scheduled_from')
// (TenantRequestForm). Mirrors MaintenanceDateValidationTest's idiom.

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

function fillScheduledMaintenance(array $overrides = []): array
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
        // Keep the resolution target valid so only the schedule rule fires.
        'target_resolution_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
    ], $overrides);
}

it('rejects a scheduled_to earlier than scheduled_from', function () {
    Livewire::test(CreateTenantRequest::class)
        ->fillForm(fillScheduledMaintenance([
            'tenant_id' => $this->tenant->id,
            'unit_id' => $this->unit->id,
            'scheduled_from' => now()->addDays(3)->format('Y-m-d H:i:s'),
            'scheduled_to' => now()->addDays(1)->format('Y-m-d H:i:s'),
        ]))
        ->call('create')
        ->assertHasFormErrors(['scheduled_to' => 'after_or_equal']);
});

it('accepts a scheduled_to equal to scheduled_from', function () {
    $at = now()->addDays(2)->format('Y-m-d H:i:s');

    Livewire::test(CreateTenantRequest::class)
        ->fillForm(fillScheduledMaintenance([
            'tenant_id' => $this->tenant->id,
            'unit_id' => $this->unit->id,
            'scheduled_from' => $at,
            'scheduled_to' => $at,
        ]))
        ->call('create')
        ->assertHasNoFormErrors(['scheduled_to']);
});

it('accepts a scheduled_to later than scheduled_from', function () {
    Livewire::test(CreateTenantRequest::class)
        ->fillForm(fillScheduledMaintenance([
            'tenant_id' => $this->tenant->id,
            'unit_id' => $this->unit->id,
            'scheduled_from' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'scheduled_to' => now()->addDays(4)->format('Y-m-d H:i:s'),
        ]))
        ->call('create')
        ->assertHasNoFormErrors();
});
