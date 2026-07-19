<?php

use App\Services\Eta\EtaJsonBuilder;

beforeEach(function () {
    config(['eta.issuer' => [
        'name' => 'Test Issuer',
        'type' => 'B',
        'tax_registration_number' => '999-888-777',
        'address' => [
            'country' => 'EG', 'governate' => 'Cairo',
            'regionCity' => 'Nasr City', 'street' => '1', 'buildingNumber' => '1',
        ],
    ]]);

    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
});

it('throws when a business tenant lacks a tax_id', function () {
    $tenant = makeTenant(['type' => 'company', 'tax_id' => null]);
    $lease = makeLease($this->unit, $tenant);
    $invoice = makeInvoice($lease);

    app(EtaJsonBuilder::class)->build($invoice);
})->throws(RuntimeException::class, 'tax_id');

it('builds a document when a business tenant has a tax_id', function () {
    // The dashed form is accepted at input but Tenant::setTaxIdAttribute normalises it to bare
    // digits on save, so ETA receives digits only (ETA rejects dashed VAT numbers).
    $tenant = makeTenant(['type' => 'company', 'tax_id' => '123-456-789']);
    $lease = makeLease($this->unit, $tenant);
    $invoice = makeInvoice($lease);

    $doc = app(EtaJsonBuilder::class)->build($invoice);

    expect($doc['receiver']['type'])->toBe('B');
    expect($doc['receiver']['id'])->toBe('123456789');
});

it('allows individual tenants without a tax_id (mapped to person type)', function () {
    $tenant = makeTenant(['type' => 'individual', 'tax_id' => null]);
    $lease = makeLease($this->unit, $tenant);
    $invoice = makeInvoice($lease);

    $doc = app(EtaJsonBuilder::class)->build($invoice);

    expect($doc['receiver']['type'])->toBe('P');
});
