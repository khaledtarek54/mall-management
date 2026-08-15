<?php

use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderItem;
use App\Services\FacilityWorkOrderService;
use Illuminate\Support\Facades\DB;

/**
 * Regression guard — the FR-PPM-07 completion gate must be lock-safe.
 *
 * Found by the adversarial review of the gate's first cut: transition() took
 * `SELECT ... FOR UPDATE` on facility_work_orders but then evaluated the gate with an
 * UNLOCKED `items()->pending()->count()`. Because markItem()/addItem() never touched the
 * order row, InnoDB had no conflicting lock and never blocked them. Reproduced on real
 * MySQL with two connections: T1 locks the order and sees pending=0; T2 un-marks an item
 * without blocking; T1 commits status='done' — a closed work order with an unchecked
 * item, unrecoverable in-app because `done` is terminal and the checklist then freezes.
 *
 * The fix makes the work order the aggregate root: every mutation of the order OR its
 * checklist goes through withOrderLock(), so they contend for one row and serialize.
 *
 * SQLite's compileLock() returns '' — `lockForUpdate` emits no SQL — so the suite
 * (sqlite :memory:) CANNOT prove the lock by inspecting queries; cf. the same admission
 * in PaymentOverAllocationGuardTest. These guards therefore work three ways:
 *   1. structurally — every mutator routes through the locking helper (reflective source
 *      gate, the same technique as PropertyIsolationConformanceTest);
 *   2. behaviourally — item writes run inside a transaction;
 *   3. by invariant — a terminal order never carries a pending item.
 * The FOR-UPDATE emission itself is asserted only when run against MySQL.
 */
beforeEach(function () {
    $this->svc = app(FacilityWorkOrderService::class);
    $this->asset = makeAsset();
});

function lockOrder(array $attrs = [], int $items = 0): FacilityWorkOrder
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

function methodSource(string $method): string
{
    $r = new ReflectionMethod(FacilityWorkOrderService::class, $method);
    $lines = file($r->getFileName());

    return implode('', array_slice($lines, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
}

/* ---- 1. structural: nothing may mutate the aggregate without the lock --- */

it('locks the work-order row inside a transaction in the locking helper', function () {
    $src = methodSource('withOrderLock');

    expect($src)->toContain('lockForUpdate');
    expect($src)->toContain('DB::transaction');
    // The lock must be on the PARENT row: a count can't lock items that don't exist yet,
    // so locking the item range would still let addItem() insert past the gate.
    expect($src)->toContain('FacilityWorkOrder::whereKey');
});

it('routes every public mutator through the locking helper', function () {
    // If a future mutator is added that writes the order or its checklist without the
    // aggregate lock, the gate silently stops being sound. Fail here instead.
    foreach (['transition', 'markItem', 'addItem', 'removeItem'] as $method) {
        expect(methodSource($method))->toContain('withOrderLock');
    }
});

it('has no public mutator that bypasses the locking helper', function () {
    $skip = ['withOrderLock', 'assertNotTerminal', 'assertChecklistComplete'];

    $mutators = collect((new ReflectionClass(FacilityWorkOrderService::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $m) => $m->class === FacilityWorkOrderService::class)
        ->reject(fn (ReflectionMethod $m) => in_array($m->name, $skip, true))
        ->map(fn (ReflectionMethod $m) => $m->name);

    expect($mutators)->not->toBeEmpty();

    foreach ($mutators as $name) {
        expect(methodSource($name))->toContain('withOrderLock');
    }
});

/* ---- 2. behavioural: item writes are transactional ---------------------- */

it('wraps every checklist mutation in a transaction', function () {
    // Read-then-act outside a transaction is what made the terminal check racy.
    $order = lockOrder(items: 1);
    $item = $order->items()->first();

    $depth = null;
    DB::listen(function ($q) use (&$depth) {
        if (str_contains($q->sql, 'facility_work_order_items') && str_starts_with(strtolower($q->sql), 'update')) {
            $depth = DB::transactionLevel();
        }
    });

    $this->svc->markItem($item, FacilityWorkOrderItem::RESULT_PASS);

    expect($depth)->toBeGreaterThan(0);
});

it('emits FOR UPDATE on the work-order row when the driver supports it', function () {
    $order = lockOrder(items: 1);
    $this->svc->markItem($order->items()->first(), FacilityWorkOrderItem::RESULT_PASS);

    DB::enableQueryLog();
    $this->svc->transition($order, 'done');

    expect(collect(DB::getQueryLog())->pluck('query')->filter(
        fn ($q) => str_contains(strtolower($q), 'for update') && str_contains($q, 'facility_work_orders')
    ))->not->toBeEmpty();
})->skip(
    fn () => DB::connection()->getDriverName() !== 'mysql',
    'lockForUpdate compiles to nothing on SQLite — run the suite against MySQL to exercise this.',
);

/* ---- 3. the invariant the lock exists to protect ------------------------ */

it('never lets a terminal order be reached with a pending item', function () {
    $order = lockOrder(items: 3);

    foreach ($order->items as $item) {
        $this->svc->markItem($item, FacilityWorkOrderItem::RESULT_PASS);
    }
    $this->svc->transition($order, 'done');

    expect($order->fresh()->status)->toBe('done');
    expect($order->fresh()->items()->pending()->count())->toBe(0);
});

it('refuses to add, mark or remove an item once the order is terminal', function () {
    // The service, not the UI, is the enforcement point — the relation manager's
    // orderEditable() reads a record loaded at page render.
    $order = lockOrder(items: 1);
    $item = $order->items()->first();
    $this->svc->transition($order, 'cancelled');

    expect(fn () => $this->svc->markItem($item->fresh(), FacilityWorkOrderItem::RESULT_PASS))
        ->toThrow(DomainException::class);
    expect(fn () => $this->svc->addItem($order->fresh(), 'Sneaky'))
        ->toThrow(DomainException::class);
    expect(fn () => $this->svc->removeItem($item->fresh()))
        ->toThrow(DomainException::class);

    expect($order->fresh()->items()->count())->toBe(1); // nothing added, nothing removed
    expect($item->fresh()->result)->toBe(FacilityWorkOrderItem::RESULT_PENDING);
});
