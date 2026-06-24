<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An owner (Jawad) request (FR OWN-1/2) — raised to the operator team or to
 * another owner user. Closed/cancelled requests are immutable (REQ-3).
 */
class OwnerRequest extends Model
{
    use LogsActivity, SoftDeletes;

    public const STATUSES = ['open', 'in_progress', 'resolved', 'closed', 'cancelled'];

    public const OPEN_STATUSES = ['open', 'in_progress'];

    public const RECIPIENTS = ['operator', 'owner'];

    public const PRIORITIES = ['low', 'medium', 'high'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['recipient', 'assigned_to_user_id', 'status', 'priority', 'subject'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('owner_request');
    }

    protected $fillable = [
        'reference',
        'created_by_user_id',
        'asset_id',
        'recipient',
        'assigned_to_user_id',
        'subject',
        'body',
        'priority',
        'status',
        'scheduled_from',
        'scheduled_to',
        'resolution_notes',
        'resolved_at',
        'closed_at',
    ];

    protected $casts = [
        'scheduled_from' => 'datetime',
        'scheduled_to' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['closed', 'cancelled'], true);
    }

    public static function generateReference(): string
    {
        $year = now()->format('Y');
        $count = static::whereYear('created_at', $year)->withTrashed()->count() + 1;

        return sprintf('OR-%s-%04d', $year, $count);
    }
}
