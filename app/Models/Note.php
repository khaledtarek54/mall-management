<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[DeletionAllowed(reason: 'configuration: a free-text note')]
// polymorphic note attached to various records
#[PortfolioShared]
class Note extends Model
{
    use HasFactory, LogsActivity;

    public const CHANNELS = ['call', 'whatsapp', 'email', 'meeting', 'site_visit', 'other'];

    protected $fillable = [
        'noteable_type',
        'noteable_id',
        'author_id',
        'channel',
        'subject',
        'body',
        'contacted_at',
    ];

    protected $casts = [
        'contacted_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['channel', 'subject', 'body', 'contacted_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('note');
    }

    public function noteable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
