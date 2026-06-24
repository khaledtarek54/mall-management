<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An operator (Eltizam) organizational unit — the backbone of the
 * department-oriented ERP. Seeded with the five core departments
 * (HR, Marketing, Accounting, Leasing, Operations); admins can add more.
 *
 * A null asset_id means the department is operator-wide (global); a set
 * asset_id scopes it to one property. See docs/FUNCTIONAL-REQUIREMENTS.md §5.
 */
class Department extends Model
{
    use LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'asset_id', 'head_user_id', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('department');
    }

    protected $fillable = [
        'name',
        'slug',
        'code',
        'description',
        'asset_id',
        'head_user_id',
        'is_active',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function isGlobal(): bool
    {
        return $this->asset_id === null;
    }

    protected static function booted(): void
    {
        static::creating(function (self $department) {
            if (empty($department->slug)) {
                $base = Str::slug($department->name ?? 'department');
                $slug = $base;
                $suffix = 1;
                while (static::withTrashed()->where('slug', $slug)->exists()) {
                    $slug = $base . '-' . (++$suffix);
                }
                $department->slug = $slug;
            }
        });
    }
}
