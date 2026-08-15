<?php

use App\Models\FacilityWorkOrder;
use App\Notifications\WorkOrderAssignedNotification;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * A technician is notified when a work order is assigned to them (N2). Sharper than it looks:
 * FacilityWorkOrderResource applies AssignmentScope (FR-USR-04), so an operations user sees
 * ONLY their assigned orders — without this ping they never learn one landed on them.
 */
beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

it('notifies a technician when an open work order is assigned to them', function () {
    Notification::fake();
    $asset = makeAsset();
    $tech = makeUser('operations', [$asset->id]);
    $order = FacilityWorkOrder::create([
        'asset_id' => $asset->id, 'work_order_type' => 'ppm', 'title' => 'Filter check',
        'category' => 'hvac', 'priority' => 'medium', 'status' => 'open', 'scheduled_for' => now()->toDateString(),
    ]);

    $order->update(['assigned_to_user_id' => $tech->id]);

    Notification::assertSentTo($tech, WorkOrderAssignedNotification::class);
});

it('notifies the technician when a CM is created already assigned to them', function () {
    Notification::fake();
    $asset = makeAsset();
    $tech = makeUser('operations', [$asset->id]);

    FacilityWorkOrder::create([
        'asset_id' => $asset->id, 'work_order_type' => 'cm', 'execution_type' => 'internal',
        'title' => 'Fix pump', 'description' => 'Pump leaking', 'category' => 'plumbing',
        'priority' => 'high', 'status' => 'open', 'scheduled_for' => now()->toDateString(),
        'assigned_to_user_id' => $tech->id,
    ]);

    Notification::assertSentTo($tech, WorkOrderAssignedNotification::class);
});

it('does not re-notify on an unrelated edit (only on assignment change)', function () {
    Notification::fake();
    $asset = makeAsset();
    $tech = makeUser('operations', [$asset->id]);
    $order = FacilityWorkOrder::create([
        'asset_id' => $asset->id, 'work_order_type' => 'ppm', 'title' => 'Filter check',
        'category' => 'hvac', 'priority' => 'medium', 'status' => 'open', 'scheduled_for' => now()->toDateString(),
        'assigned_to_user_id' => $tech->id,
    ]);
    Notification::assertSentToTimes($tech, WorkOrderAssignedNotification::class, 1); // on create

    $order->update(['title' => 'Filter check + belt']); // not an assignment change

    Notification::assertSentToTimes($tech, WorkOrderAssignedNotification::class, 1); // still once
});
