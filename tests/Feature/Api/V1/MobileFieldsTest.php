<?php

use App\Models\Payment;
use App\Models\RentableItem;

/**
 * Four fields the app wanted and the server did not send.
 *
 * Each one had the same consequence: the client either printed a **device-derived** value beside
 * server truth on a money screen, or dropped the line entirely rather than guess. None of them
 * needed a migration — the data existed and was simply never published.
 */
it('reports when an invoice was actually paid', function () {
    $tenant = makeTenant();
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()), $tenant), [
        'total' => 1000, 'balance' => 1000,
    ]);

    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'amount' => 1000,
        'currency' => 'EGP',
        'method' => 'card',
        'status' => 'captured',
        'payment_date' => '2026-08-14',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 1000]);

    // The confirmation screen stamped DateTime.now() here — on the screen tenants screenshot as
    // proof of payment, next to a server-polled amount and balance.
    $this->getJson("/api/v1/me/invoices/{$invoice->id}", apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.paidAt', fn ($v) => $v !== null && str_starts_with($v, '2026-08-14'));
});

it('reports null until the money is actually received', function () {
    $tenant = makeTenant();
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()), $tenant));

    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'amount' => 500,
        'currency' => 'EGP',
        'method' => 'card',
        // Initiated is NOT received — a card payment sits here before the gateway confirms, and
        // claiming a paid-at instant then would date money that has not arrived.
        'status' => 'initiated',
        'payment_date' => '2026-08-14',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 500]);

    $this->getJson("/api/v1/me/invoices/{$invoice->id}", apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.paidAt', null);
});

it('publishes the parking bays let with the premises', function () {
    $tenant = makeTenant();
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset), $tenant);

    $bay = RentableItem::create([
        'asset_id' => $asset->id,
        'code' => 'P-01',
        'type' => RentableItem::TYPE_PARKING,
        'monthly_rate' => 500,
    ]);
    $signage = RentableItem::create([
        'asset_id' => $asset->id,
        'code' => 'S-01',
        'type' => RentableItem::TYPE_SIGNAGE,
        'monthly_rate' => 300,
    ]);
    $lease->rentableItems()->attach([$bay->id, $signage->id]);

    // The design's parking-allocation card was omitted rather than filled with a guess. Only
    // PARKING counts — a signage panel is a rentable item too, and summing them would have made
    // the card wrong in a way nobody would spot.
    $this->getJson('/api/v1/me/leases', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.0.parkingSpots', 1);
});

it('filters payments to a real period', function () {
    $tenant = makeTenant();
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()), $tenant));

    foreach (['2026-06-10', '2026-08-10'] as $date) {
        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'amount' => 100,
            'currency' => 'EGP',
            'method' => 'cash',
            'status' => 'captured',
            'payment_date' => $date,
        ]);
        $payment->invoices()->attach($invoice->id, ['allocated_amount' => 100]);
    }

    // "Cleared this period" was neither a period nor a total: the query set was method/status/page
    // only, so there was no date to pass and the label had to be softened to "payments shown".
    $response = $this->getJson(
        '/api/v1/me/payments?from=2026-08-01&to=2026-08-31',
        apiHeaders($tenant),
    )->assertOk();

    expect($response->json('meta.total'))->toBe(1);
    $response->assertJsonPath('data.0.paymentDate', '2026-08-10');
});

it('accepts a statement window and still defaults without one', function () {
    $tenant = makeTenant();
    makeInvoice(makeLease(makeUnit(makeAsset()), $tenant));

    // The window is a parameter now: the service used to hard-code a trailing 12 months and report
    // nothing about what it covered, so the app printed a device-clock range beside the PDF.
    $windowed = $this->get('/api/v1/me/statement?from=2026-01-01&to=2026-08-31', apiHeaders($tenant));
    $windowed->assertOk();
    expect($windowed->headers->get('content-type'))->toBe('application/pdf');

    $this->get('/api/v1/me/statement', apiHeaders($tenant))->assertOk();
});
