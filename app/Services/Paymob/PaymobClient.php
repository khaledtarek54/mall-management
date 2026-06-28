<?php

namespace App\Services\Paymob;

use App\Models\Invoice;
use App\Support\OpsLog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around Paymob's 3-step "iframe" payment flow. Each public
 * method matches one Paymob API endpoint so test doubles can replace any
 * step independently:
 *
 *  1. authenticate()       → POST /api/auth/tokens         → bearer token
 *  2. createOrder($amount, $invoice) → POST /api/ecommerce/orders → order_id
 *  3. requestPaymentKey($order, $billing) → POST /api/acceptance/payment_keys → payment_token
 *
 * The combined helper buildPaymentSession(Invoice) does all 3 in order and
 * returns ['payment_token' => ..., 'iframe_url' => ..., 'order_id' => ...]
 * for the controller to redirect the user to.
 *
 * Throws RuntimeException with the relevant API message when a step fails.
 * Audit M11 F-42 / D-33 — closes the Pay Now stub.
 */
class PaymobClient
{
    /**
     * Paymob payment_key TTL — the iframe / SDK rejects the token after this
     * many seconds. Shared with PaymobPaymentInitiator so the reuse window
     * stays consistent with the gateway-side expiration.
     */
    public const PAYMENT_TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
        protected string $integrationId,
        protected string $iframeId,
        protected string $currency = 'EGP',
    ) {}

    public static function fromConfig(): self
    {
        $apiKey = (string) config('integrations.paymob.api_key');
        $integrationId = (string) config('integrations.paymob.integration_id');
        $iframeId = (string) config('integrations.paymob.iframe_id');

        if ($apiKey === '' || $integrationId === '' || $iframeId === '') {
            throw new RuntimeException(
                'Paymob credentials missing. Set PAYMOB_API_KEY + PAYMOB_INTEGRATION_ID + PAYMOB_IFRAME_ID in .env. See PAYMOB-SETUP.md.'
            );
        }

        return new self(
            baseUrl: (string) config('integrations.paymob.base_url', 'https://accept.paymob.com'),
            apiKey: $apiKey,
            integrationId: $integrationId,
            iframeId: $iframeId,
            currency: (string) config('integrations.paymob.currency', 'EGP'),
        );
    }

    public function authenticate(): string
    {
        $response = Http::acceptJson()->post("{$this->baseUrl}/api/auth/tokens", [
            'api_key' => $this->apiKey,
        ]);

        $this->throwIfFailed($response, 'authenticate');

        $token = $response->json('token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Paymob auth succeeded but returned no token.');
        }

        return $token;
    }

    /**
     * Amount is in EGP MAJOR units (the wrapper converts to piastres for
     * Paymob's API). Returns the numeric order_id.
     */
    public function createOrder(string $bearerToken, float $amount, Invoice $invoice): int
    {
        $amountCents = (int) round($amount * 100);

        $response = Http::acceptJson()->post("{$this->baseUrl}/api/ecommerce/orders", [
            'auth_token' => $bearerToken,
            'delivery_needed' => false,
            'amount_cents' => $amountCents,
            'currency' => $this->currency,
            'merchant_order_id' => $invoice->number . '-' . now()->format('YmdHis'),
            'items' => [[
                'name' => __('admin.notifications.invoice_issued_title') . ' ' . $invoice->number,
                'amount_cents' => $amountCents,
                'description' => 'Invoice ' . $invoice->number,
                'quantity' => 1,
            ]],
        ]);

        $this->throwIfFailed($response, 'createOrder');

        $orderId = $response->json('id');
        if (! is_int($orderId)) {
            throw new RuntimeException('Paymob created order but returned no numeric id.');
        }

        return $orderId;
    }

    /**
     * @param  array{name?:string,email?:string,phone_number?:string}  $billing
     */
    public function requestPaymentKey(string $bearerToken, int $orderId, float $amount, array $billing, ?int $integrationId = null): string
    {
        $amountCents = (int) round($amount * 100);
        $tenantName = $billing['name'] ?? '';
        $parts = explode(' ', trim($tenantName), 2);

        $response = Http::acceptJson()->post("{$this->baseUrl}/api/acceptance/payment_keys", [
            'auth_token' => $bearerToken,
            'amount_cents' => $amountCents,
            'expiration' => self::PAYMENT_TOKEN_TTL_SECONDS,
            'order_id' => $orderId,
            'billing_data' => [
                'first_name' => $parts[0] ?: 'Atriom',
                'last_name' => $parts[1] ?? 'Tenant',
                'email' => $billing['email'] ?? 'noreply@atriom.test',
                'phone_number' => $billing['phone_number'] ?? '+201000000000',
                'apartment' => 'NA', 'floor' => 'NA', 'street' => 'NA',
                'building' => 'NA', 'shipping_method' => 'NA', 'postal_code' => 'NA',
                'city' => 'Cairo', 'country' => 'EG', 'state' => 'NA',
            ],
            'currency' => $this->currency,
            // A different integration_id (e.g. Apple Pay) can be supplied; falls
            // back to the default card integration.
            'integration_id' => $integrationId ?? $this->integrationId,
        ]);

        $this->throwIfFailed($response, 'requestPaymentKey');

        $token = $response->json('token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Paymob payment key request succeeded but returned no token.');
        }

        return $token;
    }

    public function iframeUrl(string $paymentToken): string
    {
        return "{$this->baseUrl}/api/acceptance/iframes/{$this->iframeId}?payment_token={$paymentToken}";
    }

    /**
     * End-to-end: auth → order → payment key → iframe URL. Returns:
     * ['payment_token' => ..., 'iframe_url' => ..., 'order_id' => ...]
     */
    public function buildPaymentSession(Invoice $invoice, ?int $integrationId = null): array
    {
        $amount = (float) $invoice->balance;
        if ($amount <= 0) {
            throw new RuntimeException("Invoice {$invoice->number} has no balance to pay.");
        }

        $bearer = $this->authenticate();
        $orderId = $this->createOrder($bearer, $amount, $invoice);
        $paymentToken = $this->requestPaymentKey($bearer, $orderId, $amount, [
            'name' => $invoice->tenant?->name ?? '',
            'email' => $invoice->tenant?->email ?? null,
            'phone_number' => $invoice->tenant?->phone ?? null,
        ], $integrationId);

        return [
            'payment_token' => $paymentToken,
            'iframe_url' => $this->iframeUrl($paymentToken),
            'order_id' => $orderId,
        ];
    }

    /**
     * Verify Paymob's HMAC on a callback. Spec: concatenate selected fields
     * in lexicographic order, then HMAC-SHA512 with the merchant secret.
     */
    public function verifyHmac(array $callback, string $signature): bool
    {
        $obj = $callback['obj'] ?? $callback;

        // Field order from Paymob's docs:
        // amount_cents, created_at, currency, error_occured, has_parent_transaction,
        // id, integration_id, is_3d_secure, is_auth, is_capture, is_refunded,
        // is_standalone_payment, is_voided, order.id, owner, pending,
        // source_data.pan, source_data.sub_type, source_data.type, success
        $fields = [
            $obj['amount_cents'] ?? '',
            $obj['created_at'] ?? '',
            $obj['currency'] ?? '',
            $this->boolStr($obj['error_occured'] ?? false),
            $this->boolStr($obj['has_parent_transaction'] ?? false),
            $obj['id'] ?? '',
            $obj['integration_id'] ?? '',
            $this->boolStr($obj['is_3d_secure'] ?? false),
            $this->boolStr($obj['is_auth'] ?? false),
            $this->boolStr($obj['is_capture'] ?? false),
            $this->boolStr($obj['is_refunded'] ?? false),
            $this->boolStr($obj['is_standalone_payment'] ?? false),
            $this->boolStr($obj['is_voided'] ?? false),
            $obj['order']['id'] ?? '',
            $obj['owner'] ?? '',
            $this->boolStr($obj['pending'] ?? false),
            $obj['source_data']['pan'] ?? '',
            $obj['source_data']['sub_type'] ?? '',
            $obj['source_data']['type'] ?? '',
            $this->boolStr($obj['success'] ?? false),
        ];

        $hmacSecret = (string) config('integrations.paymob.hmac_secret');
        if ($hmacSecret === '') {
            return false;
        }

        $expected = hash_hmac('sha512', implode('', $fields), $hmacSecret);

        return hash_equals($expected, $signature);
    }

    protected function boolStr(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return strtolower($value) === 'true' ? 'true' : 'false';
        }

        return $value ? 'true' : 'false';
    }

    protected function throwIfFailed(Response $response, string $step): void
    {
        if ($response->successful()) {
            return;
        }

        $body = (string) $response->body();
        $trimmed = strlen($body) > 240 ? substr($body, 0, 240) . '…' : $body;

        // Make a Paymob outage visible instead of silent (the body is Paymob's
        // error response — no card data is ever returned by these endpoints).
        OpsLog::warning('paymob.request_failed', [
            'step' => $step,
            'status' => $response->status(),
            'body' => $trimmed,
        ]);

        throw new RuntimeException(
            "Paymob {$step} failed: HTTP {$response->status()} · {$trimmed}"
        );
    }
}
