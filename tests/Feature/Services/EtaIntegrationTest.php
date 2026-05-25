<?php

use App\Jobs\SubmitInvoiceToEta;
use App\Models\InvoiceItem;
use App\Services\Eta\EtaApiClient;
use App\Services\Eta\EtaSubmissionService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $tenant = makeTenant(['tax_id' => '123-456-789']);
    $this->lease = makeLease($this->unit, $tenant);
});

function withItem($lease): \App\Models\Invoice
{
    $invoice = makeInvoice($lease);
    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'type' => 'base_rent',
        'description' => 'Rent', 'amount' => 10000,
        'vat_rate' => 0, 'vat_amount' => 0, 'subtotal' => 10000,
    ]);
    return $invoice->refresh();
}

/* ───────── EtaApiClient: mock mode (default) ───────── */

it('EtaApiClient mock mode returns a deterministic accepted response', function () {
    config()->set('eta.mock', true);

    $response = app(EtaApiClient::class)->submitDocument([
        'internalID' => 'INV-001',
    ]);

    expect($response['status'])->toBe('success');
    expect($response['documentStatus'])->toBe('Valid');
    expect($response['mock'])->toBeTrue();
    expect($response['acceptedDocuments'][0]['internalId'])->toBe('INV-001');
    expect($response['acceptedDocuments'][0]['uuid'])->toBeString();
    expect($response['submissionId'])->toStartWith('MOCK-');
});

/* ───────── EtaApiClient: real mode with HTTP fake ───────── */

it('EtaApiClient real mode posts to ETA endpoint with bearer token', function () {
    config()->set('eta.mock', false);
    config()->set('eta.endpoint', 'https://eta.test');
    config()->set('eta.auth_endpoint', 'https://auth.eta.test/token');
    config()->set('eta.client_id', 'cid');
    config()->set('eta.client_secret', 'csec');

    Http::fake([
        'auth.eta.test/*' => Http::response(['access_token' => 'TKN-123']),
        'eta.test/api/v1/documentsubmissions' => Http::response([
            'submissionId' => 'SUB-9',
            'acceptedDocuments' => [['internalId' => 'INV-001', 'uuid' => 'u-1', 'longId' => 'L', 'documentStatus' => 'Valid']],
            'rejectedDocuments' => [],
        ]),
    ]);

    $response = app(EtaApiClient::class)->submitDocument([
        'internalID' => 'INV-001',
    ]);

    expect($response['submissionId'])->toBe('SUB-9');
    expect($response['acceptedDocuments'][0]['documentStatus'])->toBe('Valid');

    Http::assertSent(fn ($req) => str_contains($req->url(), 'documentsubmissions')
        && $req->header('Authorization')[0] === 'Bearer TKN-123');
});

it('EtaApiClient real mode falls back to error shape on empty response', function () {
    config()->set('eta.mock', false);
    config()->set('eta.endpoint', 'https://eta.test');
    config()->set('eta.auth_endpoint', 'https://auth.eta.test/token');

    Http::fake([
        'auth.eta.test/*' => Http::response(['access_token' => 'TKN']),
        'eta.test/*' => Http::response('', 500),
    ]);

    $response = app(EtaApiClient::class)->submitDocument(['internalID' => 'X']);

    expect($response['status'])->toBe('error');
    expect($response['httpStatus'])->toBe(500);
});

/* ───────── EtaSubmissionService ───────── */

it('EtaSubmissionService submit() is a no-op when invoice is already valid', function () {
    $invoice = withItem($this->lease);
    $invoice->update(['eta_status' => 'valid', 'eta_long_id' => 'L-orig']);

    $result = app(EtaSubmissionService::class)->submit($invoice);

    expect($result->eta_long_id)->toBe('L-orig');
});

it('EtaSubmissionService submit() persists submissionId, longId, status from accepted response', function () {
    config()->set('eta.mock', true);
    $invoice = withItem($this->lease);

    $result = app(EtaSubmissionService::class)->submit($invoice);

    expect($result->eta_status)->toBe('valid');
    expect($result->eta_submission_id)->not->toBeNull();
    expect($result->eta_long_id)->not->toBeNull();
    expect($result->eta_submitted_at)->not->toBeNull();
});

it('EtaSubmissionService submit() marks rejected when ETA returns rejectedDocuments', function () {
    $invoice = withItem($this->lease);

    $this->mock(EtaApiClient::class, function ($mock) {
        $mock->shouldReceive('submitDocument')->andReturn([
            'acceptedDocuments' => [],
            'rejectedDocuments' => [['internalId' => 'X', 'errors' => ['boom']]],
        ]);
    });

    $result = app(EtaSubmissionService::class)->submit($invoice);

    expect($result->eta_status)->toBe('rejected');
    expect($result->eta_submitted_at)->not->toBeNull();
});

it('EtaSubmissionService submit() captures throwables and marks rejected', function () {
    $invoice = withItem($this->lease);

    $this->mock(EtaApiClient::class, function ($mock) {
        $mock->shouldReceive('submitDocument')->andThrow(new RuntimeException('network down'));
    });

    $result = app(EtaSubmissionService::class)->submit($invoice);

    expect($result->eta_status)->toBe('rejected');
    expect($result->eta_response['error'])->toBe('network down');
});

it('EtaSubmissionService submit() falls back to "submitted" status when response has neither accepted nor rejected', function () {
    $invoice = withItem($this->lease);

    $this->mock(EtaApiClient::class, function ($mock) {
        $mock->shouldReceive('submitDocument')->andReturn([
            'acceptedDocuments' => [],
            'rejectedDocuments' => [],
        ]);
    });

    $result = app(EtaSubmissionService::class)->submit($invoice);

    expect($result->eta_status)->toBe('submitted');
});

/* ───────── SubmitInvoiceToEta job ───────── */

it('SubmitInvoiceToEta job handle() delegates to EtaSubmissionService', function () {
    $invoice = withItem($this->lease);
    config()->set('eta.mock', true);

    (new SubmitInvoiceToEta($invoice))->handle(app(EtaSubmissionService::class));

    expect($invoice->fresh()->eta_status)->toBe('valid');
});
