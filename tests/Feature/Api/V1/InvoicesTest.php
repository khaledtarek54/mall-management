<?php

it('lists only the authenticated tenant\'s invoices, paginated', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant);
    makeInvoice($lease);
    makeInvoice($lease);

    // Another tenant's invoice must not leak.
    $other = makeTenant();
    makeInvoice(makeLease(makeUnit(makeAsset()), $other));

    $response = $this->getJson('/api/v1/me/invoices', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['currentPage', 'lastPage', 'total'], 'links']);

    expect($response->json('meta.total'))->toBe(2);
});

it('filters invoices by status', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant);
    makeInvoice($lease, ['status' => 'paid']);
    makeInvoice($lease, ['status' => 'issued']);

    $response = $this->getJson('/api/v1/me/invoices?status=paid', apiHeaders($tenant))->assertOk();

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.status'))->toBe('paid');
});

it('shows a single invoice with line items', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant);
    $invoice = makeInvoice($lease);
    $invoice->items()->create([
        'description' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 10000, 'vat_rate' => 14, 'vat_amount' => 1400, 'total' => 11400,
    ]);

    $this->getJson("/api/v1/me/invoices/{$invoice->id}", apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.id', $invoice->id)
        ->assertJsonPath('data.items.0.description', 'Base Rent');
});

it('returns 404 for another tenant\'s invoice', function () {
    $tenant = makeTenant();
    $other = makeTenant();
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()), $other));

    $this->getJson("/api/v1/me/invoices/{$invoice->id}", apiHeaders($tenant))
        ->assertNotFound();
});

it('streams an invoice PDF', function () {
    $tenant = makeTenant();
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()), $tenant));

    $response = $this->get("/api/v1/me/invoices/{$invoice->id}/pdf", apiHeaders($tenant));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('streams the statement of account PDF', function () {
    $tenant = makeTenant();
    makeInvoice(makeLease(makeUnit(makeAsset()), $tenant));

    $response = $this->get('/api/v1/me/statement', apiHeaders($tenant));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('requires authentication for invoices', function () {
    $this->getJson('/api/v1/me/invoices')->assertUnauthorized();
});
