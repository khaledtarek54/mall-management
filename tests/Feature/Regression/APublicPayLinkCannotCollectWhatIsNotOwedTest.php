<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\WriteOffInvoiceService;
use App\Support\DemoPayments;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\Http;

/**
 * WHICH invoice a tenant may pay, and HOW MUCH, are one question each — and both had four answers.
 *
 * `/pay/{token}` is the only money surface in this system with **no login at all**: the bearer token
 * in the URL is the whole of who is asking. It is also the surface a tenant is most likely to use,
 * because the link is on every invoice email.
 *
 * **WHICH.** `Invoice::isPayable()` was a hand-rolled denylist of three statuses beside
 * `App\Support\InvoiceSettlement`, the register built for exactly this question with a reason
 * written against every status on both sides. It missed `draft` — so a never-issued invoice was
 * publicly readable and publicly payable, an eighth surface for the invariant that says the tenant
 * never sees a draft. Measured before the fix: **200**, naming the tenant and the amount. The
 * portal's View page then carried two more opinions three lines apart — `canPayDemo()` repeated the
 * same three statuses, and `canPayNow()`, the one that opens a LIVE gateway, tested no status at
 * all, so a written-off invoice offered real card checkout while the fake button beside it refused.
 *
 * **HOW MUCH.** Every path charged the raw `balance`. A write-off deliberately leaves `balance`
 * standing — that is what keeps it visible on the document — so a 10,000 invoice with 6,000 forgiven
 * asked for 10,000. Measured on the real page: it printed 10,000 and never 4,000. Paying it drives
 * AR negative for that debt and leaves bad-debt expense standing against cash that arrived, which is
 * the permanently red `billing:reconcile --deep` that blocks the next deploy.
 * `InvoiceSettlement::settleableAmount()` already answered this on seven call sites — every channel
 * an OPERATOR drives, and none a TENANT drives.
 *
 * **The first cut of this fix was worse than the bug**, and the review caught it: it moved our own
 * record to the netted figure and left `PaymobClient::buildPaymentSession()` charging the raw
 * balance. Nothing compares the gateway's `amount_cents` against our Payment row, so the tenant
 * would have been billed 10,000 against a 4,000 receipt and 6,000 of real cardholder money would
 * sit in the merchant account with no row anywhere — not unallocated, ABSENT, with
 * `billing:reconcile` green because the books stayed internally consistent about a receipt that was
 * never the size we thought.
 *
 * Every refusal here is paired with a CONTROL that must succeed, because a route that 302'd
 * everything would satisfy the refusals alone and read as a pass.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset), makeTenant(['name' => 'Cilantro Coffee']));
});

function payableInvoice(array $attrs = []): Invoice
{
    return makeInvoice(test()->lease, array_merge([
        'status' => 'issued', 'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000,
        'paid_amount' => 0, 'balance' => 10000,
        'issue_date' => CarbonImmutable::now()->subMonth(),
        'due_date' => CarbonImmutable::now()->subWeek(),
    ], $attrs));
}

it('shows a real invoice to whoever holds the link — the control', function () {
    $invoice = payableInvoice();

    $this->get('/pay/'.$invoice->paymentLinkToken())
        ->assertOk()
        ->assertSee('Cilantro Coffee')
        ->assertSee('10,000.00');

    expect($invoice->isPayable())->toBeTrue()
        ->and($invoice->payableAmount())->toEqual(10000.0);
});

it('never shows a DRAFT invoice to the public, and never takes money for one', function () {
    $draft = payableInvoice(['status' => 'draft']);

    expect($draft->isPayable())->toBeFalse();

    // **404 on every public route, not a redirect.** A draft is not a document: nothing was issued,
    // nothing was posted, and the tenant has never been told it exists — so it has no public
    // existence at all. The first cut redirected to the status page, which still printed the
    // tenant's NAME and the invoice number to whoever held the link, and (because `payableAmount()`
    // is 0 for every relieved status) rendered "✓ Payment successful — 0.00" over them.
    $token = $draft->paymentLinkToken();

    $this->get('/pay/'.$token)->assertNotFound();
    $this->get('/pay/'.$token.'/status')->assertNotFound();

    // And the money door, which is the half that cannot be undone. `draft` is what
    // `InvoiceSettlement` refuses under "nothing was ever posted, so cash against it credits a
    // receivable that does not exist".
    // The REAL key. `demo_payments.enabled` is not one — `DemoPayments::enabled()` reads
    // `integrations.demo_payments.enabled` — so the line that set it was a no-op and these cases
    // passed only because the flag is null and the env is `testing`. That is the classic no-op
    // refusal: if `DEMO_PAYMENTS_ENABLED=false` ever landed in phpunit.xml, the route would 404
    // first and "no payment was written" would pass for entirely the wrong reason.
    config(['integrations.paymob.enabled' => false, 'integrations.demo_payments.enabled' => true]);

    // Asserted, not assumed — a demo route that is switched off refuses everything.
    expect(DemoPayments::enabled())->toBeTrue();

    $this->post('/pay/'.$token.'/demo')->assertNotFound();

    expect($draft->fresh()->payments()->count())->toBe(0)
        ->and($draft->fresh()->status)->toBe('draft');
});

it('asks only for what is still collectable after a partial write-off', function () {
    $invoice = payableInvoice();

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 6000,
        'reason' => 'Settlement agreed at 40%',
        'write_off_date' => CarbonImmutable::now()->toDateString(),
    ]);

    $invoice = $invoice->fresh();

    // The premise: a write-off leaves `balance` standing deliberately, so the two figures really do
    // disagree here. Without this the test would pass on an invoice where they happen to match.
    expect(round((float) $invoice->balance, 2))->toEqual(10000.0)
        ->and($invoice->payableAmount())->toEqual(4000.0);

    $this->get('/pay/'.$invoice->paymentLinkToken())
        ->assertOk()
        ->assertSee('4,000.00')
        ->assertDontSee('10,000.00');
});

it('collects the forgiven money from nobody, even when the tenant presses pay', function () {
    $invoice = payableInvoice();

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 6000,
        'reason' => 'Settlement agreed at 40%',
        'write_off_date' => CarbonImmutable::now()->toDateString(),
    ]);

    // The REAL key. `demo_payments.enabled` is not one — `DemoPayments::enabled()` reads
    // `integrations.demo_payments.enabled` — so the line that set it was a no-op and these cases
    // passed only because the flag is null and the env is `testing`. That is the classic no-op
    // refusal: if `DEMO_PAYMENTS_ENABLED=false` ever landed in phpunit.xml, the route would 404
    // first and "no payment was written" would pass for entirely the wrong reason.
    config(['integrations.paymob.enabled' => false, 'integrations.demo_payments.enabled' => true]);

    // Asserted, not assumed — a demo route that is switched off refuses everything.
    expect(DemoPayments::enabled())->toBeTrue();

    $this->post('/pay/'.$invoice->fresh()->paymentLinkToken().'/demo');

    $payment = $invoice->fresh()->payments()->first();

    expect($payment)->not->toBeNull()
        ->and(round((float) $payment->amount, 2))->toEqual(4000.0)
        // …and the invoice is now finished from BOTH sides: the tenant paid what was claimed, the
        // write-off relieved the rest, and nothing is left to chase.
        ->and($invoice->fresh()->payableAmount())->toEqual(0.0)
        ->and($invoice->fresh()->collectableBalance())->toEqual(0.0);
});

it('refuses a written-off invoice outright, on the door the demo button already refused', function () {
    $invoice = payableInvoice();

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 10000,
        'reason' => 'Bad debt',
        'write_off_date' => CarbonImmutable::now()->toDateString(),
    ]);

    $invoice = $invoice->fresh();

    expect($invoice->status)->toBe('written_off')
        ->and($invoice->isPayable())->toBeFalse()
        ->and($invoice->payableAmount())->toEqual(0.0);
});

it('gives the portal one answer instead of two that disagree', function () {
    // `ViewInvoice::canPayNow()` (a LIVE gateway) and `canPayDemo()` sat three lines apart and
    // tested different things: the demo one carried the status denylist, the real one carried none.
    // Both now ask `isPayable()`, so the button that spends money can never be the permissive one.
    $source = sourceWithoutComments(base_path(
        'app/Filament/Portal/Resources/Invoices/Pages/ViewInvoice.php'
    ));

    expect(substr_count($source, 'isPayable()'))->toBe(2)
        ->and($source)->not->toContain('in_array($this->record->status');
});

/*
|--------------------------------------------------------------------------
| The two defects the FIRST CUT of this fix introduced
|--------------------------------------------------------------------------
| Both were caught in adversarial review, and both were worse than what they replaced. Recorded as
| tests because "we fixed the amount" is exactly the kind of claim that decays.
*/

