<?php

use App\Filament\Portal\Resources\TenantSalesDeclarations\Pages\CreateTenantSalesDeclaration;
use App\Notifications\PortalMaintenanceSubmittedNotification;
use App\Notifications\SalesDeclarationSubmittedNotification;
use App\Services\TenantRequestService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset);
    $this->lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active',
        'has_percentage_rent' => true,
        'percentage_rent_threshold' => 100000,
        'percentage_rent_rate' => 5.0,
    ]);

    // Two operators assigned to this asset; one not assigned.
    $this->managerOnAsset = makeUser('manager', [$this->asset->id]);
    $this->maintOnAsset = makeUser('operations', [$this->asset->id]);
    $this->managerOffAsset = makeUser('manager');
});

it('a portal maintenance submission notifies assigned managers + operationss, not others', function () {
    Notification::fake();

    app(TenantRequestService::class)->create([
        'title' => 'AC out',
        'description' => 'storefront is hot',
        'priority' => 'high',
        'category' => 'hvac',
        'unit_id' => $this->unit->id,
    ], $this->tenant);

    Notification::assertSentTo($this->managerOnAsset, PortalMaintenanceSubmittedNotification::class);
    Notification::assertSentTo($this->maintOnAsset, PortalMaintenanceSubmittedNotification::class);
    Notification::assertNotSentTo($this->managerOffAsset, PortalMaintenanceSubmittedNotification::class);
});

it('a super_admin always receives operator-side notifications, even when not assigned to the asset', function () {
    Notification::fake();
    $superAdmin = makeUser('super_admin'); // deliberately not assigned to any asset

    app(TenantRequestService::class)->create([
        'title' => 'AC out',
        'description' => 'storefront is hot',
        'priority' => 'high',
        'category' => 'hvac',
        'unit_id' => $this->unit->id,
    ], $this->tenant);

    // Super_admin is in even though off-asset; assigned property staff still get it.
    Notification::assertSentTo($superAdmin, PortalMaintenanceSubmittedNotification::class);
    Notification::assertSentTo($this->managerOnAsset, PortalMaintenanceSubmittedNotification::class);
});

it('a portal sales declaration submission notifies assigned managers + leasings', function () {
    Notification::fake();
    $leasingOnAsset = makeUser('leasing', [$this->asset->id]);
    $leasingOffAsset = makeUser('leasing');

    Filament::setCurrentPanel(Filament::getPanel('portal'));
    $this->actingAs(makeTenantUser($this->tenant), 'portal');

    Livewire::test(CreateTenantSalesDeclaration::class)
        ->fillForm([
            'lease_id' => $this->lease->id,
            'period_start' => now()->startOfMonth()->subMonth()->toDateString(),
            'period_end' => now()->startOfMonth()->subDay()->toDateString(),
            'declared_sales' => 150000,
        ])
        ->call('create');

    Notification::assertSentTo($this->managerOnAsset, SalesDeclarationSubmittedNotification::class);
    Notification::assertSentTo($leasingOnAsset, SalesDeclarationSubmittedNotification::class);
    Notification::assertNotSentTo($leasingOffAsset, SalesDeclarationSubmittedNotification::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('when no roles match (test env without seeded roles) the notification path silently skips', function () {
    Notification::fake();

    // Drop every Spatie role row so role(['manager', ...]) returns nothing.
    Role::query()->delete();

    app(TenantRequestService::class)->create([
        'title' => 'No one to receive',
        'description' => 'silent failure check',
        'priority' => 'medium',
        'category' => 'other',
        'unit_id' => $this->unit->id,
    ], $this->tenant);

    Notification::assertNothingSent();
});
