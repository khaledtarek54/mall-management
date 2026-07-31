<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Notifications\TenantResetPasswordNotification;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
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
    use RefusesDeletionWhenReferenced, CanResetPassword, HasApiTokens, HasFactory, InteractsWithMedia, LogsActivity, Notifiable, SoftDeletes;

    /** Identity paperwork — commercial register, tax card, trade licence. */
    public const DOCUMENTS_COLLECTION = 'documents';

    /**
     * Tenant documents live on a PRIVATE disk (not web-accessible). These are the
     * retailer's identity papers — commercial register (سجل تجاري), tax card (بطاقة
     * ضريبية) — and leaking them is a data-protection incident, not just a bug.
     *
     * **This was a live exposure until 2026-07-16** — see the note on
     * {@see Lease::registerMediaCollections()}. Declare the disk explicitly; never inherit
     * medialibrary's `public` default (MediaPrivacyConformanceTest enforces it).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::DOCUMENTS_COLLECTION)->useDisk('local');
    }

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
        'address_governorate',
        'address_city',
        'address_street',
        'address_building_number',
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
    /**
     * @param  array<int>|null  $assetIds  Restrict to these properties (pass visibleAssetIds() from
     *                                     an admin surface so a property-restricted operator's view of a shared tenant excludes malls
     *                                     they can't see). null (default) = whole company, for the tenant's own portal/API/statement.
     */
    public function outstandingBalance(?array $assetIds = null): float
    {
        $invoiceBalance = (float) $this->invoices()
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->when($assetIds !== null, fn ($q) => $q->whereHas('lease.unit', fn ($u) => $u->whereIn('asset_id', $assetIds)))
            ->sum('balance');

        // The CreditNote status enum is (draft, issued, applied, void) —
        // a 'partially_applied' state was once contemplated but never
        // shipped. CreditNoteService leaves a partly-applied note in
        // 'issued' (balance > 0); 'issued' alone is the correct filter.
        // See audit M14 F-55 / D-41.
        $creditNoteBalance = (float) $this->creditNotes()
            ->where('status', 'issued')
            ->when($assetIds !== null, fn ($q) => $q->whereHas('lease.unit', fn ($u) => $u->whereIn('asset_id', $assetIds)))
            ->sum('balance');

        return round($invoiceBalance - $creditNoteBalance, 2);
    }

    /**
     * Money the tenant has paid that isn't yet applied to a receivable — the tenant's CREDIT / ON-
     * ACCOUNT balance. It is the sum, over the tenant's RECEIVED payments (captured/reconciled/
     * settled), of each payment's UNALLOCATED remainder (amount − its allocations); that remainder
     * already sits on the books as Unearned Revenue (PaymentJournalizer). Scoped like
     * outstandingBalance(): pass visibleAssetIds() for an admin surface, null for the tenant's own
     * whole-company view. A credit is attributed to the property where the payment was received
     * (its allocated invoices' asset), so a restricted user only sees credit for their properties.
     *
     * @param  array<int>|null  $assetIds
     */
    public function creditBalance(?array $assetIds = null): float
    {
        $payments = $this->payments()->received()
            ->when(
                $assetIds !== null,
                fn ($q) => $q->whereHas('invoices.lease.unit', fn ($u) => $u->whereIn('asset_id', $assetIds)),
            )
            ->with('invoices')
            ->get();

        $credit = 0.0;
        foreach ($payments as $payment) {
            $allocated = (float) $payment->invoices->sum(fn ($i) => (float) $i->pivot->allocated_amount);
            $credit += max(0.0, round((float) $payment->amount - $allocated, 2));
        }

        // Subtract credit already APPLIED to invoices (an on-account draw-down — its own document,
        // soft-deleted rows excluded so a reversal returns the credit here). Scoped by the
        // application's asset (= the settled invoice's property).
        $applied = (float) $this->creditApplications()
            ->when($assetIds !== null, fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->sum('amount');

        return round($credit - $applied, 2);
    }

    public function creditApplications(): HasMany
    {
        return $this->hasMany(TenantCreditApplication::class);
    }

    /**
     * Delinquent = at least one invoice with a remaining balance is past
     * its due date. Doesn't trust the `status` column alone — that column
     * is only auto-flipped to 'overdue' by Payment hooks, so manually
     * cancelled / orphaned invoices can stay 'issued' indefinitely.
     */
    /**
     * @param  array<int>|null  $assetIds  See outstandingBalance() — scope to visible properties for
     *                                     an admin surface, null (default) for the tenant's own whole-company view.
     */
    public function isDelinquent(?array $assetIds = null): bool
    {
        return $this->invoices()
            ->where('balance', '>', 0)
            ->where('due_date', '<', now())
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->when($assetIds !== null, fn ($q) => $q->whereHas('lease.unit', fn ($u) => $u->whereIn('asset_id', $assetIds)))
            ->exists();
    }

    /**
     * Normalise the Egyptian VAT number to BARE DIGITS on save. The form + importer accept the
     * dashed form (123-456-789) for readability, but ETA's e-invoice expects digits only, and
     * EtaJsonBuilder sends tax_id verbatim — so a dashed value would go on the wire and be rejected.
     * Storing digits-only makes every downstream consumer (ETA, exports) correct by construction.
     */
    public function setTaxIdAttribute($value): void
    {
        $this->attributes['tax_id'] = ($value === null || $value === '')
            ? null
            : preg_replace('/\D+/', '', (string) $value);
    }
}
