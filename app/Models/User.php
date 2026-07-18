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
        // Property-first UX: the switcher offers ONLY real malls — never the
        // synthetic "All Properties" pseudo-asset. The operator always works
        // inside one specific property; portfolio/consolidation is a Phase-B
        // read-only surface, not a selectable operational tenant. This is also
        // what makes the All-mode "create clobber" bug class structurally
        // impossible (see docs/plans/03-remove-all-properties-mode.md).
        //
        // Soft-deleted assets are intentionally excluded — selecting one would
        // land the user in a property that no longer exists.
        return $this->hasRole('super_admin')
            ? Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE)->get()
            : $this->accessibleAssets();
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

        // The synthetic "All Properties" pseudo-asset is never a selectable
        // operational tenant (property-first UX). Reject it before the
        // super_admin allowance so a crafted /admin/ALL/... URL 404s (Filament's
        // IdentifyTenant aborts 404 — no existence enumeration) rather than
        // dropping anyone into the removed portfolio-create context.
        if ($tenant->isAllProperties()) {
            return false;
        }

        if ($this->hasRole('super_admin')) {
            return true;
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

    public function assignedTenantRequests(): HasMany
    {
        return $this->hasMany(TenantRequest::class, 'assigned_to');
    }

    public function ownedAssets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_owner')
            ->using(AssetOwner::class)
            ->withPivot(['id', 'ownership_percentage', 'started_at', 'ended_at'])
            ->withTimestamps();
    }

    /**
     * Owned properties whose ownership tenure is in effect on $onDate (default today).
     * A null started_at/ended_at is unbounded on that side. This is the set the owner
     * statements + the portfolio widget weight by `ownership_percentage` — so a 50%
     * co-owner sees their share, not the whole property.
     */
    public function currentOwnedAssets(?string $onDate = null): BelongsToMany
    {
        $on = $onDate ?? now()->toDateString();

        return $this->ownedAssets()
            ->where(fn ($q) => $q->whereNull('asset_owner.started_at')->orWhere('asset_owner.started_at', '<=', $on))
            ->where(fn ($q) => $q->whereNull('asset_owner.ended_at')->orWhere('asset_owner.ended_at', '>=', $on));
    }

    /**
     * Current ownership share per owned property: [asset_id => percentage (0–100)].
     * Keyed by asset, so a consumer can weight any per-property figure by the owner's stake.
     *
     * @return \Illuminate\Support\Collection<int, float>
     */
    public function currentOwnershipShares(?string $onDate = null): \Illuminate\Support\Collection
    {
        return $this->currentOwnedAssets($onDate)->get()
            ->mapWithKeys(fn (Asset $a) => [$a->id => (float) $a->pivot->ownership_percentage]);
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
