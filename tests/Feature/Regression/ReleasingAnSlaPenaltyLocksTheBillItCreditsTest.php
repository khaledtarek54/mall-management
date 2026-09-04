<?php

use App\Models\FacilityWorkOrder;
use App\Models\SlaPenalty;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\ApplySlaPenaltyService;
use App\Services\AssessSlaPenaltyService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Tests\Support\LockSpy;

/**
 * RELEASING an SLA penalty rewrites the whole vendor bill, so it must lock the bill.
 *
 * `VendorBill::recompute()` re-derives `penalty_applied_amount` as the sum over EVERY applied
 * penalty on the bill, then `balance` and `status` from it. That makes a release a WHOLE-BILL write,
 * not a write to the penalty row — and `ApplySlaPenaltyService::toBill()` and
 * `VoidVendorBillPaymentService` both already serialise on the bill for exactly that reason.
 *
 * The two paths that RELEASE a penalty did not. `detach()` read `$locked->bill` and `waive()` read
 * `$locked->isApplied() ? $locked->bill : null` — a plain relation, under a comment saying it
 * "mirrors detach()". So a release racing an apply on the same bill recomputed from a snapshot fixed
 * before the apply committed and wrote a balance with the other penalty's deduction ERASED: the
 * payable overstated by that penalty, which is money leaving on the next payment run. And a plain
 * read there is doubly wrong — under MySQL REPEATABLE READ it also OPENS the read view before the
 * wait, so everything `recompute()` sums afterwards is answered from before it.
 *
 * `SQLiteGrammar::compileLock()` returns '', so every lock in this codebase is inert in this suite.
 * `Tests\Support\LockSpy` compiles the clause to a SQL comment so `DB::listen()` can see which table
 * a service actually locked, on the real path. The ORDER is asserted as well as the presence: it is
 * the deadlock-safety property (penalty then bill, the same order `toBill()` takes), and asserting
 * the whole ordered list is also what proves nothing ELSE in the request locked `vendor_bills` —
 * the trap that once let a late-fee test pass with its own lock deleted.
 *
 * What this still does not prove is that two transactions serialise. That needs MySQL and two
 * connections (docs/qa/scripts/race.sh), and is stated rather than implied.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset(['code' => 'SLALK']);
    $this->vendor = Vendor::create(['name' => 'LockCo', 'status' => 'active']);

    $this->order = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'external',
        'vendor_id' => $this->vendor->id,
        'title' => 'Fix chiller',
        'description' => 'Chiller down',
        'trade_id' => tradeId('hvac'),
        'priority' => 'urgent',
        'scheduled_for' => now()->toDateString(),
        'est_service_cost' => 50000,
    ]);

    $this->bill = VendorBill::create([
        'vendor_id' => $this->vendor->id,
        'asset_id' => $this->asset->id,
        'facility_work_order_id' => $this->order->id,
        'status' => 'approved',
        'category' => 'maintenance',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'subtotal' => 50000, 'vat_amount' => 0, 'total' => 50000,
        'paid_amount' => 0, 'balance' => 50000,
    ]);

    $this->penalty = SlaPenalty::create([
        'facility_work_order_id' => $this->order->id,
        'asset_id' => $this->asset->id,
        'vendor_id' => $this->vendor->id,
        'basis' => SlaPenalty::BASIS_FLAT,
        'rate' => 8000,
        'hours_over_sla' => 0,
        'amount' => 8000,
        'status' => SlaPenalty::STATUS_FINAL,
        'finalised_at' => now(),
    ]);
});

it('locks the penalty and then the bill when one is charged — the control', function () {
    // The path that always did it. It is here so the two assertions below cannot pass for the wrong
    // reason: it fixes what "correct" looks like on this table, and pins the lock ORDER the release
    // paths must not invert.
    $spy = LockSpy::watch(fn () => app(ApplySlaPenaltyService::class)->toBill($this->penalty, $this->bill));

    expect($spy->lockedTables())->toBe(['sla_penalties', 'vendor_bills'],
        'toBill() must lock the penalty and then the bill. Locked: '.implode(', ', $spy->lockedTables()));
});

it('locks the bill it credits when a penalty is detached', function () {
    app(ApplySlaPenaltyService::class)->toBill($this->penalty, $this->bill);

    $spy = LockSpy::watch(fn () => app(ApplySlaPenaltyService::class)->detach($this->penalty->fresh()));

    expect($spy->locked('vendor_bills'))->toBeTrue(
        'detach() rewrote the whole bill without locking it. Locked: '.implode(', ', $spy->lockedTables()));

    expect($spy->lockedTables())->toBe(['sla_penalties', 'vendor_bills'],
        'The bill lock must come AFTER the penalty lock — toBill() takes them in that order and the '.
        'reverse deadlocks against it. Locked: '.implode(', ', $spy->lockedTables()));
});

it('locks the bill it credits when a penalty is waived', function () {
    // waive() is the second door onto the same act, in a different service, and its own comment says
    // it "mirrors detach()" — it mirrored the defect too.
    app(ApplySlaPenaltyService::class)->toBill($this->penalty, $this->bill);

    $spy = LockSpy::watch(fn () => app(AssessSlaPenaltyService::class)
        ->waive($this->penalty->fresh(), 'Contractor called ahead'));

    expect($spy->locked('vendor_bills'))->toBeTrue(
        'waive() rewrote the whole bill without locking it. Locked: '.implode(', ', $spy->lockedTables()));

    expect($spy->lockedTables())->toBe(['sla_penalties', 'vendor_bills'],
        'Penalty first, then bill. Locked: '.implode(', ', $spy->lockedTables()));
});

it('still hands the payable back whole after either release', function () {
    // The outcome control: locking must not change what the release DOES. A guard that also broke
    // the workflow would be worse than the race.
    app(ApplySlaPenaltyService::class)->toBill($this->penalty, $this->bill);
    expect(round((float) $this->bill->fresh()->balance, 2))->toEqual(42000.0);

    app(ApplySlaPenaltyService::class)->detach($this->penalty->fresh());
    expect(round((float) $this->bill->fresh()->balance, 2))->toEqual(50000.0)
        ->and(round((float) $this->bill->fresh()->penalty_applied_amount, 2))->toEqual(0.0);

    app(ApplySlaPenaltyService::class)->toBill($this->penalty->fresh(), $this->bill->fresh());
    app(AssessSlaPenaltyService::class)->waive($this->penalty->fresh(), 'Mall caused the delay');

    expect(round((float) $this->bill->fresh()->balance, 2))->toEqual(50000.0)
        ->and($this->penalty->fresh()->vendor_bill_id)->toBeNull();
});
