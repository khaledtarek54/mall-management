<?php

namespace App\Models;

use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A portal login that belongs to a Tenant company (req #9). One tenant may have
 * many users; only those flagged is_admin may submit/write in the portal — the
 * rest are read-only. The portal guard authenticates this model; the company
 * record is reached via ->tenant.
 */
#[DeletionAllowed(reason: 'identity: a portal login')]
// portal login for a Tenant (transitively multi-property)
#[PortfolioShared]
class TenantUser extends Authenticatable implements CanResetPasswordContract, FilamentUser, HasLocalePreference
{
    use CanResetPassword, HasApiTokens, HasFactory, LogsActivity, Notifiable, SoftDeletes;

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
        // Gate on the OWNING company's status too. Since the multi-user migration the portal
        // authenticates TenantUser (not Tenant), so Tenant::canAccessPanel()'s status check became
        // dead code — a blacklisted/inactive (or soft-deleted → tenant() resolves null) company's
        // users could still sign in. Restore the gate here.
        return $panel->getId() === 'portal' && $this->tenant?->status === 'active';
    }

    /** Only admin users may submit/write in the portal. */
    public function isPortalAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * The language this portal login reads. See User::preferredLocale() — same mechanism, and it
     * matters more here: a retailer's staff are the readers least likely to work in English, and
     * every alert they get (invoice issued, request updated, violation notice) is raised by someone
     * else's session or by a nightly sweep.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * Every column an operator can change on a portal login, recorded.
     *
     * A `TenantUser` is a LOGIN — since the 2026-09-05 unification one row opens both the tenant
     * portal and the mobile API, and `is_admin` is what decides whether that person may WRITE. None
     * of it was audited anywhere in the system: creating a login, promoting somebody to admin or
     * moving their email address left no trace at all. That is the same class of hole the property
     * roster had, and the sharper version of it, because this grants access to a company's own
     * billing rather than to a mall's operations.
     *
     * `password` is fillable here and is in `ActivityLogging::CREDENTIALS`, so the trail records
     * THAT it changed and never what it changed to.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'tenant_user');
    }
}
