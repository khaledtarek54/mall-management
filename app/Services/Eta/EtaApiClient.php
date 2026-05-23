<?php

namespace App\Services\Eta;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
    public function submitDocument(array $documentJson): array
    {
        if ($this->isMock()) {
            return $this->mockResponse($documentJson);
        }

        return $this->realResponse($documentJson);
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
        $token = $this->fetchAccessToken();

        $response = Http::withToken($token)
            ->acceptJson()
            ->post(config('eta.endpoint').'/api/v1/documentsubmissions', [
                'documents' => [$documentJson],
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
