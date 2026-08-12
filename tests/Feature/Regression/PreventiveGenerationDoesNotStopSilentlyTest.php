<?php

use App\Models\MaintenancePlan;
use App\Models\MaintenanceWorkOrder;
use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Notifications\PreventiveGenerationFailedNotification;
use App\Services\GeneratePreventiveWorkOrdersService;
use Illuminate\Support\Facades\Notification;

/**
 * A preventive plan could stop raising work orders forever, and nothing said so.
 *
 * Two correct decisions combined into a defect. `generateFor()` wraps the whole cycle in one
 * transaction, so a throw undoes `advanceDue()` along with everything else — right, because a
 * statutory round must not be skipped just because tonight's attempt failed. And `run()` contains
 * the failure per plan — right, because one bad row must not stop every other property. Together:
 * the plan retries the same cycle every night, forever, and the only trace was a `Log::warning`
 * plus a non-zero exit from a cron job with no `onFailure` hook anywhere in `routes/console.php`.
 *
 * The concrete trigger was the plan contractor's insurance lapsing. Two things were wrong there:
 * a **renewed** COI still counted as lapsed (fixed in `HasSupersededDocuments`), and a genuinely
 * lapsed one cancelled the WORK rather than the assignment. The compliance gate governs who is sent
 * to site, not whether the inspection exists — so the order is now raised unassigned, saying why.
 *
 * The plan still does not skip the cycle. What changed is that being stuck is now visible.
 */
beforeEach(function () {
    $this->gen = app(GeneratePreventiveWorkOrdersService::class);
    $this->asset = makeAsset(['code' => 'MALL']);
});

function inspectionPlanFor(?Vendor $vendor, array $attrs = []): MaintenancePlan
{
    return MaintenancePlan::create(array_merge([
        'asset_id' => test()->asset->id,
        'title' => 'Lift statutory inspection',
        'category' => 'safety',
        'frequency_unit' => 'months',
        'frequency_value' => 1,
        'next_due_date' => '2026-05-01',
        'is_active' => true,
        'vendor_id' => $vendor?->id,
    ], $attrs));
}

function lapsedContractor(): Vendor
{
    $vendor = Vendor::create(['name' => 'Uninsured Lifts', 'type' => 'contractor', 'status' => Vendor::STATUS_ACTIVE]);
    VendorDocument::create([
        'vendor_id' => $vendor->id,
        'type' => VendorDocument::TYPE_INSURANCE_COI,
        'expires_on' => '2026-01-01',
    ]);

    return $vendor;
}

it('still raises the statutory round when the contractor cannot be dispatched', function () {
    // The headline. The inspection is required whether or not this particular contractor may go —
    // and a lift that goes uninspected because a certificate expired is the outcome the compliance
    // gate exists to avoid, arrived at by the gate itself.
    $plan = inspectionPlanFor(lapsedContractor());

    expect($this->gen->run('2026-05-02'))->toBe(1);

    $order = MaintenanceWorkOrder::where('maintenance_plan_id', $plan->id)->sole();

    expect($order->vendor_id)->toBeNull()
        ->and($order->notes)->toContain('Uninsured Lifts')
        // And the plan MOVED ON, which is the whole difference: before, it re-attempted 2026-05-01
        // every night and 2026-06-01 was never reached.
        ->and($plan->fresh()->next_due_date->toDateString())->toBe('2026-06-01')
        ->and($this->gen->failures)->toBe([]);
});

it('keeps the assignment when the contractor is compliant — the paired control', function () {
    // Unassigning is a fallback, not the behaviour. If this stopped working, every generated order
    // would arrive with no contractor and the fix above would read as green.
    $vendor = Vendor::create(['name' => 'Otis Lifts', 'type' => 'contractor', 'status' => Vendor::STATUS_ACTIVE]);
    VendorDocument::create([
        'vendor_id' => $vendor->id,
        'type' => VendorDocument::TYPE_INSURANCE_COI,
        'expires_on' => '2027-12-31',
    ]);
    $plan = inspectionPlanFor($vendor);

    $this->gen->run('2026-05-02');
    $order = MaintenanceWorkOrder::where('maintenance_plan_id', $plan->id)->sole();

    expect($order->vendor_id)->toBe($vendor->id)
        ->and((string) $order->notes)->not->toContain('cannot be dispatched');
});

