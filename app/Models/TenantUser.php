<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A portal login that belongs to a Tenant company (req #9). One tenant may have
 * many users; only those flagged is_admin may submit/write in the portal — the
 * rest are read-only. The portal guard authenticates this model; the company
 * record is reached via ->tenant.
 */
class TenantUser extends Authenticatable implements CanResetPasswordContract, FilamentUser
{
    use CanResetPassword, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'is_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'portal';
    }

    /** Only admin users may submit/write in the portal. */
    public function isPortalAdmin(): bool
    {
        return (bool) $this->is_admin;
    }
}
