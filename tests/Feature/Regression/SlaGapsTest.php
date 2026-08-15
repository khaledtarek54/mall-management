<?php

use App\Filament\Admin\Widgets\ActionRequired;
use App\Models\FacilityWorkOrder;
use App\Models\SlaPolicy;
use App\Services\FacilityWorkOrderService;
use App\Settings\SlaSettings;
use App\Support\SlaResolver;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * The two gaps the SLA slice shipped with, now closed.
 *
 * G1 — a breached corrective job rang the bell but stayed off the dashboard, while breached
 *      tenant requests were shown. Same urgency, same card.
 * G2 — deleting a policy was the only way to return a property to the operator default, and
 *      delete is super_admin-only project-wide, so a manager could set an override but never
 *      remove one. Deactivating is an EDIT, so it respects that invariant rather than
 *      routing around it with a "reset" action that would be delete by another name.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->svc = app(FacilityWorkOrderService::class);
    $this->asset = makeAsset(['code' => 'GAP']);
});

function breachedCm(): FacilityWorkOrder
{
    $order = FacilityWorkOrder::create([
        'asset_id' => test()->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'internal',
        'description' => 'Fault',
        'title' => 'Fix it',
        'category' => 'hvac',
        'priority' => 'urgent',
        'scheduled_for' => '2026-07-01',
    ]);

    app(FacilityWorkOrderService::class)->transition($order, 'in_progress');
    test()->travel(100)->hours();

    return $order->fresh();
}

/* ---- G2: deactivating returns a property to the default ---------------- */

it('falls back to the default once an override is deactivated', function () {
    $policy = SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 2]);
    expect(SlaResolver::hoursFor($this->asset->id, 'urgent'))->toBe(2);

    $policy->update(['is_active' => false]);

    expect(SlaResolver::hoursFor($this->asset->id, 'urgent'))->toBe(app(SlaSettings::class)->sla_urgent_hours);
});

it('lets a manager deactivate an override without needing delete rights', function () {
    // The point of the fix: delete stays super_admin-only, so an edit has to be enough.
    $policy = SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 2]);
    $manager = makeUser('manager', [$this->asset->id]);
    $this->actingAs($manager);

    expect(\App\Filament\Admin\Resources\SlaPolicies\SlaPolicyResource::canEdit($policy))->toBeTrue();
    expect(\App\Filament\Admin\Resources\SlaPolicies\SlaPolicyResource::canDelete($policy))->toBeFalse();

    $policy->update(['is_active' => false]);

    expect(SlaResolver::hoursFor($this->asset->id, 'urgent'))->not->toBe(2);
});

it('keeps the deactivated row for reference rather than losing the number', function () {
    // Better than the workaround of retyping the default into the override: a pinned copy
    // silently stops tracking the default the moment the default changes.
    $policy = SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 2]);
    $policy->update(['is_active' => false]);

    expect($policy->fresh()->resolve_hours)->toBe(2);
    expect(SlaPolicy::count())->toBe(1);
});

it('reactivating restores the override', function () {
    $policy = SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 2, 'is_active' => false]);
    expect(SlaResolver::hoursFor($this->asset->id, 'urgent'))->not->toBe(2);

    $policy->update(['is_active' => true]);

    expect(SlaResolver::hoursFor($this->asset->id, 'urgent'))->toBe(2);
});

it('defaults a new policy to active so a NOT-NULL column never receives null', function () {
    expect(SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'low', 'resolve_hours' => 5])->is_active)->toBeTrue();
});

/* ---- G1: a breached work order reaches the dashboard ------------------- */

it('shows a breached corrective job on the action-required dashboard', function () {
    breachedCm();
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(ActionRequired::class)
            ->assertSuccessful()
            ->assertSee(__('admin.widgets.action_required.wo_sla_breached_body'));
    });
});

it('does not show the card when nothing is breached', function () {
    // An accepted job still inside its SLA.
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 500]);
    $order = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id, 'work_order_type' => 'cm', 'execution_type' => 'internal',
        'description' => 'Fault', 'title' => 'Fix', 'category' => 'hvac', 'priority' => 'urgent',
        'scheduled_for' => '2026-07-01',
    ]);
    $this->svc->transition($order, 'in_progress');
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(ActionRequired::class)
            ->assertSuccessful()
            ->assertDontSee(__('admin.widgets.action_required.wo_sla_breached_body'));
    });
});

it('does not leak another property\'s breached jobs onto the dashboard', function () {
    // The widget must use visibleAssetIds() — currentAssetId() alone is null in
    // All-Properties mode and would show the whole portfolio.
    breachedCm();
    $other = makeAsset(['code' => 'GAP2']);
    $this->actingAs(makeUser('manager', [$other->id])); // cannot see GAP

    asTenant(ensureAllPropertiesAsset(), function () {
        Livewire::test(ActionRequired::class)
            ->assertSuccessful()
            ->assertDontSee(__('admin.widgets.action_required.wo_sla_breached_body'));
    });
});
