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
        if ($this->hasRole('super_admin')) {
            return Asset::query()->withoutGlobalScopes()->get();
        }

        return $this->assignedAssets()->withoutGlobalScopes()->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if ($this->hasRole('super_admin')) {
            return true;
        }

        return $this->assignedAssets()
            ->withoutGlobalScopes()
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
