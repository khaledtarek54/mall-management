<?php

use App\Models\Payment;
use App\Models\TenantSalesDeclaration;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\TenantRequestStatusChangedNotification;
use App\Notifications\WorkOrderSlaBreachedNotification;
use App\Support\MobileNotificationLink;

/**
 * The wire contract the mobile app was found to disagree with (sync audit, 2026-08-15).
 *
 * Each case here pins a specific divergence that shipped: an endpoint the app called and the
 * backend never built, a field the app rendered and the backend never sent, or a deep link the
 * app inferred and the backend never confirmed. They are grouped in one file because they are one
 * decision — "what the app is entitled to assume" — not because they share a subject.
 */

// ============================================================================
// GET /me/payments/{id}/receipt — the app has offered this button since v0.9
// ============================================================================

function makeReceiptPayment($tenant, string $status = 'captured'): Payment
{
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()), $tenant));

    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'amount' => 1500,
        'currency' => 'EGP',
        'method' => 'cash',
        'status' => $status,
        'payment_date' => now(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 1500]);

    return $payment;
}

it('streams a receipt PDF for a payment whose money arrived', function () {
    $tenant = makeTenant();
    $payment = makeReceiptPayment($tenant);

    $response = $this->get("/api/v1/me/payments/{$payment->id}/receipt", apiHeaders($tenant))
        ->assertOk();

    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain($payment->reference)
        // A real PDF, not an empty 200 — the magic bytes are the cheapest proof the
        // service actually rendered rather than returning an empty string.
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('refuses a receipt for a payment that has not been received', function () {
    $tenant = makeTenant();
    // `initiated` is the state a card payment sits in before the gateway confirms. Issuing a
    // receipt here would assert money arrived when it has not.
    $payment = makeReceiptPayment($tenant, 'initiated');

    $this->getJson("/api/v1/me/payments/{$payment->id}/receipt", apiHeaders($tenant))
        ->assertStatus(422);
});

it('returns 404 for another tenant\'s receipt, not 403', function () {
    $mine = makeTenant();
    $theirs = makeReceiptPayment(makeTenant());

    // 404, never 403: a 403 confirms the id exists, which is what an enumeration probe asks.
    $this->getJson("/api/v1/me/payments/{$theirs->id}/receipt", apiHeaders($mine))
        ->assertNotFound();
});

// ============================================================================
// GET /me/devices — the read half of a registration surface that only had writes
// ============================================================================

it('lists the tenant\'s own registered devices without ever echoing the token', function () {
    $tenant = makeTenant();
    $tenant->deviceTokens()->create(['platform' => 'ios', 'token' => 'secret-apns', 'device_name' => 'Khaled iPhone']);

    $other = makeTenant();
    $other->deviceTokens()->create(['platform' => 'android', 'token' => 'other-fcm', 'device_name' => 'Their Pixel']);

    $response = $this->getJson('/api/v1/me/devices', apiHeaders($tenant))->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    $response->assertJsonPath('data.0.deviceName', 'Khaled iPhone')
        ->assertJsonPath('data.0.platform', 'ios');

    // The push token is a write-only credential: it must not come back on any surface.
    expect($response->getContent())->not->toContain('secret-apns')
        ->and($response->getContent())->not->toContain('other-fcm')
        ->and($response->getContent())->not->toContain('Their Pixel');
});

// ============================================================================
// GET /me — three fields the app renders that the resource never sent
// ============================================================================

it('returns every contact field that PATCH /me accepts', function () {
    $tenant = makeTenant();

    // The bug this pins: both of these were ACCEPTED and stored, then withheld on read — so the
    // tenant's own edit form could not show what it had just saved.
    $this->patchJson('/api/v1/me', [
        'contactPersonPhone' => '+20 100 555 0000',
        'address' => '12 Corniche El Nil, Cairo',
    ], apiHeaders($tenant))->assertOk();

    $this->getJson('/api/v1/me', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.contactPersonPhone', '+20 100 555 0000')
        ->assertJsonPath('data.address', '12 Corniche El Nil, Cairo');
});

it('publishes a logo url key so the app\'s avatar has somewhere to read from', function () {
    $tenant = makeTenant();

    // Null with no logo uploaded — but the KEY must be present, or the client cannot tell
    // "no logo" from "this server is too old to have the field".
    $this->getJson('/api/v1/me', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonStructure(['data' => ['logoUrl']])
        ->assertJsonPath('data.logoUrl', null);
});

it('serves auth/me and me from one controller so they cannot drift', function () {
    $tenant = makeTenant();

    $alias = $this->getJson('/api/v1/auth/me', apiHeaders($tenant))->assertOk()->json('data');
    $canonical = $this->getJson('/api/v1/me', apiHeaders($tenant))->assertOk()->json('data');

    expect($alias)->toBe($canonical);
});

// ============================================================================
// Sales declarations — "nobody has looked at this" must not read as "nothing is due"
// ============================================================================

it('reports percentage rent as null until the turnover has been entered', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, [
        'has_percentage_rent' => true, 'percentage_rent_rate' => 5,
    ]);

    $declaration = TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => now()->startOfMonth()->subMonth(),
        'period_end' => now()->startOfMonth()->subDay(),
        'status' => 'submitted',
        'declared_at' => now(),
    ]);

    // The column is NOT NULL default 0, so this used to ship `0` — indistinguishable from a
    // REVIEWED period that came in under the threshold and genuinely owes nothing.
    $this->getJson("/api/v1/me/sales-declarations/{$declaration->id}", apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.declaredSales', null)
        ->assertJsonPath('data.calculatedPercentageRent', null);
});

it('reports a real zero once the turnover has been entered', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, [
        'has_percentage_rent' => true, 'percentage_rent_rate' => 5,
    ]);

    $declaration = TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => now()->startOfMonth()->subMonth(),
        'period_end' => now()->startOfMonth()->subDay(),
        'status' => 'submitted',
        'declared_at' => now(),
        // Reviewed: staff read the report and entered a turnover below the threshold.
        'declared_sales' => 90000,
        'calculated_percentage_rent' => 0,
    ]);

    // The control for the case above: this 0 is an ANSWER and must survive as one.
    $this->getJson("/api/v1/me/sales-declarations/{$declaration->id}", apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.declaredSales', 90000)
        ->assertJsonPath('data.calculatedPercentageRent', 0);
});

