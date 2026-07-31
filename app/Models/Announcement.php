<?php

namespace App\Models;

use App\Jobs\BroadcastAnnouncement;
use App\Models\Concerns\HasSearchText;
use App\Services\SendAnnouncementAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An operator broadcast to every active tenant of one property. Composed by staff
 * in the admin panel; the broadcast fan-out ({@see SendAnnouncementAction},
 * run via {@see BroadcastAnnouncement}) sends it to each tenant's in-app
 * bell + mobile devices, then stamps sent_at + recipients_count for the audit list.
 * Property-owned (direct asset_id) — isolated to the target property.
 */
class Announcement extends Model
{
    use HasSearchText, SoftDeletes;

    protected $fillable = [
        'asset_id',
        'title',
        'body',
        'created_by',
        'sent_at',
        'recipients_count',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'recipients_count' => 'integer',
    ];

    /**
     * Headline and body — an operator looks for the announcement by what it said.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->title,
            $this->body,
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
