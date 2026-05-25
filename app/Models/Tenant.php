<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Tenant extends Authenticatable implements FilamentUser, HasMedia
{
    use HasApiTokens, HasFactory, InteractsWithMedia, LogsActivity, Notifiable, SoftDeletes;

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
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
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

        $creditNoteBalance = (float) $this->creditNotes()
            ->whereIn('status', ['issued', 'partially_applied'])
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
