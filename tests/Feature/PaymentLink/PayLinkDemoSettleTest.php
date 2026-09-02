<?php

/**
 * The demo settle button on the PUBLIC pay page.
 *
 * `/pay/{token}/demo` is the only route under `/pay` that writes money, and it is the only
 * demo-pay surface with no actor behind it — the portal asks `Portal::isAdmin()`, the mobile
 * endpoint runs under a Sanctum tenant token, and this one has nothing but the bearer token in
 * the URL. It exists so a link copied out of the admin panel can be clicked through end to end on
 * a box with no gateway.
 *
 * So most of this file is about what keeps it shut, and every refusal is paired with a control
 * that must succeed — a route that 404'd unconditionally would satisfy the refusals alone and
 * read as a pass.
 */

use App\Models\Payment;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config([
        'integrations.paymob.enabled' => false,
        'integrations.demo_payments.enabled' => true,
    ]);

    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant);
    $this->invoice = makeInvoice($this->lease, ['total' => 500, 'balance' => 500, 'status' => 'issued']);
    $this->token = $this->invoice->paymentLinkToken();
});

/* ---- the control: it actually works ------------------------------------- */

it('settles the invoice through the real capture path', function () {
    $this->post("/pay/{$this->token}/demo")
        ->assertRedirect(route('pay.status', ['token' => $this->token]));

    $this->invoice->refresh();
    $payment = $this->invoice->payments()->first();

    // Not "a row was written" — the invoice has to be genuinely settled, which means
    // recomputeTotals ran off a captured, allocated payment.
    expect((float) $this->invoice->balance)->toBe(0.0)
        ->and($this->invoice->status)->toBe('paid')
        ->and($payment->status)->toBe('captured')
        ->and($payment->gateway)->toBe('demo')
        ->and((float) $payment->amount)->toBe(500.0);
});

it('records the payment_link channel so the status page can find it', function () {
    // Not cosmetic. status() looks the payment up by `where('channel', CHANNEL_LINK)`, so a
    // null-channel capture leaves it reporting a paid invoice for 0.00 — the page would look
    // broken in exactly the flow this feature exists to demonstrate.
    $this->post("/pay/{$this->token}/demo");

    expect($this->invoice->payments()->first()->channel)->toBe(Payment::CHANNEL_LINK);

    $this->get("/pay/{$this->token}/status")
        ->assertOk()
        ->assertSee('500.00');
});

it('shows the demo button on the pay page, and not the unavailable notice', function () {
    $this->get("/pay/{$this->token}")
        ->assertOk()
        ->assertSee(__('pay.pay_demo'))
        ->assertSee(__('pay.demo_note'))
        ->assertDontSee(__('pay.unavailable'));
});

/* ---- what keeps it shut -------------------------------------------------- */

it('is a flat 404 on production, whatever the flag says', function () {
    // The condition that cannot be misconfigured. DemoPayments checks the environment first and
    // independently, so a box that somehow ships with the flag on is still refused.
    //
    // A real CSRF token is supplied rather than the middleware disabled. Laravel exempts the
    // token check while the app reports the `testing` environment, so pretending to be production
    // re-arms it and the request 419s before reaching the controller — which would pass this test
    // for the wrong reason, and keep passing if the production guard were deleted.
    app()['env'] = 'production';
    config(['integrations.demo_payments.enabled' => true]);

    $this->withSession(['_token' => 'csrf-tok'])
        ->post("/pay/{$this->token}/demo", ['_token' => 'csrf-tok'])
        ->assertNotFound();

    expect((float) $this->invoice->fresh()->balance)->toBe(500.0);
});

it('is a flat 404 when the flag is explicitly off', function () {
    config(['integrations.demo_payments.enabled' => false]);

    $this->post("/pay/{$this->token}/demo")->assertNotFound();

    expect((float) $this->invoice->fresh()->balance)->toBe(500.0);
});

