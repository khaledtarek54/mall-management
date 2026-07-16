<?php

use App\Models\Equipment;
use App\Models\MaintenanceWorkOrder;
use App\Models\MaintenanceWorkOrderItem;
use App\Models\Vendor;
use App\Services\MaintenanceWorkOrderService;
use App\Services\RaiseCorrectiveMaintenanceService;

/**
 * Corrective maintenance (module 26, FR-CM-01/02/03/04/14/15).
 *
 * CM lives here rather than on module 11's TenantRequest because a CM raised from a failed
 * check on a common-area chiller has no tenant and no unit — both NOT NULL over there.
 */
beforeEach(function () {
    $this->svc = app(RaiseCorrectiveMaintenanceService::class);
    $this->wos = app(MaintenanceWorkOrderService::class);
    $this->asset = makeAsset(['code' => 'CMA']);
    $this->machine = Equipment::create([
        'asset_id' => $this->asset->id, 'code' => 'CH-01',
        'name_en' => 'Chiller', 'name_ar' => 'مبرد', 'category' => 'hvac',
    ]);
});

function ppmOrder(array $attrs = [], int $items = 1): MaintenanceWorkOrder
{
    $order = MaintenanceWorkOrder::create(array_merge([
        'asset_id' => test()->asset->id,
        'equipment_id' => test()->machine->id,
        'title' => 'Chiller service',
        'category' => 'hvac',
        'status' => 'open',
        'scheduled_for' => '2026-07-01',
    ], $attrs));

    for ($i = 1; $i <= $items; $i++) {
        $order->items()->create(['label' => "Check {$i}"]);
    }

    return $order->refresh();
}

function cmData(array $overrides = []): array
{
    return array_merge([
        'execution_type' => 'internal',
        'description' => 'Compressor is leaking refrigerant.',
    ], $overrides);
}

/* ---- FR-CM-01: raised from a failed check ------------------------------ */

it('raises a corrective job from a failed check', function () {
    $order = ppmOrder();
    $item = $order->items()->first();
    $this->wos->markItem($item, MaintenanceWorkOrderItem::RESULT_FAIL);

    $cm = $this->svc->fromFailedCheck($item->fresh(), cmData());

    expect($cm->work_order_type)->toBe(MaintenanceWorkOrder::TYPE_CM);
    expect($cm->source_item_id)->toBe($item->id);
    expect($cm->status)->toBe('open');
    // Where the work is comes from the failing visit — those are facts about the fault.
    expect($cm->asset_id)->toBe($this->asset->id);
    expect($cm->equipment_id)->toBe($this->machine->id);
    expect($cm->title)->toBe($item->label);
});

it('refuses to raise a corrective job from a check that has not failed', function () {
    $order = ppmOrder();
    $item = $order->items()->first();
    $this->wos->markItem($item, MaintenanceWorkOrderItem::RESULT_PASS);

    expect(fn () => $this->svc->fromFailedCheck($item->fresh(), cmData()))
        ->toThrow(DomainException::class);
});

it('refuses to raise a second corrective job for the same failed check', function () {
    // The action sits on a table row, which is exactly where a double-click lands — without
    // this, one fault becomes two jobs and two engineers.
    $order = ppmOrder();
    $item = $order->items()->first();
    $this->wos->markItem($item, MaintenanceWorkOrderItem::RESULT_FAIL);
    $this->svc->fromFailedCheck($item->fresh(), cmData());

    expect(fn () => $this->svc->fromFailedCheck($item->fresh(), cmData()))
        ->toThrow(DomainException::class);

    expect(MaintenanceWorkOrder::corrective()->count())->toBe(1);
});

it('lets the PPM visit close even though its failed check raised a corrective job', function () {
    // The visit succeeded — it found the fault. Only an UNMARKED item blocks closure.
    $order = ppmOrder();
    $item = $order->items()->first();
    $this->wos->markItem($item, MaintenanceWorkOrderItem::RESULT_FAIL);
    $this->svc->fromFailedCheck($item->fresh(), cmData());

    expect($this->wos->transition($order, 'done')->status)->toBe('done');
});