it('does not withhold the assignment from a contractor who RENEWED', function () {
    // The two halves meeting. Keeping last year's lapsed certificate on file is correct practice;
    // it must not cost the contractor their work.
    $vendor = lapsedContractor();
    VendorDocument::create([
        'vendor_id' => $vendor->id,
        'type' => VendorDocument::TYPE_INSURANCE_COI,
        'expires_on' => '2027-06-30',
    ]);
    $plan = inspectionPlanFor($vendor);

    $this->gen->run('2026-05-02');

    expect(MaintenanceWorkOrder::where('maintenance_plan_id', $plan->id)->sole()->vendor_id)->toBe($vendor->id);
});

it('stamps the plan that cannot generate, so being stuck is visible on the row', function () {
    // A stuck plan and an overdue plan look identical — a date in the past — which sends somebody
    // chasing a technician for a round the system never asked anybody to do.
    $plan = inspectionPlanFor(null);
    // An unknown frequency unit is the other real trigger: `advanceDue()` throws on it, reachable
    // by a direct DB edit or an import. Written past the model guard on purpose.
    MaintenancePlan::whereKey($plan->id)->update(['frequency_unit' => 'fortnights']);

    $this->gen->run('2026-05-02');
    $plan->refresh();

    expect($this->gen->failures)->toHaveKey($plan->id)
        ->and($plan->generationIsFailing())->toBeTrue()
        ->and($plan->last_generation_error)->toContain('fortnights')
        // The cycle is NOT skipped — a missed statutory round is a backlog item, not something to
        // step over. It is now a visible one.
        ->and($plan->next_due_date->toDateString())->toBe('2026-05-01');
});

it('alerts the property once when a plan first gets stuck, not every night', function () {
    $manager = makeUser('manager');
    $manager->assignedAssets()->attach($this->asset->id);

    $plan = inspectionPlanFor(null);
    MaintenancePlan::whereKey($plan->id)->update(['frequency_unit' => 'fortnights']);

    Notification::fake();
    $this->gen->run('2026-05-02');

    Notification::assertSentTo($manager, PreventiveGenerationFailedNotification::class,
        fn (PreventiveGenerationFailedNotification $n) => $n->plan->is($plan));

    // A nightly repeat of a known problem is a message people filter — and the night it means
    // something new, they filter that too.
    Notification::fake();
    $this->gen->run('2026-05-03');
    Notification::assertNothingSent();
});

it('reaches mail as well as the bell', function () {
    // The gap between "the plan stopped" and "somebody noticed" is measured in missed inspections,
    // and an inspection nobody can prove happened is what an insurer asks about after an incident.
    $via = (new PreventiveGenerationFailedNotification(inspectionPlanFor(null), 'because'))->via(makeUser('manager'));

    expect($via)->toContain('mail')->toContain('database');
});

it('clears the stamp once the plan generates again', function () {
    // A stamp that outlives its cause is worse than none: the operator learns the badge means
    // nothing and stops reading it.
    $plan = inspectionPlanFor(null);
    MaintenancePlan::whereKey($plan->id)->update(['frequency_unit' => 'fortnights']);
    $this->gen->run('2026-05-02');
    expect($plan->fresh()->generationIsFailing())->toBeTrue();

    MaintenancePlan::whereKey($plan->id)->update(['frequency_unit' => 'months']);
    $this->gen->run('2026-05-02');

    expect($plan->fresh()->generationIsFailing())->toBeFalse()
        ->and($plan->fresh()->last_generation_error)->toBeNull();
});

it('contains one stuck plan without stopping the rest of the portfolio', function () {
    // The containment that made this invisible is still the right call, and must stay working.
    $stuck = inspectionPlanFor(null, ['title' => 'Broken plan']);
    MaintenancePlan::whereKey($stuck->id)->update(['frequency_unit' => 'fortnights']);
    $healthy = inspectionPlanFor(null, ['title' => 'Chiller service']);

    expect($this->gen->run('2026-05-02'))->toBe(1)
        ->and(MaintenanceWorkOrder::where('maintenance_plan_id', $healthy->id)->count())->toBe(1)
        ->and(MaintenanceWorkOrder::where('maintenance_plan_id', $stuck->id)->count())->toBe(0);
});
