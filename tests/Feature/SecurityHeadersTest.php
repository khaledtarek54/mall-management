<?php

/**
 * Baseline security headers on every response, with a strict CSP scoped to the
 * public payment pages only (Filament panels need a looser policy).
 */

it('sets baseline security headers on a normal web response (no strict CSP)', function () {
    $response = $this->get('/');

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect($response->headers->get('Content-Security-Policy'))->toBeNull();
});

it('applies a strict Content-Security-Policy to the public pay pages', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));

    $response = $this->get(route('pay.show', ['token' => $invoice->paymentLinkToken()]));

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'none'")
        ->toContain("frame-ancestors 'none'");
});
