<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /**
     * Operator staff assigned to this department (DEPT-4). Pivot mirrors the
     * asset_user staff pattern (free-form role label + tenure dates).
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_user')
            ->withPivot(['role', 'assigned_at', 'ended_at', 'notes'])
            ->withTimestamps();
    }

    /**
     * The spatie role that grants access to this department's resources — access
     * is RBAC, not the Department model (FR DEPT, hybrid design). Existing
     * functional roles are reused where they already match a department.
     */
    public function roleName(): string
    {
        // A department's slug IS its access-role name
        // (leasing / operations / accounting / marketing / hr).
        return $this->slug;
    }

    /** Assign this department's role to every current member (idempotent). */
    public function assignRolesToMembers(): void
    {
        $role = $this->roleName();
        $this->members()->get()->each(fn (User $u) => $u->assignRole($role));
    }

    /** Register a user into this department: membership + the department role. */
    public function registerMember(User $user, array $pivot = []): void
    {
        $this->members()->syncWithoutDetaching([$user->id => $pivot]);
        $user->assignRole($this->roleName());
    }

    /** Remove a user from this department: membership + the department role. */
    public function unregisterMember(User $user): void
    {
        $this->members()->detach($user->id);
        $user->removeRole($this->roleName());
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
