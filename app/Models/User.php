<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;
use Stephenjude\FilamentTwoFactorAuthentication\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable, TwoFactorAuthenticatable;

    /**
     * Track create/edit/delete on staff accounts so the ActivityLog page
     * has a paper trail for super_admin-initiated user changes. Password
     * hash + remember_token are excluded — operationally noisy and a
     * potential leak vector even though they're hashed. Audit M17 F-67 / D-52.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'email_verified_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('user');
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'owner' => $this->hasRole('owner'),
            // Any user with a role can access /admin. Jawad owners are now
            // RBAC users inside the admin app (the /owner portal is retired);
            // their permissions + owned-property scoping limit what they see.
            'admin' => $this->roles()->exists(),
            default => true,
        };
    }

    public function getTenants(Panel $panel): Collection
    {
        // Soft-deleted assets are intentionally excluded from the tenant
        // switcher — selecting one would land the user in a property that
        // no longer exists.
        $assets = $this->hasRole('super_admin')
            ? Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE)->get()
            : $this->accessibleAssets();

        // Prepend the "All Properties" pseudo-tenant whenever the user has
        // more than one property — it's the portfolio view across their
        // accessible set (or every property, for super_admin).
        if ($assets->count() > 1) {
            $all = Asset::where('code', Asset::ALL_PROPERTIES_CODE)->first();
            if ($all) {
                $assets = $assets->prepend($all);
            }
        }

        return $assets;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof Asset) {
            return false;
        }

        // A trashed asset is never accessible — guard against URL tampering
        // (a user assigned to an asset that gets soft-deleted shouldn't be
        // able to keep operating on it via the saved URL).
        if (method_exists($tenant, 'trashed') && $tenant->trashed()) {
            return false;
        }

        if ($this->hasRole('super_admin')) {
            return true;
        }

        // "All Properties" is accessible whenever the user has more than
        // one accessible property — same gate as getTenants().
        if ($tenant->isAllProperties()) {
            return $this->accessibleAssets()->count() > 1;
        }

        // Staff assignment (asset_user) OR legal ownership (asset_owner).
        return $this->assignedAssets()->whereKey($tenant->getKey())->exists()
            || $this->ownedAssets()->whereKey($tenant->getKey())->exists();
    }

    /**
     * The distinct set of properties this user can operate on in the admin
     * app: staff assignments (asset_user) ∪ legal ownership (asset_owner).
     * Jawad owners are scoped to their owned properties this way. Excludes the
     * synthetic "All Properties" pseudo-asset.
     *
     * @return Collection<int, Asset>
     */
    public function accessibleAssets(): Collection
    {
        $code = Asset::ALL_PROPERTIES_CODE;

        $assigned = $this->assignedAssets()->where('assets.code', '!=', $code)->get();
        $owned = $this->ownedAssets()->where('assets.code', '!=', $code)->get();

        return $assigned->concat($owned)->unique('id')->values();
    }

    public function assignedMaintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class, 'assigned_to');
    }

    public function ownedAssets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_owner')
            ->withPivot(['ownership_percentage', 'started_at', 'ended_at'])
            ->withTimestamps();
    }

    /**
     * Properties the user is assigned to as STAFF (distinct from ownedAssets,
     * which is the legal-ownership relationship). A user can be assigned to
     * one or many properties — used to scope what they see.
     */
    public function assignedAssets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_user')
            ->withPivot(['role', 'assigned_at', 'ended_at', 'notes'])
            ->withTimestamps();
    }

    /**
     * Operator departments this user belongs to (DEPT-4). Pivot mirrors the
     * asset_user staff pattern (free-form role label + tenure dates).
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_user')
            ->withPivot(['role', 'assigned_at', 'ended_at', 'notes'])
            ->withTimestamps();
    }
}
