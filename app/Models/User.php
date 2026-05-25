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
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

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
            // Anyone with a non-owner role can access /admin. This naturally
            // covers built-in roles AND any custom roles created via the UI.
            'admin' => $this->roles()->where('name', '!=', 'owner')->exists(),
            default => true,
        };
    }

    public function getTenants(Panel $panel): Collection
    {
        $assets = $this->hasRole('super_admin')
            ? Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE)->get()
            : $this->assignedAssets()->where('assets.code', '!=', Asset::ALL_PROPERTIES_CODE)->get();

        // Prepend the "All Properties" pseudo-tenant whenever the user has
        // more than one property — it's the portfolio view across their
        // assigned set (or every property, for super_admin).
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
        if ($this->hasRole('super_admin')) {
            return true;
        }

        // "All Properties" is accessible whenever the user has more than
        // one assigned property — same gate as getTenants().
        if ($tenant instanceof Asset && $tenant->isAllProperties()) {
            return $this->assignedAssets()->count() > 1;
        }

        return $this->assignedAssets()
            ->whereKey($tenant->getKey())
            ->exists();
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
}
