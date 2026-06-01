<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A push-notification registration (FCM / APNS) for one tenant device.
 * Registered by the mobile app on login and refreshed when the OS rotates
 * the token. The actual push fan-out (a listener on Invoice/Payment/etc.)
 * is a separate, post-pilot concern — this table just holds the targets.
 */
class DeviceToken extends Model
{
    protected $fillable = [
        'tenant_id',
        'platform',
        'token',
        'device_name',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