it('charges the cardholder the same figure it records — never a penny more', function () {
    // **The critical one.** The first cut moved `Payment::$amount`, the pivot allocation and the ops
    // log to `payableAmount()` and left `PaymobClient::buildPaymentSession()` on the raw `balance`.
    // Nothing anywhere compares the gateway's `amount_cents` against our Payment row, so the tenant
    // would have been billed 10,000 against a 4,000 receipt — and 6,000 of REAL cardholder money
    // would sit in the merchant account with no row in Atriom at all. Not unallocated: absent. And
    // `billing:reconcile` stays green throughout, because our books remain perfectly consistent
    // about a receipt that was never the size we thought it was. Strictly worse than the
    // over-charge it was meant to fix, because an over-charge is at least recorded and refundable.
    $invoice = payableInvoice();

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 6000,
        'reason' => 'Settlement agreed at 40%',
        'write_off_date' => CarbonImmutable::now()->toDateString(),
    ]);

    config(['integrations.paymob.enabled' => true]);

    Http::fake([
        '*/api/auth/tokens' => Http::response(['token' => 'BEARER']),
        '*/api/ecommerce/orders' => Http::response(['id' => 987654]),
        '*/api/acceptance/payment_keys' => Http::response(['token' => 'PAYKEY']),
    ]);

    $this->post(route('pay.start', ['token' => $invoice->fresh()->paymentLinkToken()]));

    $payment = Payment::query()->where('channel', Payment::CHANNEL_LINK)->firstOrFail();

    // Our record…
    expect(round((float) $payment->amount, 2))->toEqual(4000.0)
        ->and(round((float) $payment->invoices()->first()->pivot->allocated_amount, 2))->toEqual(4000.0);

    // …and what the gateway was actually told to collect. Paymob takes piastres, so 4,000.00 EGP
    // is 400000.
    //
    // **Read off the RECORDED requests, not `Http::assertSent()`.** That helper passes when ANY
    // request satisfies the callback, so the obvious shape — `return true` for the calls you do not
    // care about — is matched by the auth-token request and can never fail. Mutation-proved: with
    // the fix reverted and the gateway back on the raw balance, the assertSent version stayed
    // GREEN. Another entry in this file's own theme of assertions that only look like proof.
    $amounts = collect(Http::recorded())
        ->mapWithKeys(function (array $pair): array {
            [$request] = $pair;

            $endpoint = match (true) {
                str_contains($request->url(), '/api/ecommerce/orders') => 'order',
                str_contains($request->url(), '/api/acceptance/payment_keys') => 'payment_key',
                default => null,
            };

            return $endpoint === null ? [] : [$endpoint => (int) ($request->data()['amount_cents'] ?? -1)];
        });

    // The premise: both calls were actually made, or an empty sweep would satisfy every assertion
    // about it below.
    expect($amounts->keys()->sort()->values()->all())->toBe(['order', 'payment_key']);

    // The ORDER and the PAYMENT KEY separately — two calls, and the cardholder is shown the second.
    expect($amounts['order'])->toBe(400000)
        ->and($amounts['payment_key'])->toBe(400000);
});

it('never tells a written-off debtor that their payment succeeded', function () {
    // The second one. `payableAmount()` returns 0 for EVERY relieved status, so reading the status
    // page's `paid` state off it made a written-off invoice render "✓ Payment successful — 0.00" on
    // a page with no login in front of it, to a tenant whose debt had just gone to bad debt — and
    // `WriteOffInvoiceService::reverse()` exists precisely because such a debt may still be chased.
    $invoice = payableInvoice();

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 10000,
        'reason' => 'Bad debt',
        'write_off_date' => CarbonImmutable::now()->toDateString(),
    ]);

    $page = $this->get('/pay/'.$invoice->fresh()->paymentLinkToken().'/status')->assertOk();

    expect($page->getContent())
        ->toContain(__('pay.states.closed.title'))
        ->not->toContain(__('pay.states.paid.title'));

    // The control: an invoice genuinely settled in full still reads as paid, or this test would be
    // satisfied by a status page that never says "paid" to anyone.
    $settled = payableInvoice(['status' => 'paid', 'paid_amount' => 10000, 'balance' => 0]);

    $this->get('/pay/'.$settled->paymentLinkToken().'/status')
        ->assertOk()
        ->assertSee(__('pay.states.paid.title'));
});
