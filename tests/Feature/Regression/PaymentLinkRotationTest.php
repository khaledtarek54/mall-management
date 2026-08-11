<?php

/**
 * A leaked pay link must be revocable.
 *
 * /pay/{token} is a bearer URL: no login, no expiry. Whoever holds it can read
 * the tenant's name, the line items and the amounts, and pay the invoice. That
 * is the design — it has to work from an email on a phone.
 *
 * The gap it left is that a link which goes somewhere it should not (forwarded
 * mail, shared inbox, a screenshot in a WhatsApp group, browser history on a
 * shop-floor PC) could never be taken back. Rotation is the remedy, so these
 * tests are about REVOCATION: the old URL must actually stop working, not merely
 * stop being displayed.
 */

use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Paymob\PaymobPaymentInitiator;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    config([
        'integrations.paymob.enabled' => true,
        'integrations.paymob.base_url' => 'https://sandbox.paymob.test',
        'integrations.paymob.api_key' => 'TEST-API-KEY',
        'integrations.paymob.integration_id' => '123456',
        'integrations.paymob.iframe_id' => '999',
        'integrations.paymob.currency' => 'EGP',
    ]);

    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant);
    $this->invoice = makeInvoice($this->lease, ['total' => 500, 'balance' => 500, 'status' => 'issued']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Authenticate as a role, THEN scope the panel to the asset (setTenant needs a user). */
function payLinkActAs(string $role): void
{
    test()->actingAs(makeUser($role, [test()->asset->id]));
    Filament::setTenant(test()->asset);
}

it('makes the OLD url dead, not just hidden', function () {
    $old = $this->invoice->paymentLinkToken();

    $this->get("/pay/{$old}")->assertOk();

    $new = $this->invoice->rotatePaymentLinkToken();

    // The whole point. A 200 here means the leak was never closed.
    $this->get("/pay/{$old}")->assertNotFound();
    $this->get("/pay/{$new}")->assertOk();
});

it('kills the old link on every public route, not only the landing page', function () {
    // show() is the obvious one; status() discloses the tenant and the amount too,
    // and start() would let the holder open a gateway session. All three resolve
    // through the same token, so all three must die together.
    $old = $this->invoice->paymentLinkToken();
    $this->invoice->rotatePaymentLinkToken();

    $this->get("/pay/{$old}")->assertNotFound();
    $this->get("/pay/{$old}/status")->assertNotFound();
    $this->post("/pay/{$old}/start")->assertNotFound();
});

it('issues a genuinely different token', function () {
    $old = $this->invoice->paymentLinkToken();
    $new = $this->invoice->rotatePaymentLinkToken();

    expect($new)->not->toBe($old)
        ->and(strlen($new))->toBe(48)
        ->and($this->invoice->fresh()->payment_link_token)->toBe($new);
});

it('rotates a settled invoice too — a leaked link still discloses it', function () {
    // isPayable() is false here, but /pay/{token}/status still names the tenant
    // and the amount. If rotation were gated on payability, the one invoice you
    // most want to lock down after the fact could not be locked down.
    settleInvoiceInFull($this->invoice);
    $this->invoice->refresh();

    $old = $this->invoice->paymentLinkToken();
    $this->invoice->rotatePaymentLinkToken();

    $this->get("/pay/{$old}/status")->assertNotFound();
});

it('does not strand a payment already at the gateway', function () {
    // The session is keyed by Paymob's order id, not by our token, so a client
    // who is mid-checkout when the operator rotates must still have their capture
    // land against the right invoice.
    Http::fake([
        'sandbox.paymob.test/api/auth/tokens' => Http::response(['token' => 'BEARER']),
        'sandbox.paymob.test/api/ecommerce/orders' => Http::response(['id' => 7777]),
        'sandbox.paymob.test/api/acceptance/payment_keys' => Http::response(['token' => 'PAY-KEY']),
    ]);

    $session = app(PaymobPaymentInitiator::class)->start($this->invoice, Payment::CHANNEL_LINK);

    $this->invoice->rotatePaymentLinkToken();

    // The callback's recovery path is the gateway_transaction_id, untouched by rotation.
    $payment = Payment::find($session['payment_id']);

    expect($payment->gateway_transaction_id)->toBe(PaymobPaymentInitiator::orderRef(7777))
        ->and($payment->invoices()->first()->id)->toBe($this->invoice->id);
});

/* ---- who is allowed to do it -------------------------------------------- */

it('refuses to rotate for a user without invoices.edit, even dispatched directly', function () {
    // visible() is not a gate: mountAction() never consults it, so the action is
    // dispatchable by a crafted Livewire call regardless of what the UI shows.
    // Revoking a client's access to their own invoice is a write.
    //
    // Dispatched via mountAction + callMountedAction, NOT callAction — callAction
    // asserts the action is visible first, so it would report this fixed while it
    // was still exploitable.
    payLinkActAs('viewer');

    $before = $this->invoice->payment_link_token;

    Livewire::test(ListInvoices::class)
        ->mountAction(TestAction::make('regeneratePaymentLink')->table($this->invoice))
        ->callMountedAction();

    expect($this->invoice->fresh()->payment_link_token)->toBe($before);
});

it('lets a manager rotate it', function () {
    // The other half: the gate must not be so tight that the remedy is unusable.
    payLinkActAs('manager');

    $before = $this->invoice->payment_link_token;

    Livewire::test(ListInvoices::class)
        ->mountAction(TestAction::make('regeneratePaymentLink')->table($this->invoice))
        ->callMountedAction();

    expect($this->invoice->fresh()->payment_link_token)->not->toBe($before);
});
