<?php

use App\Models\FacilityWorkOrder;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServicePlan;
use App\Services\ApplyTenantCreditService;
use App\Services\FacilityWorkOrderService;
use App\Services\GeneratePreventiveWorkOrdersService;
use App\Services\LateFeeService;
use App\Services\LeaseCreationService;
use App\Services\VoidInvoiceService;
use App\Settings\BillingSettings;
use App\Support\ConcurrencyPolicy;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Tests\Support\LockSpy;

/**
 * The lock is REACHED on the real code path — not merely present in the source.
 *
 * `SQLiteGrammar::compileLock()` returns `''`, so on the suite's sqlite `:memory:` connection every
 * `lockForUpdate()` in the codebase compiles to nothing. All 118 critical sections were inert in
 * every test, and deleting one turned nothing red — in exactly the area this project has been bitten
 * twice (the unit double-booking race, the Paymob double-charge race).
 *
 * `Tests\Support\LockSpy` fixes the observability without changing behaviour: a lock clause is
 * appended to the compiled SELECT, and a SQL **comment** is valid SQLite, so the grammar emits a
 * marker where MySQL emits `for update`. The statement runs identically; `DB::listen()` can now see
 * which table a service locked.
 *
 * `ConcurrencyPolicyConformanceTest` pins the count of locks per file, which catches a DELETION.
 * These tests catch the subtler thing a count cannot: a lock that is still in the file but no longer
 * on the path the service actually takes.
 *
 * What this still does not prove is that two concurrent transactions serialise. That needs MySQL and
 * two connections, and is stated as out of scope rather than implied.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->asset = makeAsset(['code' => 'MALL']);

    // Auto-apply credit OFF, and this is load-bearing rather than tidiness.
    //
    // `ApplyTenantCreditService` fires on every invoice CREATE and locks `invoices` + `tenants`
    // before deciding there is no credit to spend. With it on, the late-fee test passed with
    // LateFeeService's own lock deleted — it was observing a lock taken by a different service on
    // the same table. Verified by mutation: the assertion only means "this service locks" once
    // nothing else in the request does.
    app(BillingSettings::class)->auto_apply_tenant_credit = false;
});

it('locks the UNIT when a lease is created — the double-booking race', function () {
    // The race that actually happened: `isActivelyLeased()` is check-then-act, so two signings on
    // the same vacant unit both passed the check. What must be locked is the CONTENDED row — the
    // unit — and not the lease being written, which does not exist yet and contends with nothing.
    $unit = makeUnit($this->asset);
    $tenant = makeTenant();

    $spy = LockSpy::watch(fn () => app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => $tenant->id,
        'lease' => [
            'unit_id' => $unit->id,
            'commencement_date' => '2026-01-01',
            'term_months' => 12,
            'base_rent_monthly' => 10000,
        ],
    ]));

    expect($spy->locked('units'))->toBeTrue(
        'LeaseCreationService took no lock on `units`. Locked: '.implode(', ', $spy->lockedTables()));
});

it('locks the invoice it is penalising before raising a late fee', function () {
    $lease = makeLease(makeUnit($this->asset), makeTenant(), [
        'late_fee_percent' => 5, 'late_fee_grace_days' => 0, 'late_fee_minimum' => 0,
    ]);
    makeInvoice($lease, [
        'status' => 'issued', 'issue_date' => '2026-05-01', 'due_date' => '2026-05-31',
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'paid_amount' => 0, 'balance' => 10000,
    ]);

    $spy = LockSpy::watch(fn () => app(LateFeeService::class)
        ->runForToday(CarbonImmutable::parse('2026-06-30')));

    expect($spy->locked('invoices'))->toBeTrue(
        'LateFeeService took no lock on `invoices`. Locked: '.implode(', ', $spy->lockedTables()));
});

it('locks the invoice before voiding it', function () {
    // Voiding while a payment is being allocated would strand the settlement on a document that has
    // left the books — one of the four AR settlement channels pointing at nothing.
    $lease = makeLease(makeUnit($this->asset), makeTenant());
    $invoice = makeInvoice($lease, [
        'status' => 'issued', 'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000,
        'paid_amount' => 0, 'balance' => 5000,
    ]);

    $spy = LockSpy::watch(fn () => app(VoidInvoiceService::class)->void($invoice->fresh(), 'test'));

    expect($spy->locked('invoices'))->toBeTrue(
        'VoidInvoiceService took no lock on `invoices`. Locked: '.implode(', ', $spy->lockedTables()));
});

it('locks the invoice and the tenant before spending on-account credit', function () {
    // On-account credit is one of the FOUR AR settlement channels, and the balance is DERIVED from
    // the tenant's overpayments rather than stored — so the row that must not move underneath the
    // calculation is the tenant, and the invoice being settled. Two applications reading the same
    // balance would both spend it, burying the excess as negative AR.
    $tenant = makeTenant();
    $lease = makeLease(makeUnit($this->asset), $tenant);

    $paid = makeInvoice($lease, [
        'status' => 'issued', 'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000,
        'paid_amount' => 0, 'balance' => 1000,
    ]);
    Payment::create([
        'tenant_id' => $tenant->id, 'amount' => 3000, 'currency' => 'EGP', 'method' => 'cash',
        'payment_date' => now()->toDateString(), 'status' => 'captured',
    ])->invoices()->attach($paid->id, ['allocated_amount' => 1000]);
    $paid->recomputeTotals();

    $target = makeInvoice($lease, [
        'status' => 'issued', 'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000,
        'paid_amount' => 0, 'balance' => 5000,
    ]);

    $spy = LockSpy::watch(fn () => app(ApplyTenantCreditService::class)->applyToInvoice($target->fresh()));

    expect($spy->locked('invoices'))->toBeTrue(
        'ApplyTenantCreditService took no lock on `invoices`. Locked: '.implode(', ', $spy->lockedTables()))
        ->and($spy->locked('tenants'))->toBeTrue(
            'ApplyTenantCreditService took no lock on `tenants`. Locked: '.implode(', ', $spy->lockedTables()));
});

it('locks the plan before advancing its next due date', function () {
    // Re-checked under the lock, so two overlapping sweeps cannot raise two orders for one cycle.
    ServicePlan::create([
        'asset_id' => $this->asset->id, 'title' => 'Lift inspection', 'category' => 'safety',
        'frequency_unit' => 'months', 'frequency_value' => 1,
        'next_due_date' => '2026-05-01', 'is_active' => true,
    ]);

    $spy = LockSpy::watch(fn () => app(GeneratePreventiveWorkOrdersService::class)->run('2026-05-02'));

    expect($spy->locked('service_plans'))->toBeTrue(
        'GeneratePreventiveWorkOrdersService took no lock on `service_plans`. Locked: '
        .implode(', ', $spy->lockedTables()));
});

it('locks the work order as the aggregate root for its checklist', function () {
    // Every mutation of the order OR its items goes through this one lock, which is why the items
    // table is deliberately never locked directly.
    $order = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => FacilityWorkOrder::TYPE_CM,
        'execution_type' => 'internal',
        'title' => 'Chiller', 'description' => 'Down', 'category' => 'hvac',
        'status' => 'open', 'priority' => 'high', 'scheduled_for' => now()->toDateString(),
    ]);

    $spy = LockSpy::watch(fn () => app(FacilityWorkOrderService::class)
        ->transition($order->fresh(), 'in_progress'));

    expect($spy->locked('facility_work_orders'))->toBeTrue(
        'FacilityWorkOrderService took no lock on `facility_work_orders`. Locked: '
        .implode(', ', $spy->lockedTables()));
});

it('ignores a query that takes no lock — the control that stops this passing for free', function () {
    // If the spy counted every query, all six tests above would pass with every lock removed. This
    // is the assertion that makes them mean something.
    $lease = makeLease(makeUnit($this->asset), makeTenant());
    $invoice = makeInvoice($lease, [
        'status' => 'issued', 'subtotal' => 100, 'vat_amount' => 0, 'total' => 100,
        'paid_amount' => 0, 'balance' => 100,
    ]);

    $spy = LockSpy::watch(function () use ($invoice) {
        Invoice::query()->find($invoice->id);
        Invoice::query()->where('status', 'issued')->get();
    });

    expect($spy->count())->toBe(0)
        ->and($spy->locked('invoices'))->toBeFalse();
});

it('names a real service for every PROVEN entry', function () {
    // The registry's PROVEN tier claims these are driven through a LockSpy test. If an entry is
    // added without one, the tier means nothing — it becomes a second REGISTERED with a nicer name.
    $covered = ['LeaseCreationService', 'LateFeeService', 'VoidInvoiceService',
        'ApplyTenantCreditService', 'GeneratePreventiveWorkOrdersService', 'FacilityWorkOrderService'];

    $claimed = collect(array_keys(ConcurrencyPolicy::PROVEN))
        ->map(fn (string $p): string => basename($p, '.php'))
        ->sort()->values()->all();

    expect($claimed)->toBe(collect($covered)->sort()->values()->all(),
        'PROVEN changed but this file did not. Every PROVEN entry needs a test above that drives '.
        'its service and asserts the lock, or it is only count-pinned and belongs in REGISTERED.');
});
