<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'expiry_reminder_notified_at',
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

    // Non-nullable boolean columns: default the in-memory model so a
    // service-created lease (which may omit them) never propagates null into
    // the NOT NULL columns (e.g. on renewal before a DB re-read).
    protected $attributes = [
        'has_percentage_rent' => false,
        'security_deposit_received' => false,
    ];

    protected $casts = [
        'commencement_date' => 'date',
        'expiry_date' => 'date',
        'expiry_reminder_notified_at' => 'datetime',
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

    /**
     * All units this lease covers (master + additional) via lease_unit.
     * leases.unit_id stays the MASTER unit (see masterUnit()).
     */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'lease_unit')
            ->withPivot('is_master')
            ->withTimestamps();
    }

    /** The master unit — the lease's primary unit (= leases.unit_id). */
    public function masterUnit(): BelongsTo
    {
        return $this->unit();
    }

    /**
     * Set the full unit set for this lease and designate one master, keeping
     * leases.unit_id (= master) in sync and recomputing occupancy for every
     * affected unit. The master defaults to the first id when not supplied.
     *
     * @param  array<int>  $unitIds
     */
    public function syncUnits(array $unitIds, ?int $masterUnitId = null): void
    {
        $unitIds = array_values(array_unique(array_map('intval', $unitIds)));
        if ($unitIds === []) {
            return;
        }

        $master = in_array((int) $masterUnitId, $unitIds, true) ? (int) $masterUnitId : $unitIds[0];
        $previous = $this->units()->pluck('units.id')->all();

        $pivot = [];
        foreach ($unitIds as $id) {
            $pivot[$id] = ['is_master' => $id === $master];
        }
        $this->units()->sync($pivot);

        if ((int) $this->unit_id !== $master) {
            $this->forceFill(['unit_id' => $master])->saveQuietly();
        }

        Unit::whereIn('id', array_unique([...$previous, ...$unitIds]))
            ->get()
            ->each
            ->recomputeStatus();
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
        return $this->hasMany(TenantRequest::class);
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

    public static function generateReference(string $assetCode = 'AW'): string
    {
        $year = now()->format('Y');
        $count = static::whereYear('created_at', $year)->count() + 1;

        return sprintf('LSE-%s-%s-%04d', $assetCode, $year, $count);
    }
}
