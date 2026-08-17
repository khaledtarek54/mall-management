<?php

/**
 * Regression: clicking "Pay with card" on the public pay link did nothing.
 *
 * The pay pages carry a deliberately tight CSP, and it said `form-action
 * 'self'`. That directive is checked against every hop of a form submission's
 * navigation — the redirect chain included — so the browser accepted the POST
 * to /pay/{token}/start and then refused the 302 to accept.paymob.com. From the
 * server's side everything succeeded: a Paymob order was opened, an `initiated`
 * Payment was written, `paymob.session_started` was logged. From the client's
 * side the button was inert and the only evidence was a console violation.
 *
 * The whole suite was green, because Laravel's test client does not enforce
 * CSP: PaymentLinkFlowTest's `assertRedirect()` to the gateway passed the entire
 * time the button was broken. So these tests assert the property the browser
 * actually checks — that the Location the hand-off redirects to is an origin the
 * page's OWN policy permits — rather than that either half looks right alone.
 */

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('integrations.paymob.enabled', true);
    config()->set('integrations.paymob.api_key', 'k');
    config()->set('integrations.paymob.integration_id', '111');
    config()->set('integrations.paymob.iframe_id', '222');

    Http::fake([
        '*/api/auth/tokens' => Http::response(['token' => 'BEARER']),
        '*/api/ecommerce/orders' => Http::response(['id' => 4242]),
        '*/api/acceptance/payment_keys' => Http::response(['token' => 'PAYKEY']),
    ]);
});

/** The `form-action` sources from a policy header, e.g. ["'self'", 'https://…']. */
function formActionSources(?string $csp): array
{
    expect($csp)->not->toBeNull();

    preg_match('/form-action ([^;]+)/', (string) $csp, $m);
    expect($m)->not->toBeEmpty('the pay page must declare form-action');

    return preg_split('/\s+/', trim($m[1]));
}

it('permits the gateway hand-off the pay button actually performs', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));
    $token = $invoice->paymentLinkToken();

    // The policy the browser holds while the form is on screen...
    $sources = formActionSources(
        $this->get(route('pay.show', ['token' => $token]))
            ->assertOk()
            ->headers->get('Content-Security-Policy')
    );

    // ...and the origin that same form is redirected to when submitted.
    $location = $this->post(route('pay.start', ['token' => $token]))
        ->assertRedirect()
        ->headers->get('Location');

    $parts = parse_url($location);
    $origin = $parts['scheme'].'://'.$parts['host'];

    expect($origin)->not->toBe(url('/'));           // the point: it IS cross-origin
    expect($sources)->toContain($origin);
});

it('follows PAYMOB_BASE_URL rather than a written-out host', function () {
    config()->set('integrations.paymob.base_url', 'https://sandbox.paymob.example:8443');
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));

    $sources = formActionSources(
        $this->get(route('pay.show', ['token' => $invoice->paymentLinkToken()]))
            ->headers->get('Content-Security-Policy')
    );

    // Origin only — a path in a CSP source expression would narrow the policy.
    expect($sources)->toContain('https://sandbox.paymob.example:8443')
        ->not->toContain('https://accept.paymob.com');
});

it('still refuses form posts to anywhere else', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));

    $csp = $this->get(route('pay.show', ['token' => $invoice->paymentLinkToken()]))
        ->headers->get('Content-Security-Policy');

    // Widening form-action is the one relaxation; the rest of the lock-down —
    // no scripts, no framing, no base tag — must survive the change.
    expect(formActionSources($csp))->toHaveCount(2)          // 'self' + the gateway
        ->and($csp)->toContain("default-src 'none'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("base-uri 'none'")
        ->not->toContain('*');                                // never a wildcard source
});
