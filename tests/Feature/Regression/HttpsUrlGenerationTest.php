<?php

/*
|--------------------------------------------------------------------------
| Absolute URLs must be https in production
|--------------------------------------------------------------------------
| Security headers and a strict CSP were done; `forceScheme`/`forceHttps` existed NOWHERE, and
| there was no `trustProxies()` call either. TLS terminates at the proxy, so PHP sees a plain http
| request and Laravel built every absolute URL with http://.
|
| These are not cosmetic URLs — they are the ones that leave the building:
|   · the tenant payment link (Invoice::paymentLinkUrl)
|   · password-reset links for /admin, /portal and the mobile API
|   · the Paymob return URL
|   · the "Open Atriom" button in every emailed alert
|
| An http:// payment link is an invoice total travelling in clear text. HSTS was already being
| sent, but HSTS only protects a browser that has ALREADY completed one https visit — it does
| nothing for the first click on a link in an email, which is exactly how a payment link is opened.
*/

use Illuminate\Http\Request;

it('defaults to forcing https everywhere except local and testing', function () {
    // The point of the default: a production deploy is secure without anyone remembering an env
    // var, and the local Herd/http loop and the test suite still work.
    $forceHttpsFor = function (string $env): bool {
        return (bool) (! in_array($env, ['local', 'testing'], true));
    };

    expect($forceHttpsFor('production'))->toBeTrue()
        ->and($forceHttpsFor('staging'))->toBeTrue()
        ->and($forceHttpsFor('local'))->toBeFalse()
        ->and($forceHttpsFor('testing'))->toBeFalse();

    // …and that is exactly the expression config/security.php uses, so this cannot drift into
    // testing one rule while the app applies another.
    expect(file_get_contents(config_path('security.php')))
        ->toContain("! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)");
});

it('builds https absolute URLs once the scheme is forced', function () {
    // The suite runs with APP_ENV=testing (so the default is OFF and Herd's http loop works);
    // turn it on the way the provider does and assert the thing that actually matters.
    URL::forceScheme('https');

    expect(url('/pay/abc'))->toStartWith('https://')
        ->and(route('pay.show', ['token' => 'abc']))->toStartWith('https://');

    URL::forceScheme('http');
});

it('trusts the proxy for the forwarded scheme, so a TLS-terminated request is seen as secure', function () {
    // Without trustProxies(), Laravel ignores X-Forwarded-Proto and reports isSecure() === false
    // behind every load balancer — which is also why the client IP was the proxy's, giving login
    // throttling one shared bucket for the whole internet.
    $request = Request::create('http://mall-management.test/admin', 'GET', server: [
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        'REMOTE_ADDR' => '10.0.0.1',
    ]);

    Request::setTrustedProxies(
        ['10.0.0.1'],
        Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
    );

    expect($request->isSecure())->toBeTrue()
        // The caller, not the proxy — this is what throttling and the audit trail key on.
        ->and($request->ip())->toBe('203.0.113.9');

    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

it('registers the proxy trust at all', function () {
    // The regression this guards is an ABSENCE — there was no trustProxies() call anywhere, which
    // no behavioural test would notice because the default (trust nothing) is silently wrong
    // rather than broken.
    expect(file_get_contents(base_path('bootstrap/app.php')))
        ->toContain('trustProxies(')
        ->toContain('HEADER_X_FORWARDED_PROTO');
});