it('falls back to the check label when the optional title is left blank', function () {
    // `??` only catches null, but a cleared TextInput sends '' — which sailed through into
    // `title`, a NOT-NULL column, giving a CM with a blank title. The same bug class
    // CLAUDE.md flags for null (meter_readings.cost, leases.has_percentage_rent).
    $order = ppmOrder();
    $item = $order->items()->first();
    $this->wos->markItem($item, MaintenanceWorkOrderItem::RESULT_FAIL);

    $cm = $this->svc->fromFailedCheck($item->fresh(), cmData(['title' => '', 'scheduled_for' => '']));

    expect($cm->title)->toBe($item->label);
    expect($cm->title)->not->toBe('');
    expect($cm->scheduled_for->toDateString())->toBe(now()->toDateString());
});

it('falls back to the original title when a follow-up leaves it blank', function () {
    $order = ppmOrder(items: 0, attrs: ['title' => 'Original job']);
    $this->wos->transition($order, 'done');

    $followUp = $this->svc->asFollowUp($order->fresh(), cmData(['title' => '']));

    expect($followUp->title)->toBe('Original job');
});

/* ---- FR-CM-02/03: internal vs external, and the XOR -------------------- */

it('classifies a corrective job as internal or external', function () {
    $order = ppmOrder();
    $item = $order->items()->first();
    $this->wos->markItem($item, MaintenanceWorkOrderItem::RESULT_FAIL);
    $tech = makeUser('operations', [$this->asset->id]);

    $cm = $this->svc->fromFailedCheck($item->fresh(), cmData(['assigned_to_user_id' => $tech->id]));

    expect($cm->execution_type)->toBe('internal');
    expect($cm->assignee->id)->toBe($tech->id);
    expect($cm->vendor_id)->toBeNull();
});

it('assigns an external corrective job to a vendor, not a technician', function () {
    $order = ppmOrder();
    $item = $order->items()->first();
    $this->wos->markItem($item, MaintenanceWorkOrderItem::RESULT_FAIL);
    $vendor = Vendor::create(['name' => 'CoolAir', 'category' => 'hvac', 'status' => 'active']);
    $tech = makeUser('operations', [$this->asset->id]);

    // A caller passing both must not silently get both — the service nulls the other side.
    $cm = $this->svc->fromFailedCheck($item->fresh(), cmData([
        'execution_type' => 'external',
        'vendor_id' => $vendor->id,
        'assigned_to_user_id' => $tech->id,
    ]));

    expect($cm->execution_type)->toBe('external');
    expect($cm->vendor_id)->toBe($vendor->id);
    expect($cm->assigned_to_user_id)->toBeNull();
});

it('refuses an internal corrective job that also names a vendor', function () {
    // Module 11 lets a request carry both a staff assignee and a vendor at once, which is
    // exactly why its assignment could never discriminate internal from external. If the
    // classification doesn't constrain who is on the job, it is decorative.
    $vendor = Vendor::create(['name' => 'CoolAir', 'category' => 'hvac', 'status' => 'active']);

    expect(fn () => MaintenanceWorkOrder::create([
        'asset_id' => $this->asset->id, 'title' => 'Bad', 'category' => 'hvac',
        'scheduled_for' => '2026-07-01', 'work_order_type' => 'cm',
        'execution_type' => 'internal', 'description' => 'x', 'vendor_id' => $vendor->id,
    ]))->toThrow(InvalidArgumentException::class, 'cannot also name a vendor');
});

