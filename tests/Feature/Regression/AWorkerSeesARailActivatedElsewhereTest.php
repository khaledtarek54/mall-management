<?php

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Support\ValueSets;
use Illuminate\Support\Facades\DB;

/**
 * **A long-lived process must not refuse a value the operator has just added.**
 *
 * `ValueSets::guard()` carries a last chance before it throws: for a catalogue-widened column it
 * flushes and re-derives the enforced set, because — in its own words — "a `queue:work` daemon that
 * started before an operator activated Fawry keeps answering from a map built without it … A wrong
 * REFUSAL is the worse direction: the row is lost and the failure looks like a bug in the job."
 *
 * **That recovery was a no-op from the day it was written.** `flushCatalogueCache()` cleared only
 * `ValueSets::$byTable`; the rebuild then called `PaymentMethod::inboundCodes()`, which answered from
 * the CONTAINER memo — the very cache that was stale. So the re-derive re-read what it was trying to
 * get past. Laravel does not save it either: a worker resets `forgetScopedInstances()` between jobs,
 * and these are plain instance bindings.
 *
 * ## Simulating another process, honestly
 *
 * The row is inserted with a raw `DB::table()->insert()` so no Eloquent event fires and no flush
 * happens — which is exactly what a SECOND process looks like from in here. Reading through the
 * catalogue first is what makes the memo stale; without that the test would pass on an empty cache
 * and prove nothing.
 */
it('accepts a rail another process activated, without waiting for a restart', function () {
    // 1. This "worker" answers once, filling the memo.
    expect(PaymentMethod::inboundCodes())->toBeArray()
        ->and(ValueSets::allowed('payments', 'method'))->not->toContain('fawry');

    // 2. Another process activates Fawry. No model event reaches us.
    DB::table('payment_methods')->insert([
        'code' => 'fawry',
        'name_en' => 'Fawry',
        'name_ar' => 'فوري',
        'for_inbound' => true,
        'for_outbound' => false,
        'is_active' => true,
        'settlement_days' => 0,
        'sort_order' => 99,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 3. The memo is genuinely stale — the premise, asserted rather than assumed.
    expect(PaymentMethod::inboundCodes())->not->toContain('fawry');

    // 4. The save must SUCCEED. `guard()` refuses on the stale set, re-derives on the refusal path,
    //    and finds the rail. Before the fix this threw DomainException.
    $tenant = makeTenant();
    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'amount' => 100,
        'method' => 'fawry',
        'payment_date' => now()->toDateString(),
        'status' => 'captured',
    ]);

    expect($payment->fresh()->method)->toBe('fawry');
});

it('still refuses a value no process ever added', function () {
    // The control. A recovery that re-derived and then accepted anything would satisfy the case
    // above just as well, and would delete the guard.
    expect(fn () => Payment::create([
        'tenant_id' => makeTenant()->id,
        'amount' => 100,
        'method' => 'carrier_pigeon',
        'payment_date' => now()->toDateString(),
        'status' => 'captured',
    ]))->toThrow(DomainException::class);
});