it('is a flat 404 once a real gateway is live', function () {
    // The two payment paths must never both be open: a client offered both takes the free one.
    config(['integrations.paymob.enabled' => true]);

    $this->post("/pay/{$this->token}/demo")->assertNotFound();

    expect((float) $this->invoice->fresh()->balance)->toBe(500.0);

    // ...and the page offers the gateway instead, not the demo button.
    $this->get("/pay/{$this->token}")
        ->assertSee(__('pay.pay_with_card'))
        ->assertDontSee(__('pay.pay_demo'));
});

it('answers 404 for a disabled box BEFORE resolving the token', function () {
    // A bad token and a switched-off feature must be indistinguishable, so the endpoint cannot be
    // probed for which invoices exist. Same status for both, checked in that order.
    config(['integrations.demo_payments.enabled' => false]);

    $this->post("/pay/{$this->token}/demo")->assertNotFound();
    $this->post('/pay/not-a-real-token/demo')->assertNotFound();
});

it('dies with the token when the link is rotated', function () {
    $old = $this->token;
    $this->invoice->rotatePaymentLinkToken();

    $this->post("/pay/{$old}/demo")->assertNotFound();

    expect((float) $this->invoice->fresh()->balance)->toBe(500.0);
});

/* ---- it cannot be pressed twice ------------------------------------------ */

it('does not double-settle an invoice that is already paid', function () {
    $this->post("/pay/{$this->token}/demo");
    $this->post("/pay/{$this->token}/demo")
        ->assertRedirect(route('pay.status', ['token' => $this->token]));

    // One payment, not two — the second press finds nothing payable and says so quietly.
    expect($this->invoice->payments()->count())->toBe(1)
        ->and((float) $this->invoice->fresh()->balance)->toBe(0.0);
});

it('refuses a cancelled invoice without writing anything', function () {
    $this->invoice->forceFill(['status' => 'cancelled'])->save();

    $this->post("/pay/{$this->token}/demo")
        ->assertRedirect(route('pay.status', ['token' => $this->token]));

    expect($this->invoice->payments()->count())->toBe(0);
});

/* ---- the only record of who did it --------------------------------------- */

it('logs the fabricated payment, because the request is anonymous by construction', function () {
    // The portal and the mobile endpoint can name a user. This one cannot, so the ops log is the
    // whole audit trail for a demo capture on a shared box. OpsLog writes to the `ops` CHANNEL,
    // so a bare Log::shouldReceive('warning') would never match and the test would pass on a
    // deleted log line.
    Log::shouldReceive('channel')->with('ops')->andReturnSelf();
    // **Variadic, because a `withArgs` closure with fixed parameters THROWS rather than
    // not-matching.** Mockery invokes the matcher for every `warning()` call on the facade, and any
    // other line in the request — `SealedPeriod`'s fail-open catch logs a single string when the
    // chart cannot answer a journalizer, which this fixture's books cannot — arrives with one
    // argument against a two-parameter closure and raises `ArgumentCountError`. That surfaces as a
    // **500 on the route**, so the test reports the endpoint as broken when what is strict is the
    // test. Shape-check first, then assert.
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (...$args) => ($args[0] ?? null) === 'invoice.demo_paid_via_link'
            && is_array($args[1] ?? null)
            && $args[1]['invoice_id'] === $this->invoice->id
            && $args[1]['amount'] === 500.0
            && array_key_exists('ip', $args[1]));

    // …and a catch-all AFTER it, or any other warning in the request is an *unexpected call* and
    // Mockery throws — again surfacing as a 500 on the route.
    //
    // **Narrowed to everything that is NOT this event**, because a bare `zeroOrMoreTimes()` quietly
    // turns the `once()` above into `atLeastOnce()`: a SECOND emission of the ops line would fall
    // through to the catch-all and pass. Measured against Mockery — with the blanket form, logging
    // the line twice passed; with this one it fails, and the three cases that must fail (line
    // missing, nothing logged, wrong payload) still do.
    Log::shouldReceive('warning')
        ->zeroOrMoreTimes()
        ->withArgs(fn (...$args) => ($args[0] ?? null) !== 'invoice.demo_paid_via_link');
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $this->post("/pay/{$this->token}/demo")->assertRedirect();
});
