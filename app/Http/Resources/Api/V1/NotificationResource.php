<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @mixin DatabaseNotification
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
            // Notification payload, minus the Filament bell render hints — those
            // are server-side presentation cruft the mobile app shouldn't see (and
            // stripping them keeps this from silently shipping internal keys).
            //
            // `actions` is stripped for a stronger reason than tidiness: it holds an absolute
            // /admin or /portal URL built for a WEB panel the app has no session in. Shipping it
            // would invite the client to open a link that lands on a login screen. The app already
            // deep-links from the id fields below, which is the contract it was written against.
            'data' => collect($this->data)->except(['format', 'duration', 'icon', 'color', 'actions'])->all(),
            'read' => $this->read_at !== null,
            'read_at' => optional($this->read_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
