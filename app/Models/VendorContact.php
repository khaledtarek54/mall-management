<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;

/**
 * A person at a contractor — and, since 2026-08-28, optionally a **login** to the vendor portal.
 *
 * Modelled on `TenantUser`, which solved this exact problem: a company with several people, its own
 * guard, its own panel, every query scoped to the company. Reusing the shape reuses its scoping and
 * its failure modes rather than discovering them again.
 *
 * **One deliberate difference from the tenant portal.** There is no `is_admin` twin: a contractor's
 * contacts are few and all of them act, so every portal contact may act (design §4). Fewer states,
 * fewer bugs.
 *
 * **`is_portal_user` is OFF for every existing row and for every new contact.** A contact is
 * somebody's phone number until an operator decides otherwise.
 *
 * @see docs/modules/12b-VENDOR-PORTAL-DESIGN.md
 */
#[DeletionAllowed(reason: 'operational: a contact person')]
// belongs to the shared Vendor
#[PortfolioShared]
class VendorContact extends Authenticatable implements CanResetPasswordContract, FilamentUser
{
    use CanResetPassword, Notifiable;

    protected $fillable = [
        'vendor_id',
        'name',
        'role',
        'email',
        'phone',
        'is_primary',
        'notes',
        'is_portal_user',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_portal_user' => 'boolean',
        'password' => 'hashed',
        'last_login_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Contacts who can actually sign in. */
    public function scopePortalUsers(Builder $query): Builder
    {
        return $query->where('is_portal_user', true);
    }

    /**
     * **The gate on the whole portal**, and it asks three things rather than one.
     *
     * A contact may sign in only when they were GIVEN a login, and only while the company they
     * belong to is one this operator still deals with. Gating on `is_portal_user` alone would leave
     * a terminated contractor's staff signing in and reading the jobs they were last dispatched to
     * — which is the tenant portal's own lesson, where gating on the login and not the COMPANY left
     * a blacklisted tenant's users with a working account.
     *
     * `isDispatchable()` is deliberately NOT the test. That asks "may we send them onto the floor
     * today", which goes false the day an insurance certificate lapses — and a contractor whose COI
     * expired mid-job must still be able to read the thread and hand over evidence. Suspending
     * someone's account is a different decision from suspending their dispatches, and conflating
     * them would make a lapsed certificate silently delete their access to work already done.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'vendor'
            && $this->is_portal_user
            && $this->vendor?->status === Vendor::STATUS_ACTIVE;
    }

    protected static function booted(): void
    {
        static::saving(function (self $contact) {
            // **Unique among rows that can SIGN IN**, which is not a unique index: two non-login
            // contacts sharing a switchboard address is ordinary data, and MySQL cannot express a
            // partial unique index portably (a stored generated column behaves differently on
            // SQLite, which the suite runs on — so it would be green here and untested there).
            //
            // On the model rather than in a service because this is the one choke point every path
            // shares: the vendor form, the importer, the console and a future API. Same reasoning
            // `GuardsPostingDate` gives for the same placement.
            if (! $contact->is_portal_user || blank($contact->email)) {
                return;
            }

            $clash = static::query()
                ->where('email', $contact->email)
                ->where('is_portal_user', true)
                ->whereKeyNot($contact->getKey() ?? 0)
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages([
                    'email' => [__('admin.vendors.portal.email_taken')],
                ]);
            }
        });
    }
}
