<?php

namespace App\Services\Push;

use App\Support\OpsLog;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Sends via Firebase Cloud Messaging HTTP v1. No SDK dependency — it mints a
 * Google OAuth access token from the service-account JSON (signed JWT bearer
 * flow, cached ~55 min) and POSTs one message per token to the v1 endpoint.
 *
 * FCM is free + unlimited. Bound only when integrations.push.enabled is true and
 * a credentials path is set (see AppServiceProvider); otherwise NullPushSender.
 */
class FcmPushSender implements PushSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function __construct(
        private string $credentialsPath,
        private ?string $projectId = null,
    ) {}

    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = array_values(array_filter(array_unique($tokens)));

        if ($tokens === []) {
            return [];
        }

        try {
            $creds = $this->credentials();
            $projectId = $this->projectId ?: ($creds['project_id'] ?? null);
            $accessToken = $this->accessToken($creds);

            if (! $projectId || ! $accessToken) {
                OpsLog::warning('push.fcm_misconfigured', ['has_project' => (bool) $projectId]);

                return [];
            }
        } catch (\Throwable $e) {
            // Bad/missing creds must not break the triggering event.
            OpsLog::warning('push.fcm_auth_failed', ['error' => $e->getMessage()]);

            return [];
        }

        // FCM data values must all be strings.
        $stringData = collect($data)
            ->map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v))
            ->all();

        $endpoint = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        // FCM HTTP v1 has no multicast, so it's one request per token — but fire
        // them concurrently (a tenant may have several devices) and key each
        // response by its token so we can map failures back for pruning.
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $token) => $pool->as($token)
                ->withToken($accessToken)
                ->asJson()
                ->post($endpoint, [
                    'message' => [
                        'token' => $token,
                        'notification' => ['title' => $title, 'body' => $body],
                        'data' => $stringData,
                    ],
                ]),
            $tokens,
        ));

        $dead = [];

        foreach ($tokens as $token) {
            $response = $responses[$token] ?? null;

            // A transport-level failure (timeout, DNS) surfaces as a Throwable
            // rather than a Response — transient, so keep the token.
            if (! $response instanceof Response) {
                OpsLog::warning('push.fcm_exception', [
                    'error' => $response instanceof \Throwable ? $response->getMessage() : 'no_response',
                ]);

                continue;
            }

            if ($response->successful()) {
                continue;
            }

            if ($this->tokenIsDead($response)) {
                $dead[] = $token;

                continue;
            }

            OpsLog::warning('push.fcm_send_failed', [
                'status' => $response->status(),
                'error' => $response->json('error.status') ?? 'unknown',
            ]);
        }

        return $dead;
    }

    /**
     * True only for FCM's unambiguous "this token is permanently gone" signal, so
     * a transient 5xx or a payload bug can never wipe a live token. FCM returns
     * HTTP 404 with error.status NOT_FOUND / UNREGISTERED (errorCode UNREGISTERED
     * in the details) once a token is uninstalled or has expired.
     */
    protected function tokenIsDead(Response $response): bool
    {
        if ($response->status() !== 404) {
            return false;
        }

        return in_array($response->json('error.status'), ['UNREGISTERED', 'NOT_FOUND'], true)
            || $response->json('error.details.0.errorCode') === 'UNREGISTERED';
    }

    /**
     * @return array<string, mixed>
     */
    protected function credentials(): array
    {
        $json = json_decode((string) file_get_contents($this->credentialsPath), true);

        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            throw new \RuntimeException('Invalid FCM service-account credentials.');
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $creds
     */
    protected function accessToken(array $creds): ?string
    {
        $tokenUri = $creds['token_uri'] ?? 'https://oauth2.googleapis.com/token';

        return Cache::remember('fcm.access_token', 3300, function () use ($creds, $tokenUri) {
            $now = time();
            $jwt = $this->signJwt([
                'iss' => $creds['client_email'],
                'scope' => self::SCOPE,
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ], $creds['private_key']);

            return Http::asForm()->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ])->json('access_token');
        });
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function signJwt(array $claims, string $privateKey): string
    {
        $b64 = fn (string $d): string => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');

        $segments = [
            $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $b64(json_encode($claims)),
        ];
        $signingInput = implode('.', $segments);

        openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return $signingInput.'.'.$b64($signature);
    }
}
