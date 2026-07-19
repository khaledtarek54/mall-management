<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Lease extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, LogsActivity, SoftDeletes;

    /** The signed contract + supporting paperwork. */
    public const DOCUMENTS_COLLECTION = 'documents';

    /** Terminal lease states — immutable once reached (CLAUDE.md invariant). */
    public const TERMINAL_STATUSES = ['terminated', 'expired', 'cancelled', 'renewed'];

    protected static function booted(): void
    {
        // ── Arm the rent-escalation anniversary on create ──────────────────────────────────────
        // The daily leases:apply-escalations sweep keys on next_escalation_date, but NO creation
        // path used to populate it (the wizard omitted it, the form has no field, renewal set it
        // null) — so escalation silently never ran for a single real lease (dead feature; the tests
        // only passed because fixtures hand-injected the column). Converge the derivation HERE so
        // every path (wizard, standard form, renewal, seeder) arms it consistently and can't drift.
        // Derived only when escalation is genuinely configured; 'none'/rate-0 leases stay null and
        // are never considered by the sweep.
        static::creating(function (self $lease) {
            if ($lease->next_escalation_date === null
                && in_array($lease->escalation_type, ['fixed_percent', 'cpi'], true)
                && (float) $lease->escalation_rate > 0
                && $lease->commencement_date !== null) {
                $lease->next_escalation_date = Carbon::parse($lease->commencement_date)->addYear()->format('Y-m-d');
            }
        });

        // ── Terminal leases are immutable ──────────────────────────────────────────────────────
        // Once a lease is terminated/expired/cancelled/renewed its fields can't change — only
        // soft-delete/restore (deleted_at). The transition INTO a terminal state is allowed (checked
        // against the ORIGINAL status: termination + renewal both move from 'active'). Closes the
        // hole where the standard Edit form could re-open + mutate a terminated lease.
        static::updating(function (self $lease) {
            $original = $lease->getOriginal('status');
            if (in_array($original, self::TERMINAL_STATUSES, true)) {
                // Block commercial/state changes; still permit benign annotations (notes/metadata),
                // timestamps, and soft-delete/restore. This stops the exploit (re-opening a
                // terminated lease and changing its rent/status/dates) without freezing housekeeping.
                $allowed = ['notes', 'metadata', 'updated_at', 'deleted_at'];
                $blocked = collect($lease->getDirty())->keys()->reject(fn ($k) => in_array($k, $allowed, true));
                if ($blocked->isNotEmpty()) {
                    throw new \DomainException("A '{$original}' lease is immutable — reverse or renew it instead.");
                }
            }
        });
    }

    /**
     * Lease documents live on a PRIVATE disk (not web-accessible). A signed contract is
     * the most confidential artifact in the system — it carries both parties' identities
     * and the commercial terms — and must never be reachable via a guessable public URL.
     * They're served only through the authenticated admin panel.
     *
     * **This was a live exposure until 2026-07-16.** The model implemented HasMedia but
     * registered no collection, so `documents` silently inherited medialibrary's default
     * disk — `env('MEDIA_DISK', 'public')`, and neither the env var nor a config override
     * existed. Every uploaded contract landed in the webroot. Never rely on the default:
     * declare the disk explicitly (MediaPrivacyConformanceTest enforces it).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::DOCUMENTS_COLLECTION)->useDisk('local');
    }

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

    public function camTerms(): HasMany
    {
        return $this->hasMany(LeaseCamTerm::class);
    }

    /**
     * The CAM cost-share ceiling in force for a reconciliation year, or null if the lease has no
     * cap term for/before that year. Picks the effective-dated term with the greatest
     * effective_year ≤ the reconciled year, then resolves its ceiling.
     */
    public function resolveCamCeiling(int $reconciledYear): ?float
    {
        $term = $this->camTerms()
            ->where('effective_year', '<=', $reconciledYear)
            ->orderByDesc('effective_year')
            ->first();

        return $term?->resolveCeiling($reconciledYear);
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

    /** Terminal = terminated/expired/cancelled/renewed — the lease is immutable in this state. */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isExpiringSoon(int $days = 90): bool
    {
        if (! $this->expiry_date) {
            return false;
        }

        return $this->expiry_date->isBetween(now(), now()->addDays($days));
    }

    /**
     * Holdover = an active lease PAST its end date. It still occupies the unit + projects it as
     * occupied, but the monthly billing engine excludes it (period past expiry) — so a held-over
     * tenant trades rent-free until someone renews or terminates. Surfaced on the ActionRequired
     * dashboard so it can never go silent. (Automatic holdover *billing* is a deferred decision.)
     */
    public function scopeHoldover($query)
    {
        return $query->where('status', 'active')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString());
    }

    public function isHoldover(): bool
    {
        return $this->status === 'active'
            && $this->expiry_date !== null
            && $this->expiry_date->startOfDay()->lt(now()->startOfDay());
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
