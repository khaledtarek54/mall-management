<?php

/*
|--------------------------------------------------------------------------
| A rail activated at 10:00 is usable at 10:00, in every process
|--------------------------------------------------------------------------
| `ValueSets::forTable()` memoizes in a PHP STATIC because the guard runs on every model save. Once
| a catalogue can widen that map, the memo depends on database state — and the flush the catalogue's
| model performs on write is PROCESS-LOCAL. A `queue:work` daemon that booted before the operator
| activated Fawry keeps answering from the map it built, so a queued job saving a Fawry payment is
| REFUSED while the identical save through the web succeeds.
|
| A wrong refusal is the worse direction: the row is lost and the failure reads as a bug in the job,
| not as configuration. So `guard()` re-derives once on the refusal path before throwing — free on
| every save that passes, one query on the handful that would otherwise be refused.
|
| The same staleness arrives a second way, which this also covers: a transaction that rolls back
| after creating a rail leaves the static holding values the database no longer has.
*/

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Support\ValueSets;

it('accepts a rail activated after the guard built its map', function () {
    $tenant = makeTenant();

    // Warm the static exactly as a long-lived worker does: a save BEFORE the rail exists.
    Payment::create([
        'tenant_id' => $tenant->id,
        'amount' => 100,
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => '2026-03-01',
    ]);

    expect(ValueSets::forTable('payments')['method'] ?? [])->not->toContain('fawry');

    // The operator activates Fawry — in another process, so nothing signals this one. Simulated by
    // writing the row and then re-staling the map, which is precisely the daemon's state.
    PaymentMethod::create([
        'code' => 'fawry',
        'name_en' => 'Fawry',
        'name_ar' => 'فوري',
        'for_inbound' => true,
        'for_outbound' => false,
    ]);
    staleTheValueSetCache();

    expect(ValueSets::forTable('payments')['method'] ?? [])
        ->not->toContain('fawry', 'The premise: this process is holding a map built before the rail existed.');

    // The save must succeed anyway.
    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'amount' => 250,
        'method' => 'fawry',
        'status' => 'captured',
        'payment_date' => '2026-03-02',
    ]);

    expect($payment->fresh()->method)->toBe('fawry');
});

it('still refuses a value that is in no floor and no catalogue — the control', function () {
    $tenant = makeTenant();

    // Without this the case above could be satisfied by a guard that stopped refusing anything.
    expect(fn () => Payment::create([
        'tenant_id' => $tenant->id,
        'amount' => 100,
        'method' => 'bitcoin',
        'status' => 'captured',
        'payment_date' => '2026-03-01',
    ]))->toThrow(DomainException::class);
});
