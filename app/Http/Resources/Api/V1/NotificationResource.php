<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Illuminate\Notifications\DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, // UUID
            // Short class name (e.g. "PaymentReceivedNotification") so the app can
            // branch on it without coupling to the PHP namespace.
            'type' => class_basename($this->type),
            'data' => $this->data, // notification payload (toDatabase output)
            'read' => $this->read_at !== null,
            'read_at' => optional($this->read_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
