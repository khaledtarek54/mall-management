<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\DeviceToken
 */
class DeviceTokenResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'device_name' => $this->device_name,
            // The raw push token is deliberately not echoed back — it is a
            // write-only credential from the client's perspective.
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
