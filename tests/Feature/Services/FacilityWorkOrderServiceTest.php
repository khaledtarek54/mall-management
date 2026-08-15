<?php

use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderItem;
use App\Services\FacilityWorkOrderService;

/**
 * The module-26 work-order state machine + the FR-PPM-07 completion gate.
 *
 * Module 26 previously had no service at all: start/complete/cancel were inline Filament
 * table actions guarded only by a permission and "not already terminal", so a work order
 * closed with none of its checklist marked.
 */
beforeEach(function () {
    $this->svc = app(FacilityWorkOrderService::class);
    $this->asset = makeAsset();
});

function makeOrder(array $attrs = [], int $items = 0): FacilityWorkOrder
{
    $order = FacilityWorkOrder::create(array_merge([
        'asset_id' => test()->asset->id,
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

/* ---- FR-PPM-07: the completion gate ------------------------------------ */

it('refuses to complete a work order while any checklist item is unmarked', function () {
    $order = makeOrder(items: 3);

    expect(fn () => $this->svc->transition($order, 'done'))
        ->toThrow(DomainException::class);

    // The refusal must leave the order untouched — not half-completed.
    expect($order->fresh()->status)->toBe('open');
    expect($order->fresh()->completed_at)->toBeNull();
});

it('names how many items are still unchecked so the engineer knows what to do', function () {
    $order = makeOrder(items: 3);
    $this->svc->markItem($order->items()->first(), FacilityWorkOrderItem::RESULT_PASS);

    expect(fn () => $this->svc->transition($order, 'done'))
        ->toThrow(DomainException::class, '2 still unchecked');
});

it('completes once every item is marked, stamping who and when', function () {
    $order = makeOrder(items: 2);
    $engineer = makeUser('operations');

    foreach ($order->items as $item) {
        $this->svc->markItem($item, FacilityWorkOrderItem::RESULT_PASS, $engineer->id);
    }
    $done = $this->svc->transition($order, 'done', $engineer->id);

    expect($done->status)->toBe('done');
    expect($done->completed_by_user_id)->toBe($engineer->id);
    expect($done->completed_at)->not->toBeNull();
});

it('lets a visit that found a fault still close — a fail is marked, not blocking', function () {
    // The whole point of a PPM visit is to find faults. Failing an item records the
    // finding (and later raises a CM); it must not trap the work order open forever.
    $order = makeOrder(items: 2);
    $this->svc->markItem($order->items()->first(), FacilityWorkOrderItem::RESULT_PASS);
    $this->svc->markItem($order->items()->orderByDesc('id')->first(), FacilityWorkOrderItem::RESULT_FAIL);

    $done = $this->svc->transition($order, 'done');

    expect($done->status)->toBe('done');
    expect($done->failedItems()->count())->toBe(1);
});

it('completes an ad-hoc order that never had a checklist', function () {
    // Vacuously complete — the gate must not strand orders with no items.
    $order = makeOrder(items: 0);

    expect($this->svc->transition($order, 'done')->status)->toBe('done');
});

it('re-blocks completion when a marked item is set back to pending', function () {
    $order = makeOrder(items: 1);
    $item = $order->items()->first();

    $this->svc->markItem($item, FacilityWorkOrderItem::RESULT_PASS);
    $this->svc->markItem($item, FacilityWorkOrderItem::RESULT_PENDING);

    expect($item->fresh()->marked_at)->toBeNull();
    expect($item->fresh()->marked_by_user_id)->toBeNull();
    expect(fn () => $this->svc->transition($order, 'done'))->toThrow(DomainException::class);
});

it('blocks completion when an item is added to a fully-marked checklist', function () {
    // The gate is evaluated against live rows, not a cached count.
    $order = makeOrder(items: 1);
    $this->svc->markItem($order->items()->first(), FacilityWorkOrderItem::RESULT_PASS);
    $order->items()->create(['label' => 'Late addition']);

    expect(fn () => $this->svc->transition($order, 'done'))->toThrow(DomainException::class);
});

/* ---- the state machine -------------------------------------------------- */

it('rejects an illegal transition', function () {
    $order = makeOrder(['status' => 'open']);

    // done and cancelled are terminal — module 11's immutability rule holds here too.
    $this->svc->transition($order, 'cancelled');

    expect(fn () => $this->svc->transition($order->fresh(), 'in_progress'))
        ->toThrow(InvalidArgumentException::class, 'Illegal transition: cancelled → in_progress');
});

it('allows open to done directly for a one-go job', function () {
    // Deliberate: forcing a Start click first is friction with no safety benefit.
    // The checklist gate — not the path to done — is the invariant.
    expect($this->svc->transition(makeOrder(), 'done')->status)->toBe('done');
});

it('cancels without demanding a marked checklist', function () {
    // Cancelling is abandoning the visit; the gate applies to completing it.
    $order = makeOrder(items: 3);

    expect($this->svc->transition($order, 'cancelled')->status)->toBe('cancelled');
});

it('freezes the checklist of a terminal order', function () {
    $order = makeOrder(items: 1);
    $this->svc->transition($order, 'cancelled');

    expect(fn () => $this->svc->markItem($order->items()->first()->fresh(), FacilityWorkOrderItem::RESULT_PASS))
        ->toThrow(DomainException::class);
});

it('rejects an unknown checklist result', function () {
    $order = makeOrder(items: 1);

    expect(fn () => $this->svc->markItem($order->items()->first(), 'maybe'))
        ->toThrow(InvalidArgumentException::class);
});
