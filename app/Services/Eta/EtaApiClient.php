<?php

namespace App\Services\Eta;

use App\Services\Eta\Signing\EtaDocumentSigner;
use App\Services\Eta\Signing\UnsignedEtaSigner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * HTTP client for ETA's preproduction (test) e-invoicing API.
 *
 * Two modes:
 *  - mock (default): returns a deterministic "accepted" response, useful for
 *    demos before real credentials land
 *  - real: actual POST to ETA's preprod endpoint with bearer-token auth
 *
 * Switch by setting ETA_MOCK=false in .env once credentials are wired.
 */
class EtaApiClient
{
    protected EtaDocumentSigner $signer;

    public function __construct(?EtaDocumentSigner $signer = null)
    {
        // Default to the passthrough so direct instantiation (and tests) work;
        // the container injects the bound signer when resolved via DI.
        $this->signer = $signer ?? new UnsignedEtaSigner;
    }

    public function submitDocument(array $documentJson): array
    {
        if ($this->isMock()) {
            return $this->mockResponse($documentJson);
        }

        return $this->realResponse($documentJson);
    }

    /**
     * Non-destructive credentials/connectivity check for `integrations:check`.
     * Attempts ONLY the OAuth token grant — never submits a document.
     *
     * @return array{ok:bool, mode:string, message:string}
     */
    public function verifyCredentials(): array
    {
        if ($this->isMock()) {
            return ['ok' => true, 'mode' => 'mock', 'message' => 'ETA is in MOCK mode (ETA_MOCK=true) — not contacting the real tax authority.'];
        }

        if (! config('eta.client_id') || ! config('eta.client_secret')) {
            return ['ok' => false, 'mode' => 'real', 'message' => 'ETA_CLIENT_ID / ETA_CLIENT_SECRET are not set.'];
        }

        try {
            $token = $this->fetchAccessToken();
        } catch (Throwable $e) {
            return ['ok' => false, 'mode' => 'real', 'message' => 'OAuth request failed: '.$e->getMessage()];
        }

        return $token !== ''
            ? ['ok' => true, 'mode' => 'real', 'message' => 'OAuth token acquired from '.config('eta.auth_endpoint').'.']
            : ['ok' => false, 'mode' => 'real', 'message' => 'OAuth endpoint returned no access_token — check credentials/scope.'];
    }

    protected function isMock(): bool
    {
        return (bool) config('eta.mock', true);
    }

    protected function mockResponse(array $documentJson): array
    {
        $internalId = $documentJson['internalID'] ?? 'UNKNOWN';

        return [
            'status' => 'success',
            'submissionId' => 'MOCK-'.Str::upper(Str::random(20)),
            'longId' => Str::upper(Str::random(32)),
            'documentStatus' => 'Valid',
            'acceptedDocuments' => [[
                'internalId' => $internalId,
                'uuid' => (string) Str::uuid(),
                'longId' => Str::upper(Str::random(32)),
                'documentStatus' => 'Valid',
                'dateTimeReceived' => now()->toIso8601String(),
            ]],
            'rejectedDocuments' => [],
            'mock' => true,
        ];
    }

    protected function realResponse(array $documentJson): array
    {
        // Never submit an unsigned document while pretending it's compliant: if
        // signing is switched on but only the passthrough signer is bound, stop.
        if (config('eta.signing.enabled') && ! $this->signer->isSigning()) {
            throw new RuntimeException(
                'ETA signing is enabled (ETA_SIGNING_ENABLED=true) but no real EtaDocumentSigner is bound. '
                .'Bind a CAdES signer in AppServiceProvider before submitting to ETA production.'
            );
        }

        $token = $this->fetchAccessToken();
        $document = $this->signer->sign($documentJson);

        $response = Http::withToken($token)
            ->acceptJson()
            ->post(config('eta.endpoint').'/api/v1/documentsubmissions', [
                'documents' => [$document],
            ]);

        return $response->json() ?? [
            'status' => 'error',
            'message' => 'Empty response from ETA',
            'httpStatus' => $response->status(),
        ];
    }

    protected function fetchAccessToken(): string
    {
        $response = Http::asForm()->post(config('eta.auth_endpoint'), [
            'grant_type' => 'client_credentials',
            'client_id' => config('eta.client_id'),
            'client_secret' => config('eta.client_secret'),
            'scope' => 'InvoicingAPI',
        ]);

        return (string) ($response->json('access_token') ?? '');
    }
}
