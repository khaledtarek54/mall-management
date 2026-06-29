<?php

namespace App\Models;

use App\Notifications\TenantResetPasswordNotification;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Tenant extends Authenticatable implements CanResetPasswordContract, FilamentUser, HasMedia
{
    use CanResetPassword, HasApiTokens, HasFactory, InteractsWithMedia, LogsActivity, Notifiable, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'legal_name', 'type', 'status', 'email', 'phone'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('tenant');
    }

    protected $fillable = [
        'name',
        'legal_name',
        'type',
        'email',
        'password',
        'phone',
        'whatsapp',
        'tax_id',
        'national_id',
        'commercial_register',
        'address',
        'contact_person',
        'contact_person_phone',
        'status',
        'metadata',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'national_id',
        'tax_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'portal' && $this->status === 'active';
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    /** Portal login accounts for this tenant (req #9 multi-user). */
    public function users(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    /**
     * Notify the tenant on every surface: the Tenant record (the mobile API
     * still authenticates it) AND each portal user (the web bell reads
     * TenantUser notifications). Tenants with no portal users still get the
     * Tenant copy, so nothing regresses.
     */
    public function notifyPortal($notification): void
    {
        $this->notify($notification);

        foreach ($this->users as $user) {
            $user->notify($notification);
        }
    }

    public function activeLeases(): HasMany
    {
        return $this->leases()->where('status', 'active');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'noteable')->latest('contacted_at');
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(TenantRequest::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /**
     * Sales declarations belong to a Lease, not directly to the Tenant, so we
     * reach them through the leases table. Mirrors the portal resource's
     * whereHas('lease', ...) scoping, expressed as a relationship.
     */
    public function salesDeclarations(): HasManyThrough
    {
        return $this->hasManyThrough(TenantSalesDeclaration::class, Lease::class);
    }

    /**
     * Send the mobile-app password reset link. Overrides the default so the
     * link targets the app deep-link rather than a web route.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new TenantResetPasswordNotification($token));
    }

    /**
     * Net outstanding AR for this tenant: open invoice balances minus the
     * tenant's unapplied credit-note balances. A tenant carrying a 1000 EGP
     * invoice and a 300 EGP issued credit note owes 700, not 1000.
     */
    public function outstandingBalance(): float
    {
        $invoiceBalance = (float) $this->invoices()
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->sum('balance');

        // The CreditNote status enum is (draft, issued, applied, void) —
        // a 'partially_applied' state was once contemplated but never
        // shipped. CreditNoteService leaves a partly-applied note in
        // 'issued' (balance > 0); 'issued' alone is the correct filter.
        // See audit M14 F-55 / D-41.
        $creditNoteBalance = (float) $this->creditNotes()
            ->where('status', 'issued')
            ->sum('balance');

        return round($invoiceBalance - $creditNoteBalance, 2);
    }

    /**
     * Delinquent = at least one invoice with a remaining balance is past
     * its due date. Doesn't trust the `status` column alone — that column
     * is only auto-flipped to 'overdue' by Payment hooks, so manually
     * cancelled / orphaned invoices can stay 'issued' indefinitely.
     */
    public function isDelinquent(): bool
    {
        return $this->invoices()
            ->where('balance', '>', 0)
            ->where('due_date', '<', now())
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->exists();
    }
}
