<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An owner (Jawad) request (FR OWN-1/2) — raised to the operator team or to
 * another owner user. Closed/cancelled requests are immutable (REQ-3).
 */
#[DeletionAllowed(reason: 'operational: responded requests are already immutable')]
// asset_id nullable (property-specific or cross-property)
#[PropertyOwned]
class OwnerRequest extends Model
{
    use HasFactory, HasSearchText, LogsActivity, SoftDeletes;

    public const STATUSES = ['open', 'in_progress', 'resolved', 'closed', 'cancelled'];

    public const OPEN_STATUSES = ['open', 'in_progress'];

    public const RECIPIENTS = ['operator', 'owner'];

    public const PRIORITIES = ['low', 'medium', 'high'];

    /**
     * Reference and subject.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->reference,
            $this->subject,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'owner_request');
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * The conversation thread — replies oldest-first, so it reads top-to-bottom like a chat.
     *
     * @return HasMany<OwnerRequestReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(OwnerRequestReply::class)->oldest();
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

        // Bump until free — the bare count is race-prone (mirrors the money helpers).
        $candidate = sprintf('OR-%s-%04d', $year, $count);
        $attempts = 0;
        while (static::withTrashed()->where('reference', $candidate)->exists() && $attempts < 50) {
            $candidate = sprintf('OR-%s-%04d', $year, ++$count);
            $attempts++;
        }

        return $candidate;
    }
}