// ============================================================================
// Notification deep links — stated by the registry, never inferred from a class name
// ============================================================================

it('derives a mobile deep link from the same registry the panels use', function () {
    $request = makeTenantRequest();

    $link = MobileNotificationLink::for(
        TenantRequestStatusChangedNotification::class,
        (new TenantRequestStatusChangedNotification($request, 'submitted'))->toDatabase($request->tenant),
    );

    // The exact bug: the app read `maintenanceId` while the payload has ALWAYS carried
    // `request_id`, so both tenant-request notifications deep-linked to nothing.
    expect($link)->toBe(['target' => 'request', 'id' => $request->id]);
});

it('derives the link for a payment notification too', function () {
    $tenant = makeTenant();
    $payment = makeReceiptPayment($tenant);

    $link = MobileNotificationLink::for(
        PaymentReceivedNotification::class,
        (new PaymentReceivedNotification($payment))->toDatabase($tenant),
    );

    expect($link)->toBe(['target' => 'payment', 'id' => $payment->id]);
});

it('has no link for a record the app has no screen for', function () {
    // A work order is a staff record. The honest answer is no link — NOT a link to a route the
    // app cannot open. This is the assertion that keeps TARGETS from being padded out.
    expect(MobileNotificationLink::for(WorkOrderSlaBreachedNotification::class, ['work_order_id' => 7]))
        ->toBeNull();
});

it('has no link when the id the registry names is missing from the payload', function () {
    expect(MobileNotificationLink::for(PaymentReceivedNotification::class, []))->toBeNull();
});

it('ships the link on the notification inbox so a push tap and an inbox tap agree', function () {
    $request = makeTenantRequest();
    $tenant = $request->tenant;

    $tenant->notify(new TenantRequestStatusChangedNotification($request, 'submitted'));

    $this->getJson('/api/v1/me/notifications', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.0.link.target', 'request')
        ->assertJsonPath('data.0.link.id', $request->id);
});

// ============================================================================
// Pagination — one meta shape across every list, however the payload is built
// ============================================================================

it('reports the same pagination keys on a hand-shaped payload as on a resource collection', function () {
    $tenant = makeTenant();
    makeInvoice(makeLease(makeUnit(makeAsset()), $tenant));

    $collection = $this->getJson('/api/v1/me/invoices', apiHeaders($tenant))->assertOk();

    // `from`/`to` are the two Laravel emits that the hand-rolled blocks were missing, which left
    // the client with two pagination shapes to model depending on which list it read.
    $collection->assertJsonStructure(['meta' => ['currentPage', 'lastPage', 'perPage', 'total', 'from', 'to']]);
});
