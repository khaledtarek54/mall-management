<?php

/**
 * Regression — found by the adversarial review of the mobile drift asks (PR #6). A read-only login
 * cannot learn which invoice ids exist in the portfolio.
 *
 * The two POST routes that take an invoice — `paymob-session` and `pay-demo` — bound it implicitly
 * (`Invoice $invoice`), and `SubstituteBindings` sits in Laravel's priority list AHEAD of
 * `EnsurePortalAdminForWrites`. So the lookup ran, unscoped, before the read-only gate: an invoice id
 * that EXISTS — any tenant's — answered 403 `read_only`, and one that does not answered 404. That is
 * the cross-tenant existence oracle the "404, never 403, for another tenant's document" convention
 * exists to close (CLAUDE.md, auth surfaces), reached through a different door — and only a
 * read-only login could reach it, because an admin's request went on into the controller, whose own
 * tenant check already answered 404 for both. The PR's docs then described "403 read_only" and "404
 * belongs to another tenant" side by side on both controllers without noticing the difference was
 * the leak.
 *
 * Both controllers now resolve the invoice THEMSELVES, through the tenant's own invoices, after the
 * gate — the shape every sibling `{id}` route already used. The route template keeps `{invoice}`, so
 * the published spec path and the URL the app calls are unchanged.
 *
 * Two logins, two routes, and the same pair of ids for each: the read-only login must get ONE answer
 * for an existing stranger's invoice and a missing one (403, both — nothing was looked up), and the
 * admin login must get one answer for the same pair (404, both). The controls prove the lookup still
 * reaches the controller for the tenant's own document.
 */
beforeEach(function () {
    $this->tenant = makeTenant();
    $this->own = makeInvoice(makeLease(makeUnit(makeAsset()), $this->tenant));
    $this->strangers = makeInvoice(makeLease(makeUnit(makeAsset()), makeTenant()));
    $this->missing = 999_999;

    // The demo route is live only where Paymob is off, and the session route only where it is on;
    // both refusals below happen BEFORE either gate is consulted, so the config is set per route.
    config(['integrations.paymob.enabled' => false]);
});

dataset('pay routes', [
    'paymob-session' => ['paymob-session', true],
    'pay-demo' => ['pay-demo', false],
]);

it('answers a read-only login the same 403 for a stranger\'s invoice and for none at all', function (string $route, bool $paymobOn) {
    config(['integrations.paymob.enabled' => $paymobOn]);
    $headers = apiHeadersFor(makeTenantUser($this->tenant, isAdmin: false));

    $existing = $this->postJson("/api/v1/me/invoices/{$this->strangers->id}/{$route}", [], $headers);
    $missing = $this->postJson("/api/v1/me/invoices/{$this->missing}/{$route}", [], $headers);

    expect($existing->status())->toBe(403)
        ->and($missing->status())->toBe(403, 'a missing id must not answer differently from an existing stranger\'s — that difference is the oracle')
        ->and($existing->json('error'))->toBe('read_only')
        ->and($missing->json('error'))->toBe('read_only');
})->with('pay routes');

it('answers an admin login the same 404 for a stranger\'s invoice and for none at all', function (string $route, bool $paymobOn) {
    config(['integrations.paymob.enabled' => $paymobOn]);
    $headers = apiHeadersFor(makeTenantUser($this->tenant, isAdmin: true));

    $this->postJson("/api/v1/me/invoices/{$this->strangers->id}/{$route}", [], $headers)->assertNotFound();
    $this->postJson("/api/v1/me/invoices/{$this->missing}/{$route}", [], $headers)->assertNotFound();
})->with('pay routes');

it('still reaches the controller for the tenant\'s own invoice — the control', function () {
    // Paymob OFF: the session route refuses on its gateway gate, the demo route goes on to its own
    // checks. Either way the invoice was found, or the answer would have been 404.
    $headers = apiHeadersFor(makeTenantUser($this->tenant, isAdmin: true));

    $this->postJson("/api/v1/me/invoices/{$this->own->id}/paymob-session", [], $headers)
        ->assertStatus(409)
        ->assertJsonPath('error', 'paymob_disabled');

    expect($this->postJson("/api/v1/me/invoices/{$this->own->id}/pay-demo", [], $headers)->status())
        ->not->toBe(404);
});

it('answers 404 for a missing id even with the gateway off — the lookup comes before the gateway gate, as the binding did', function () {
    // With the binding gone, ordering is a choice: the lookup is made first so a missing invoice
    // does not start answering 409 where it always answered 404.
    $headers = apiHeadersFor(makeTenantUser($this->tenant, isAdmin: true));

    $this->postJson("/api/v1/me/invoices/{$this->missing}/paymob-session", [], $headers)->assertNotFound();
});
