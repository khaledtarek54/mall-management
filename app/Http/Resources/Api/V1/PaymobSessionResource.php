<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Versioned mobile contract for a Paymob payment session. Returns both the
 * payment_token (for the Paymob mobile SDK) and the iframe_url (for a
 * WebView fallback) so the Flutter client can choose either approach
 * without us needing to ship a v2.
 *
 * Wrapping is the Laravel default { "data": { ... } } envelope.
 */
class PaymobSessionResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'payment_token' => $this->resource['payment_token'],
            'iframe_url' => $this->resource['iframe_url'],
            'iframe_id' => (string) config('integrations.paymob.iframe_id'),
            'order_id' => $this->resource['order_id'],
            'payment_id' => $this->resource['payment_id'],
            'expires_at' => $this->resource['expires_at']->toIso8601String(),
            'reused' => $this->resource['reused'],
        ];
    }
}