it('refuses an external corrective job that also names a technician', function () {
    $tech = makeUser('operations', [$this->asset->id]);

    expect(fn () => MaintenanceWorkOrder::create([
        'asset_id' => $this->asset->id, 'title' => 'Bad', 'category' => 'hvac',
        'scheduled_for' => '2026-07-01', 'work_order_type' => 'cm',
        'execution_type' => 'external', 'description' => 'x', 'assigned_to_user_id' => $tech->id,
    ]))->toThrow(InvalidArgumentException::class, 'cannot also name an in-house technician');
});

it('refuses a corrective job with no classification', function () {
    expect(fn () => MaintenanceWorkOrder::create([
        'asset_id' => $this->asset->id, 'title' => 'Bad', 'category' => 'hvac',
        'scheduled_for' => '2026-07-01', 'work_order_type' => 'cm', 'description' => 'x',
    ]))->toThrow(InvalidArgumentException::class, 'internal or external');
});

/* ---- FR-CM-04: description required ------------------------------------ */

it('requires a description on a corrective job', function () {
    expect(fn () => MaintenanceWorkOrder::create([
        'asset_id' => $this->asset->id, 'title' => 'Bad', 'category' => 'hvac',
        'scheduled_for' => '2026-07-01', 'work_order_type' => 'cm', 'execution_type' => 'internal',
    ]))->toThrow(InvalidArgumentException::class, 'requires a description');
});

it('does not impose the CM rules on a preventive order', function () {
    // PPM orders legitimately carry a department and a vendor at once and have no
    // description — the CM rules must not leak onto them.
    $vendor = Vendor::create(['name' => 'CoolAir', 'category' => 'hvac', 'status' => 'active']);

    $ppm = ppmOrder(['vendor_id' => $vendor->id]);

    expect($ppm->work_order_type)->toBe(MaintenanceWorkOrder::TYPE_PPM);
    expect($ppm->execution_type)->toBeNull();
    expect($ppm->description)->toBeNull();
});

/* ---- FR-CM-14/15: follow-ups, not reopening ---------------------------- */

it('raises a follow-up linked to a closed order without reopening it', function () {
    // The client explicitly prefers a new linked order to reopening, so the original's
    // closure record survives for audit.
    $order = ppmOrder(items: 0);
    $this->wos->transition($order, 'done');

    $followUp = $this->svc->asFollowUp($order->fresh(), cmData(['description' => 'Fix was incomplete.']));

    expect($followUp->parent_work_order_id)->toBe($order->id);
    expect($followUp->work_order_type)->toBe(MaintenanceWorkOrder::TYPE_CM);
    expect($followUp->status)->toBe('open');
    // The original is untouched — still closed, still terminal.
    expect($order->fresh()->status)->toBe('done');
    expect($order->fresh()->completed_at)->not->toBeNull();
});

it('shows the chain from both ends', function () {
    $order = ppmOrder(items: 0);
    $this->wos->transition($order, 'done');
    $followUp = $this->svc->asFollowUp($order->fresh(), cmData());

    expect($order->fresh()->followUps->pluck('id')->all())->toBe([$followUp->id]);
    expect($followUp->parentWorkOrder->id)->toBe($order->id);
});

it('chains a follow-up of a follow-up', function () {
    // Some faults take three visits. The chain must not be limited to one hop.
    $first = ppmOrder(items: 0);
    $this->wos->transition($first, 'done');
    $second = $this->svc->asFollowUp($first->fresh(), cmData());
    $this->wos->transition($second, 'cancelled');
    $third = $this->svc->asFollowUp($second->fresh(), cmData());

    expect($third->parentWorkOrder->parentWorkOrder->id)->toBe($first->id);
});

/* ---- references --------------------------------------------------------- */

it('gives corrective jobs a CM reference and preventive ones a WO reference', function () {
    // An engineer can tell a fault report from a scheduled visit by the reference alone.
    $order = ppmOrder(items: 0);
    $this->wos->transition($order, 'done');
    $cm = $this->svc->asFollowUp($order->fresh(), cmData());

    expect($order->reference)->toStartWith('WO-CMA-');
    expect($cm->reference)->toStartWith('CM-CMA-');
});
