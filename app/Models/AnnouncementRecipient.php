<?php

namespace App\Models;

use App\Services\Announcements\MarkAnnouncementReadAction;
use App\Services\SendAnnouncementAction;
use App\Support\Attributes\DeletionAllowed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tenant's copy of one announcement: that it was sent to them, and whether they have read it.
 *
 * The row is written by {@see SendAnnouncementAction} at broadcast time and stamped
 * by {@see MarkAnnouncementReadAction} when the tenant opens the post
 * on mobile or in the portal. It is the recipient list AND the read receipt, because those are the
 * same fact at two moments — see the migration docblock.
 *
 * **This is what makes the tenant feed correct.** `Announcement::liveFor()` asks "does a row exist
 * for this tenant", never "is this tenant currently in that property". The second question gives a
 * different answer every time a lease starts or ends, which would hand a retailer notices from
 * before they arrived and take away the ones they were actually sent.
 *
 * Property isolation is INHERITED, not stored: the row reaches its Asset through
 * `announcement.asset_id`. Nothing here carries an asset_id of its own, so there is nothing to
 * guard on write — the parent decides, once.
 */
#[DeletionAllowed(reason: 'operational: a delivery + read receipt, cascaded from its announcement')]
class AnnouncementRecipient extends Model
{
    protected $fillable = [
        'announcement_id',
        'tenant_id',
        'notified_at',
        'read_at',
        'read_by_tenant_user_id',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    /** @return BelongsTo<Announcement, $this> */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Which portal login opened it. Null when the reader was the mobile app (it authenticates
     * the Tenant company, and there is no user to record).
     *
     * @return BelongsTo<TenantUser, $this>
     */
    public function readBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'read_by_tenant_user_id');
    }

    /** @param  Builder<AnnouncementRecipient>  $query */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    /** @param  Builder<AnnouncementRecipient>  $query */
    public function scopeRead(Builder $query): void
    {
        $query->whereNotNull('read_at');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
