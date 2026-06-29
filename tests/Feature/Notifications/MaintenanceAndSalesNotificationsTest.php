<?php

use App\Models\TenantRequest;
use App\Models\TenantSalesDeclaration;
use App\Notifications\MaintenanceCommentAddedNotification;
use App\Notifications\MaintenanceStatusChangedNotification;
use App\Notifications\SalesDeclarationLockedNotification;
use App\Services\TenantRequestService;
use App\Services\PercentageRentCalculationService;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

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
        'percentage_rent_calculation_type' => 'artificial',
    ]);
    $this->operator = makeUser('manager', [$this->asset->id]);
});

it('TenantRequestService::transition notifies the tenant on status change', function () {
    Notification::fake();

    $request = TenantRequest::create([
        'reference' => 'MR-' . uniqid(),
        'tenant_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'title' => 'AC not cooling',
        'description' => 'Broken in store A-01',
        'status' => 'submitted',
        'priority' => 'high',
        'category' => 'hvac',
        'submitted_at' => now(),
    ]);

    app(TenantRequestService::class)->transition($request, 'acknowledged');

    Notification::assertSentTo(
        $this->tenant,
        MaintenanceStatusChangedNotification::class,
        fn (MaintenanceStatusChangedNotification $n) => $n->request->id === $request->id && $n->previousStatus === 'submitted'
    );
});

it('the cancelled transition does NOT fire (tenant cancelled themselves)', function () {
    Notification::fake();

    $request = TenantRequest::create([
        'reference' => 'MR-' . uniqid(),
        'tenant_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'title' => 'misfiled',
        'description' => 'never mind',
        'status' => 'submitted',
        'priority' => 'low',
        'category' => 'other',
        'submitted_at' => now(),
    ]);

    app(TenantRequestService::class)->transition($request, 'cancelled');

    Notification::assertNothingSent();
});

it('a tenant comment notifies the assigned property team (ERP bell)', function () {
    Notification::fake();

    $request = TenantRequest::create([
        'reference' => 'MR-' . uniqid(),
        'tenant_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'title' => 'AC not cooling',
        'description' => 'Broken in store A-01',
        'status' => 'submitted',
        'priority' => 'high',
        'category' => 'hvac',
        'submitted_at' => now(),
    ]);

    app(TenantRequestService::class)->comment($request, $this->tenant, 'Any update?', isInternal: false);

    Notification::assertSentTo(
        $this->operator,
        MaintenanceCommentAddedNotification::class,
        fn (MaintenanceCommentAddedNotification $n) => $n->request->id === $request->id
    );
});

it('a staff comment notifies the tenant, and internal notes notify no one', function () {
    Notification::fake();

    $request = TenantRequest::create([
        'reference' => 'MR-' . uniqid(),
        'tenant_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'title' => 'Leak',
        'description' => 'Ceiling drip',
        'status' => 'in_progress',
        'priority' => 'medium',
        'category' => 'plumbing',
        'submitted_at' => now(),
    ]);

    $svc = app(TenantRequestService::class);

    $svc->comment($request, $this->operator, 'On our way', isInternal: false);
    Notification::assertSentTo($this->tenant, MaintenanceCommentAddedNotification::class);

    // Internal note: no further notifications fan out.
    Notification::assertSentToTimes($this->tenant, MaintenanceCommentAddedNotification::class, 1);
    $svc->comment($request, $this->operator, 'Waiting on parts', isInternal: true);
    Notification::assertSentToTimes($this->tenant, MaintenanceCommentAddedNotification::class, 1);
});

it('PercentageRentCalculationService::lock notifies the tenant', function () {
    Notification::fake();

    $declaration = TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id,
        'period_start' => now()->startOfMonth()->subMonth(),
        'period_end' => now()->startOfMonth()->subDay(),
        'declared_sales' => 200000,
        'declared_at' => now()->subDays(3),
        'declared_by_type' => $this->tenant::class,
        'declared_by_id' => $this->tenant->id,
        'status' => 'submitted',
    ]);

    app(PercentageRentCalculationService::class)->lock($declaration, $this->operator);

    Notification::assertSentTo(
        $this->tenant,
        SalesDeclarationLockedNotification::class,
        fn (SalesDeclarationLockedNotification $n) => $n->declaration->id === $declaration->id
    );
});

it('locking an already-locked declaration does not re-fire the notification', function () {
    Notification::fake();

    $declaration = TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id,
        'period_start' => now()->startOfMonth()->subMonth(),
        'period_end' => now()->startOfMonth()->subDay(),
        'declared_sales' => 150000,
        'declared_at' => now(),
        'declared_by_type' => $this->tenant::class,
        'declared_by_id' => $this->tenant->id,
        'status' => 'submitted',
    ]);

    $service = app(PercentageRentCalculationService::class);
    $service->lock($declaration, $this->operator);
    $service->lock($declaration->fresh(), $this->operator);

    Notification::assertSentToTimes($this->tenant, SalesDeclarationLockedNotification::class, 1);
});
