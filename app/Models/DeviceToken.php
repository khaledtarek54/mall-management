<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A push-notification registration (FCM / APNS) for one tenant device.
 * Registered by the mobile app on login and refreshed when the OS rotates
 * the token. The push fan-out reads these targets: the `push` notification
 * channel ({@see \App\Notifications\Channels\PushChannel}) sends every
 * tenant-facing notification to the tenant's tokens via the bound
 * {@see \App\Services\Push\PushSender} (NullPushSender until FCM creds land).
 */
#[DeletionAllowed(reason: 'parent-managed: pruned automatically when a push token goes dead')]
// push token for a Tenant
#[PortfolioShared]
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
