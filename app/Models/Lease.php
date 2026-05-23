<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Lease extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['reference', 'status', 'commencement_date', 'expiry_date', 'term_months', 'base_rent_monthly', 'service_charge_monthly', 'tenant_id', 'unit_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('lease');
    }

    protected $fillable = [
        'reference',
        'unit_id',
        'tenant_id',
        'previous_lease_id',
        'status',
        'commencement_date',
        'expiry_date',
        'term_months',
        'base_rent_monthly',
        'service_charge_monthly',
        'currency',
        'security_deposit',
        'security_deposit_received',
        'escalation_rate',
        'escalation_type',
        'next_escalation_date',
        'has_percentage_rent',
        'percentage_rent_threshold',
        'percentage_rent_rate',
        'percentage_rent_calculation_type',
        'billing_day',
        'payment_terms_days',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'commencement_date' => 'date',
        'expiry_date' => 'date',
        'next_escalation_date' => 'date',
        'billing_day' => 'date',
        'base_rent_monthly' => 'decimal:2',
        'service_charge_monthly' => 'decimal:2',
        'security_deposit' => 'decimal:2',
        'escalation_rate' => 'decimal:2',
        'percentage_rent_threshold' => 'decimal:2',
        'percentage_rent_rate' => 'decimal:2',
        'security_deposit_received' => 'boolean',
        'has_percentage_rent' => 'boolean',
        'metadata' => 'array',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function previousLease(): BelongsTo
    {
        return $this->belongsTo(Lease::class, 'previous_lease_id');
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(Lease::class, 'previous_lease_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function salesDeclarations(): HasMany
    {
        return $this->hasMany(TenantSalesDeclaration::class);
    }

    public function camAllocations(): HasMany
    {
        return $this->hasMany(CamAllocation::class);
    }

    // ============ Derived ============

    public function totalMonthlyAmount(): float
    {
        return (float) ($this->base_rent_monthly + $this->service_charge_monthly);
    }

    public function annualValue(): float
    {
        return $this->totalMonthlyAmount() * 12;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isExpiringSoon(int $days = 90): bool
    {
        if (! $this->expiry_date) {
            return false;
        }
        return $this->expiry_date->isBetween(now(), now()->addDays($days));
    }

    public function daysUntilExpiry(): int
    {
        return (int) now()->diffInDays($this->expiry_date, false);
    }

    // ============ Generation helpers ============

    public static function generateReference(string $assetCode = 'HW'): string
    {
        $year = now()->format('Y');
        $count = static::whereYear('created_at', $year)->count() + 1;
        return sprintf('LSE-%s-%s-%04d', $assetCode, $year, $count);
    }
}
