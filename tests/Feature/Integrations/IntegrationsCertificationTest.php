<?php

use App\Services\Eta\EtaApiClient;
use App\Services\Eta\Signing\EtaDocumentSigner;
use App\Services\Eta\Signing\UnsignedEtaSigner;
use Illuminate\Support\Facades\Http;

/** ETA credential preflight + the pluggable signing seam + the integrations:check command. */

it('reports mock mode without contacting ETA', function () {
    config()->set('eta.mock', true);

    $r = app(EtaApiClient::class)->verifyCredentials();

    expect($r['ok'])->toBeTrue();
    expect($r['mode'])->toBe('mock');
});

it('reports missing credentials in real mode', function () {
    config()->set('eta.mock', false);
    config()->set('eta.client_id', null);
    config()->set('eta.client_secret', null);

    $r = app(EtaApiClient::class)->verifyCredentials();

    expect($r['ok'])->toBeFalse();
    expect($r['message'])->toContain('not set');
});

it('acquires an OAuth token in real mode when credentials are valid', function () {
    config()->set('eta.mock', false);
    config()->set('eta.client_id', 'cid');
    config()->set('eta.client_secret', 'csec');
    config()->set('eta.auth_endpoint', 'https://auth.eta.test/token');
    Http::fake(['auth.eta.test/*' => Http::response(['access_token' => 'TKN-123'])]);

    $r = app(EtaApiClient::class)->verifyCredentials();

    expect($r['ok'])->toBeTrue();
    expect($r['mode'])->toBe('real');
});

it('refuses to submit unsigned when signing is enabled but only the passthrough signer is bound', function () {
    config()->set('eta.mock', false);
    config()->set('eta.signing.enabled', true);

    expect(fn () => app(EtaApiClient::class)->submitDocument(['internalID' => 'X']))
        ->toThrow(RuntimeException::class, 'ETA signing is enabled');
});

it('binds a passthrough ETA signer by default', function () {
    $signer = app(EtaDocumentSigner::class);

    expect($signer)->toBeInstanceOf(UnsignedEtaSigner::class);
    expect($signer->isSigning())->toBeFalse();
    expect($signer->sign(['a' => 1]))->toBe(['a' => 1]);
});

it('drives EGS item codes from config', function () {
    config()->set('eta.egs_codes.base_rent', 'EG-TEST-001');

    expect(config('eta.egs_codes.base_rent'))->toBe('EG-TEST-001');
});

it('integrations:check passes in mock/disabled defaults', function () {
    config()->set('eta.mock', true);
    config()->set('integrations.paymob.enabled', false);

    $this->artisan('integrations:check')->assertExitCode(0);
});

it('integrations:check fails when paymob is enabled but unreachable', function () {
    config()->set('eta.mock', true);
    config()->set('integrations.paymob.enabled', true);
    config()->set('integrations.paymob.api_key', 'k');
    config()->set('integrations.paymob.integration_id', 'i');
    config()->set('integrations.paymob.iframe_id', 'f');
    Http::fake(['*/api/auth/tokens' => Http::response([], 500)]);

    $this->artisan('integrations:check --paymob')->assertExitCode(1);
});
