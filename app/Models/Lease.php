<?php

namespace App\Models;

use App\Contracts\BillableAgreement;
use App\Models\Concerns\AllocatesDocumentNumber;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\Lease\ActsAsBillableAgreement;
use App\Models\Concerns\Lease\DeterminesFitOutGrace;
use App\Models\Concerns\Lease\HasCamTerms;
use App\Models\Concerns\Lease\HasLeasePremises;
use App\Models\Concerns\Lease\HasLeaseTermState;
use App\Models\Concerns\Lease\HasRenewalLineage;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PropertyOwned;
use App\Support\DocumentNumbering;
use App\Support\InvoiceItemSettlement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[DeletableWhenUnused(blockedBy: ['invoices', 'charges', 'salesDeclarations', 'camAllocations', 'tenantRequests', 'renewals', 'deposits', 'postDatedCheques', 'events'], instead: 'terminate the lease — that is the documented end of a tenancy, and it keeps the billing history')]
#[PropertyOwned(via: 'unit')]
class Lease extends Model implements BillableAgreement, HasMedia
{
    use ActsAsBillableAgreement, AllocatesDocumentNumber, DeterminesFitOutGrace, HasCamTerms, HasFactory, HasLeasePremises, HasLeaseTermState, HasRenewalLineage, HasSearchText, InteractsWithMedia, LogsActivity, RefusesDeletionWhenReferenced, SoftDeletes;
    use HasCustomFields;

    /** The signed contract + supporting paperwork. */
    public const DOCUMENTS_COLLECTION = 'documents';

    /**
     * A lease is found by its reference. Tenant and unit are reached through relation
     * search against THEIR blobs — never copied into this one (see the trait docblock).
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->reference,

            // The operator's own fields (D-7). `metadata` is this row's own attribute, so this
            // honours the no-relations rule and re-folds whenever the record saves.
            ...$this->customFieldSearchValues(),
        ];
    }

    protected static function booted(): void
    {
        // ── Allocate a reference when none was supplied — under the lock, held across the INSERT ──
        //
        // Deliberately NOT Invoice's "always re-generate" rule, and the difference is the point.
        // Nothing legitimately supplies an invoice NUMBER, so overwriting one is free. A lease
        // reference is different: **importing an operator's existing leases means importing the
        // contract references they already use**, and those must survive the insert. Overwriting
        // unconditionally also silently renamed any lease created with a deliberate reference.
        //
        // A supplied duplicate is therefore refused by the UNIQUE index rather than quietly
        // renumbered, which is the correct answer for someone else's data. Generated references
        // are safe by construction: `generateUniqueReference()` is MAX-based over `withTrashed()`
        // with a collision loop, and the lock stops two concurrent creates racing to the same
        // number. If the lock times out, the loop and the index remain.
        static::creating(function (self $lease) {
            if (filled($lease->reference)) {
                return;
            }

            $assetCode = $lease->unit?->asset?->code ?: 'AW';

            $lease->reference = $lease->allocateDocumentNumber(
                static::referencePrefix($assetCode),
                fn (): string => static::generateUniqueReference($assetCode),
            );
        });

        // ── The escalation clause and its terms are kept consistent on EVERY write ─────────────
        // This was a `creating` hook, which covered exactly half of the problem.
        //
        //  1. **Arming.** The daily `leases:apply-escalations` sweep keys on
        //     `next_escalation_date`, and no creation path used to populate it — so escalation
        //     silently never ran for a single real lease. That was fixed on create. But adding a
        //     clause to an EXISTING lease (the ordinary way an operator records a term they missed,
        //     or switches `none` → `fixed_percent`) still left the column null, and `whereNotNull`
        //     excluded the lease for the rest of its term. The same dead feature, one edit away.
        //
        //  2. **Clearing.** The form shows a rate box only for the types that state a rate, so a
        //     lease switched to `none` keeps whatever rate, amount and collar it had — invisible in
        //     the UI, still sitting in the columns, and read again the moment somebody switches the
        //     type back. A field the operator cannot see must not hold a value that can take effect.
        //
        // Both belong on `saving` and in the MODEL rather than the form: the importer, the API and
        // `LeaseRenewalService` all write leases without ever rendering a field.
        //
        // The anniversary stays `commencement + 1 year` even when that is in the past. A backlog is
        // already how this system models a late clause — the sweep applies ONE step per run and
        // rolls forward, so a mid-term lease catches up over successive nights instead of
        // compounding several years in a single pass.
        //
        // **Clearing keys on the TYPE; arming keys on the FIGURE**, and the asymmetry is load-bearing.
        // `none` is the only value that means "there is no clause", and it is the only one whose
        // fields the form hides — so it is the only one whose terms may be discarded. A
        // `fixed_percent` stated at 0% is a different animal: it is a real clause with a zero step,
        // the rate box is on screen, and `RentEscalationService` deliberately keeps such a lease in
        // its sweep so it can roll the date forward once a year rather than reconsider it every
        // night. Clearing on `! escalatesContractually()` looked equivalent and dropped exactly
        // those leases out of the sweep for good (caught by `RentEscalationTest` and
        // `FixedAmountEscalationTest`, both of which pin the rolled date).
        static::saving(function (self $lease) {
            if ($lease->escalation_type === 'none') {
                $lease->escalation_rate = 0;
                $lease->escalation_amount = null;
                $lease->escalation_floor_rate = null;
                $lease->escalation_ceiling_rate = null;
                $lease->next_escalation_date = null;

                return;
            }

            if ($lease->escalatesContractually()
                && $lease->next_escalation_date === null
                && $lease->commencement_date !== null) {
                // The clause's own interval, not a literal year (EG-30 / M-6). This hook ARMS the
                // first step, and it was the sibling the interval change left behind: a biennial
                // clause got its first escalation armed twelve months out and stepped a year early,
                // once, before the sweep's own rolling took over — a rent increase the tenant never
                // agreed to, on the first anniversary, with nothing on screen to say so.
                $lease->next_escalation_date = $lease
                    ->escalationDateAfter(CarbonImmutable::parse($lease->commencement_date))
                    ->format('Y-m-d');
            }
        });

        // ── The escalation collar must be a range, not a contradiction ─────────────────────────
        // A floor above the ceiling has no reading: `RentEscalationService::collar()` applies the
        // floor and then the ceiling, so the ceiling silently wins and the "minimum" the operator
        // typed is the one thing that cannot happen. Refused at the model so an import or an API
        // write is covered too — the form's `gte()` only guards the screen.
        static::saving(function (self $lease) {
            if ($lease->escalation_floor_rate !== null
                && $lease->escalation_ceiling_rate !== null
                && (float) $lease->escalation_floor_rate > (float) $lease->escalation_ceiling_rate) {
                throw new \DomainException(__('admin.errors.escalation_collar_inverted'));
            }
        });

        // ── A lease's term cannot run backwards ────────────────────────────────────────────────
        // `expiry_date` has THREE writers — the standard form, `LeaseTerminationService` (which
        // stamps the termination date onto it) and `LeaseRenewalService` — and the rule lived on
        // exactly one of them, as an `->after()` on the form's DatePicker. The terminate action's
        // DatePicker carried no constraint at all, so terminating with a mis-keyed earlier date
        // produced a lease that reads expired while its status is active, that `activeInPeriod()`
        // can never match (so it bills nothing, ever again), and recurring charges stamped
        // `end_date` BEFORE their own `start_date` — the shape `atriom:audit-charge-schedules`
        // exists to catch on import, minted in-app.
        //
        // Guarded on BOTH columns: fixing only the expiry side leaves the identical broken state
        // reachable by moving commencement forward instead.
        //
        // EQUAL IS ALLOWED. A lease terminated on its own commencement date — a deal that collapses
        // at handover — is legitimate and must stay recordable. The form keeps the stricter
        // `->after()` for NEW leases, where a zero-day term is nonsense: this layer carries the
        // invariant every writer must obey, the form adds the product rule for the create path.
        static::saving(function (self $lease) {
            if ($lease->commencement_date === null || $lease->expiry_date === null) {
                return;
            }

            $commencement = Carbon::parse($lease->commencement_date)->startOfDay();
            $expiry = Carbon::parse($lease->expiry_date)->startOfDay();

            if ($expiry->lt($commencement)) {
                throw new \DomainException(__('admin.errors.lease_expiry_before_commencement', [
                    'commencement' => $commencement->toDateString(),
                    'expiry' => $expiry->toDateString(),
                ]));
            }
        });

        // ── A deposit cannot be negative ───────────────────────────────────────────────────────
        // `minValue(0)` on the form and nothing behind it. Low severity, and worth saying so: this
        // is the CONTRACTUAL figure — the money that actually moves comes from `deposit_transactions`
        // — so a negative one cannot mis-pay anyone. What it can do is print a nonsense
        // "contractual deposit" line on the move-out statement the operator hands the tenant
        // (`MoveOutStatementService::for()`). Refused rather than clamped: silently turning -5,000
        // into 0 hides the typo instead of reporting it.
        static::saving(function (self $lease) {
            if ($lease->security_deposit !== null && (float) $lease->security_deposit < 0) {
                throw new \DomainException(__('admin.errors.negative_security_deposit'));
            }
        });

        // ── Rate-priced rent is DERIVED, from every writer ─────────────────────────────────────
        // A lease priced per m² must never carry a monthly figure that disagrees with its own rate
        // and area. Enforced here rather than in the form so an import, a service or a future
        // screen cannot drift — the same reason the NOT-NULL coercions live at this layer.
        //
        // Only on CREATE and when the rate or basis actually changed: re-deriving on every save
        // would silently overwrite an amount a later expansion legitimately set for a period, and
        // the schedule — not this column — is the record of what billed.
        static::saving(function (self $lease) {
            if ($lease->rent_pricing_basis !== self::RENT_RATE) {
                return;
            }

            // On CREATE always — a typed monthly figure cannot outrank the rate the deal was
            // struck at. On UPDATE only when the rate moved and the caller did NOT state a rent in
            // the same save: `LeaseRentChangeService` re-rates and re-prices together, on an
            // effective date this hook knows nothing about, and must not be second-guessed.
            $stated = $lease->exists && $lease->isDirty('base_rent_monthly');

            if (! $lease->exists
                || ($lease->isDirty(['base_rent_rate_per_sqm_year', 'rent_pricing_basis']) && ! $stated)) {
                $derived = $lease->deriveBaseRentFromRate();

                if ($derived !== null) {
                    $lease->base_rent_monthly = $derived;
                }
            }
        });

        // ── A deposit agreed as "three months' rent" stays three months' rent ──────────────────
        // `security_deposit` is a flat figure and rent escalates, so on a 7% clause a 3× deposit
        // covers 2.62 months by year three and 2.29 by year five: the landlord's security erodes by
        // nearly a quarter over a term, silently, and precisely as a tenant becomes more likely to
        // default. Yardi tracks the requirement against rent for this reason.
        //
        // In the MODEL, beside the rate derivation above and for the same reason: the escalation
        // sweep, the Change Rent action, a renewal (which copies `security_deposit` forward while
        // setting a NEW rent — the same erosion, one renewal at a time), the importer and the API
        // all write leases, and only one of them is a form. One seam covers them all.
        //
        // **Null means flat, and nothing moves.** A deposit agreed as a sum unrelated to rent is a
        // real deal; inferring a multiple by dividing the deposit by the rent would invent a term
        // nobody agreed to.
        static::saving(function (self $lease) {
            if ($lease->security_deposit_months === null) {
                return;
            }

            $required = round((float) $lease->base_rent_monthly * (float) $lease->security_deposit_months, 2);

            if ((float) $lease->security_deposit !== $required) {
                $lease->security_deposit = $required;
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

    /**
     * Columns a RENEWAL deliberately does not inherit, each with the reason.
     *
     * `LeaseRenewalService` derives its payload from `$fillable` minus this list, so a new lease
     * column is carried by default and dropping one is a decision somebody has to write down.
     *
     * It is that way round because the opposite failed: the service enumerated what to copy, the
     * table grew from ~24 columns to 43, and **14 negotiated terms were silently lost on every
     * renewal** — the escalation amount and its collar, the rate-pricing basis, the per-lease
     * late-fee terms, the percentage-rent deduction clause, the holdover uplift. None of them
     * errored. `LeaseRenewalCarriesTermsTest` fails the build on a column that is neither carried
     * nor named here.
     *
     * @var array<string, string>
     */
    public const RENEWAL_RESETS = [
        // ── Identity and term: the renewal's own, set explicitly by the service ────────────────
        'reference' => 'the renewal gets its own document number.',
        'previous_lease_id' => 'points AT the original — set by the service, not copied.',
        'status' => 'a renewal starts active regardless of how the original ended.',
        'commencement_date' => 'the renewal term, supplied by the operator.',
        'expiry_date' => 'same.',
        'term_months' => 'same.',
        'base_rent_monthly' => 'the renegotiated rent — the whole point of renewing.',
        'service_charge_monthly' => 'same.',

        // ── State that belonged to the ORIGINAL tenancy ───────────────────────────────────────
        'possession_date' => 'the tenant took possession once, at the start of the original lease.',
        'rent_commencement_date' => 'fit-out grace was for the original build-out; a renewal has no new one.',
        'fit_out_scope' => 'same — there is no fit-out to scope on a renewal.',
        'next_escalation_date' => 'recomputed by the escalation hook in `Lease::booted` from the renewal\'s own dates; copying the original\'s would escalate against a term that has ended.',
        'holdover_from' => 'holdover is a state the ORIGINAL entered by running past expiry. A renewal starts inside its term. (`holdover_rate_pct` — the negotiated uplift — DOES carry.)',
        'expiry_reminder_notified_at' => 'a notification stamp about the original\'s expiry.',
    ];

    protected $fillable = [
        // How a PART month is priced (EG-29). Null = whatever the property says.
        'proration_method',
        // The operator's own fields (D-7). A VIRTUAL attribute — `HasCustomFields` routes it
        // through `fillCustomFields()`, which discards keys the catalogue does not define. The
        // `metadata` column itself is deliberately NOT fillable: nothing fills it wholesale.
        'custom_fields',
        'reference',
        'unit_id',
        'unit_ownership_id',
        'tenant_id',
        'previous_lease_id',
        'status',
        'commencement_date',
        'expiry_date',
        'holdover_rate_pct',
        'holdover_from',
        'expiry_reminder_notified_at',
        'term_months',
        'base_rent_monthly',
        'rent_pricing_basis',
        'base_rent_rate_per_sqm_year',
        'service_charge_monthly',
        'has_marketing_levy',
        'marketing_levy_rate',
        'possession_date',
        'rent_commencement_date',
        'fit_out_scope',
        'billing_frequency',
        'currency',
        'security_deposit',
        'security_deposit_months',
        'escalation_rate',
        'escalation_amount',
        'escalation_floor_rate',
        'escalation_ceiling_rate',
        'escalation_index_code',
        'escalation_index_base_value',
        'escalation_index_lag_months',
        'escalation_type',
        'escalation_interval_months',
        'next_escalation_date',
        'has_percentage_rent',
        'percentage_rent_threshold',
        'percentage_rent_rate',
        'percentage_rent_calculation_type',
        'percentage_rent_frequency',
        'percentage_rent_billing_frequency',
        'percentage_rent_deductible_types',
        'percentage_rent_sales_exclusions',
        'payment_terms_days',
        'late_fee_percent',
        'late_fee_grace_days',
        'late_fee_minimum',
        'late_fee_maximum',
        'late_fee_recurrence_days',
        'notes',
    ];

    // Non-nullable boolean columns: default the in-memory model so a
    // service-created lease (which may omit them) never propagates null into
    // the NOT NULL columns (e.g. on renewal before a DB re-read).
    protected $attributes = [
        'has_percentage_rent' => false,
        'has_marketing_levy' => true, // preserve today's behaviour: every lease gets the levy by default
        // NEW leases default to the STANDARD (net) abatement — base rent free, service charge
        // still payable. The COLUMN default is `gross`, so every lease that already existed keeps
        // the grace it was actually billed under; retroactively re-billing a live tenancy is not a
        // migration. See the migration and docs/gap-analysis/README.md Q2.
        'fit_out_scope' => self::FIT_OUT_RENT_ONLY,
        'billing_frequency' => 'monthly', // bill monthly unless set to quarterly/semiannual/annual
        'percentage_rent_frequency' => 'monthly', // fresh monthly breakpoint unless set to annual (cumulative)
        'percentage_rent_billing_frequency' => 'monthly', // WHEN the overage is charged — a separate term from the basis above
    ];

    protected $casts = [
        'percentage_rent_deductible_types' => 'array',
        'percentage_rent_sales_exclusions' => 'array',
        'commencement_date' => 'date',
        'expiry_date' => 'date',
        'holdover_rate_pct' => 'decimal:2',
        'base_rent_rate_per_sqm_year' => 'decimal:2',
        'late_fee_percent' => 'decimal:2',
        'late_fee_grace_days' => 'integer',
        'late_fee_minimum' => 'decimal:2',
        'late_fee_maximum' => 'decimal:2',
        'holdover_from' => 'date',
        'expiry_reminder_notified_at' => 'datetime',
        'next_escalation_date' => 'date',
        'base_rent_monthly' => 'decimal:2',
        'service_charge_monthly' => 'decimal:2',
        'security_deposit' => 'decimal:2',
        'security_deposit_months' => 'decimal:2',
        // Cast declared purely so static analysis reads the column as a string. It was created as a
        // DB-level `enum('none','fixed_percent','cpi')` in 2024, and larastan derives the attribute
        // type from that migration while ignoring the `->change()` that converted it to a varchar —
        // so without this, every comparison against `fixed_amount` reads as "always false". A no-op
        // at runtime; the truth about allowed values lives in the model + form validation, per the
        // project's no-DB-enums convention.
        'escalation_type' => 'string',
        'escalation_rate' => 'decimal:2',
        'escalation_amount' => 'decimal:2',
        'escalation_floor_rate' => 'decimal:2',
        'escalation_ceiling_rate' => 'decimal:2',
        'escalation_index_base_value' => 'decimal:4',
        'escalation_index_lag_months' => 'integer',
        'escalation_interval_months' => 'integer',
        'percentage_rent_threshold' => 'decimal:2',
        'percentage_rent_rate' => 'decimal:2',
        'has_percentage_rent' => 'boolean',
        'has_marketing_levy' => 'boolean',
        'marketing_levy_rate' => 'decimal:2',
        'possession_date' => 'date',
        'rent_commencement_date' => 'date',
        'billing_frequency' => 'string',
        'metadata' => 'array',
    ];

    /**
     * The unit ownership this tenancy sits under — the OWNER's own tenant.
     *
     * Yardi's construct: when an owner lets his unit out, the lessee is a sub-record under the
     * owner's unit. The lessee is a real occupant for access, violations, SLA, fit-out and every
     * mall rule — and **the owner stays liable for the assessments**. Owner of record is not
     * occupant of record.
     *
     * Null for an ordinary lease of space the mall still owns, which is almost all of them.
     *
     * Deliberately NOT accompanied by a "do we collect this rent" flag: that is a term of the
     * management agreement, held on the ownership (`management_mode`), and a lease we do not bill
     * rent on simply carries no rent charge row — which the billing engine already handles by
     * raising nothing.
     *
     * @return BelongsTo<UnitOwnership, $this>
     */
    public function unitOwnership(): BelongsTo
    {
        return $this->belongsTo(UnitOwnership::class);
    }

    /** Is this tenancy the OWNER's, rather than one the mall signed itself? */
    public function isUnderOwnership(): bool
    {
        return $this->unit_ownership_id !== null;
    }

    /**
     * Does this lease state a rent increase the system will actually apply?
     *
     * A TYPE alone is not a clause — `fixed_percent` at 0% and `fixed_amount` at nothing per year
     * are both "no increase", stated in two words instead of one. This is the single reading of
     * that question: the `saving` hook arms or clears the escalation terms from it, and both halves
     * of the old `creating` hook derived it inline.
     *
     * **`cpi` changed on 2026-08-19, when the index register arrived.** It used to count as
     * configured only if somebody had typed an `escalation_rate` — the sole way an index clause was
     * expressible when the sweep could not apply one at all. A CPI lease is now configured when it
     * names an INDEX and a base value to measure from, because that is what actually produces a
     * step (`RentIndex` + `RentEscalationService::indexRateFor()`).
     *
     * The rate-only shape still counts, deliberately. Those leases could never escalate (the sweep
     * skipped every CPI lease), so treating them as unconfigured would be truthful about the future
     * and destructive about the past: the `saving` hook CLEARS the escalation terms of a lease that
     * is not configured, so a migration-day re-save would wipe the anniversary an operator had
     * recorded. Left armed and still inert, which is exactly what it was yesterday, until someone
     * names the index.
     */
    /**
     * Months in one percentage-rent BILLING period: monthly=1, quarterly=3, annual=12.
     *
     * Separate from `billingCycleMonths()` (base rent) and from `percentage_rent_frequency` (the
     * calculation basis) on purpose — all three are independent lease terms, and a lease routinely
     * states a different cadence for each: rent quarterly in advance, sales declared monthly,
     * overage settled annually in arrears.
     */
    public function percentageRentBillingMonths(): int
    {
        return match ((string) $this->percentage_rent_billing_frequency) {
            'quarterly' => 3,
            'annual' => 12,
            default => 1,
        };
    }

    public function escalatesContractually(): bool
    {
        return match ($this->escalation_type) {
            'fixed_percent' => (float) $this->escalation_rate > 0,
            // Either the new index shape or the legacy rate-only one — see the note above.
            'cpi' => (filled($this->escalation_index_code) && (float) $this->escalation_index_base_value > 0)
                || (float) $this->escalation_rate > 0,
            'fixed_amount' => (float) $this->escalation_amount > 0,
            default => false,
        };
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /**
     * The percentage-rent breakpoint ladder, when this lease is billed on a tiered basis.
     *
     * @return HasMany<LeasePercentageRentTier, $this>
     */
    public function percentageRentTiers(): HasMany
    {
        return $this->hasMany(LeasePercentageRentTier::class)->orderBy('from_amount');
    }

    /**
     * Options recorded on this lease — renewal, termination, expansion, first refusal.
     *
     * @return HasMany<LeaseOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(LeaseOption::class);
    }

    /**
     * The lease's commercial history, newest first — every dated, reasoned change (story LE-01).
     *
     * Newest first because that is the question the timeline answers: "what happened to this lease
     * recently". The reconstruct-a-past-date question sorts the other way and is served by
     * {@see eventsAsOf()}.
     *
     * @return HasMany<LeaseEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(LeaseEvent::class)
            ->orderByDesc('effective_date')
            ->orderByDesc('id');
    }

    /**
     * Parking bays, stores and signage let alongside the premises (space model).
     *
     * Deliberately NOT `units()` — a rentable item is not lettable area, and keeping the two
     * relations apart is what stops one being summed into the other. See
     * [docs/benchmarks/yardi/09-yardi-space-and-parking.md](../../docs/benchmarks/yardi/09-yardi-space-and-parking.md).
     *
     * @return BelongsToMany<RentableItem, $this>
     */
    /**
     * Parking bays, storage and signage this lease holds.
     *
     * A MORPH since 2026-08-19: the holder of a rentable item is the agreement with the ledger, not
     * specifically a lease. That is Voyager's own model — rentable items are assigned to the
     * customer RECORD (`docs/benchmarks/yardi/09-yardi-space-and-parking.md` §2, "assign Rentable
     * Items … to both new and existing residents"), and in its condo product the unit owner simply
     * is that record. A `UnitOwnership` therefore holds bays through the identical relation.
     */
    /**
     * The lease abstract — the legal terms that are not money.
     *
     * Voyager's clause register (`docs/benchmarks/yardi/01-yardi-lease-administration.md` §7). The
     * two that matter most are co-tenancy and kick-out, which the benchmark calls **contingent
     * money**: while they lived only in the uploaded PDF, nothing could report which leases carried
     * one.
     *
     * @return HasMany<LeaseClause, $this>
     */
    public function clauses(): HasMany
    {
        return $this->hasMany(LeaseClause::class);
    }

    public function rentableItems(): MorphToMany
    {
        return $this->morphToMany(RentableItem::class, 'holder', 'rentable_item_holdings')
            ->withPivot(['effective_from', 'effective_to', 'monthly_rate'])
            ->withTimestamps();
    }

    /**
     * The history as it stood on a past date — the auditor's view.
     *
     * @return Collection<int, LeaseEvent>
     */
    public function eventsAsOf(CarbonImmutable $on): Collection
    {
        return $this->events
            ->filter(fn (LeaseEvent $e) => $e->effectiveOn()->lte($on))
            ->sortBy([
                fn (LeaseEvent $a, LeaseEvent $b) => $a->effective_date <=> $b->effective_date,
                fn (LeaseEvent $a, LeaseEvent $b) => $a->id <=> $b->id,
            ])
            ->values();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    // NEVER-deletable money records that reference the lease directly and can exist BEFORE any
    // invoice — a security deposit is recorded at signing, a year of post-dated cheques is lodged up
    // front. Listed so DeletionPolicy blocks deleting a lease that carries them (pre-go-live review).
    public function deposits(): HasMany
    {
        return $this->hasMany(DepositTransaction::class);
    }

    /**
     * The month-by-month difference between what this lease BILLED and what the books RECOGNISE.
     *
     * Straight-line rent (EAS 49 / IFRS 16): an escalating lease is recognised at its average rent
     * from day one, so the early months recognise MORE than they bill and the late months less. The
     * engine, its journalizer and its scheduled command all existed; nothing in the panel ever showed
     * the result, so a lease's straight-line position was reachable only from a CLI (fixed 2026-08-18).
     */
    public function straightLineAdjustments(): HasMany
    {
        return $this->hasMany(StraightLineRentAdjustment::class);
    }

    /**
     * The security deposit ACTUALLY held against this lease — receipts, less refunds, forfeits and
     * anything already netted against the tenant's invoices.
     *
     * **The one definition.** It lived in `MoveOutStatementService`, which meant the answer to "has
     * this tenant paid their deposit?" was only reachable from a move-out. The lease list, the lease
     * page and the tenant's own portal each needed it, and the alternative to putting it here was
     * three re-implementations of a subtraction that must never disagree.
     *
     * The `DepositApplication` term is the one people leave out: omitting it lets the same deposit
     * settle the arrears AND be refunded in full.
     *
     * Uses the loaded relation when it is there, so a list can eager-load and not issue a query per
     * row. Only RECORDED movements count — a draft is an intention, and settling against intentions
     * is how a landlord refunds money it never received.
     */
    public function depositHeld(): float
    {
        $rows = $this->relationLoaded('deposits')
            ? $this->deposits
            : $this->deposits()->get();

        $recorded = $rows->where('status', 'recorded');

        $held = $recorded->where('type', 'receipt')->sum('amount')
            - $recorded->where('type', 'refund')->sum('amount')
            - $recorded->where('type', 'forfeit')->sum('amount')
            - DepositApplication::where('lease_id', $this->id)->sum('amount')
            // …plus deposits BILLED and since paid (Voyager's model, 2026-08-18). A deposit charged
            // on an invoice is held only to the extent the tenant has settled that line, which is
            // why this reads the settlement and not the line total: an unpaid deposit invoice is a
            // receivable, not money in the bank, and treating it as held would refund at move-out
            // what was never received.
            + $this->settledDepositBillings();

        return round((float) $held, 2);
    }

    /**
     * The part of any BILLED security deposit the tenant has actually settled.
     *
     * Derived from `InvoiceItemSettlement`, the one place that answers "how much of this line has
     * been paid" — per the money invariants, a per-item balance is never stored, because that would
     * be a second truth about the same settlement. Cancelled and written-off invoices claim nothing.
     */
    public function settledDepositBillings(): float
    {
        $invoices = Invoice::query()
            ->where('lease_id', $this->id)
            ->whereNotIn('status', ['cancelled', 'credited', 'written_off'])
            ->whereHas('items', fn ($q) => $q->where('type', 'security_deposit'))
            ->with('items')
            ->get();

        $settled = 0.0;

        foreach ($invoices as $invoice) {
            $settled += (float) InvoiceItemSettlement::for($invoice)
                ->where('type', 'security_deposit')
                ->sum('settled');
        }

        return round($settled, 2);
    }

    /**
     * Agreed, less held — never negative.
     *
     * This is the number that was missing everywhere: a lease says 180,000, the bank has 150,000,
     * and nothing on any list said so. An operator asking "who still owes me a deposit?" had to open
     * every lease in turn.
     */
    public function depositShortfall(): float
    {
        return round(max((float) ($this->security_deposit ?? 0) - $this->depositHeld(), 0), 2);
    }

    public function postDatedCheques(): HasMany
    {
        return $this->hasMany(PostDatedCheque::class);
    }

    public function tenantRequests(): HasMany
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

    // ============ BillableAgreement ============
    //
    // The part of a lease that is true of ANY agreement raising AR — who owes, in what currency,
    // and which column records that this agreement raised the invoice. A unit ownership answers the
    // same three (plan 08) and the billing machinery downstream never learns the difference.
    // Everything else the interface asks for (assetId, paymentTermsDays, billingCycleMonths,
    // isBillableForPeriod, charges) this model already had, which is why the seam sits here.

    /**
     * The SQL half of "owes a sales declaration for this period and hasn't filed one".
     *
     * `missingSalesDeclarationsFor()` below is the authoritative answer, but it returns a
     * Collection because the fit-out exemption is model logic rather than a column. A table
     * filter needs a Builder, so this scope is the query part alone — and the two are used
     * in exactly one direction: the ActionRequired card counts `scope + reject(fit-out)`, the
     * Leases table filter applies the scope. The filter is therefore a SUPERSET of the card:
     * clicking a count of 3 can land on 4 rows if one is still in fit-out, but it can never
     * land on a list MISSING a lease the card counted. That is the safe direction for a
     * "go and chase these" link; the reverse would send someone to a page that appears to
     * contradict the number they clicked.
     */
    public function scopeOwingSalesDeclaration($query, CarbonImmutable $periodStart)
    {
        return $query->where('status', 'active')
            ->where('has_percentage_rent', true)
            ->whereNotNull('commencement_date')
            ->whereDate('commencement_date', '<=', $periodStart->endOfMonth())
            ->whereDoesntHave('salesDeclarations', fn ($q) => $q->whereDate('period_start', $periodStart));
    }

    /**
     * Active percentage-rent leases that owe a sales declaration for the period and have not filed
     * one — past their fit-out grace, so a lease that isn't billable yet isn't chased either.
     *
     * ONE definition, two callers: `sales:scan-missing-declarations` (which chases the tenant) and
     * the month-end close checklist (which counts them as an outstanding task). The same rule
     * lived only inside the command until 2026-08-08; a second copy in the checklist would have
     * been the third place "which leases owe a declaration" was written down, and the first place
     * it silently disagreed. Same reasoning as `isBillableForPeriod()`/`scopeBillableForPeriod()`
     * above.
     *
     * @return Collection<int, static>
     */
    public static function missingSalesDeclarationsFor(
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        ?int $assetId = null,
    ): Collection {
        return static::query()
            ->where('status', 'active')
            ->where('has_percentage_rent', true)
            ->whereNotNull('commencement_date')
            ->whereDate('commencement_date', '<=', $periodEnd)
            ->whereDoesntHave('salesDeclarations', fn ($q) => $q->whereDate('period_start', $periodStart))
            ->when($assetId, fn ($q) => $q->whereHas('unit', fn ($u) => $u->where('asset_id', $assetId)))
            ->with('tenant')
            ->get()
            // Fit-out is a model-level rule, not SQL — a lease still inside its grace is not yet
            // billable, so it is not yet chaseable either.
            ->reject(fn (self $lease) => $lease->periodInFitOut($periodEnd))
            ->values();
    }

    // ============ Generation helpers ============

    /** `LSE-AW-2026-` — the sequence the numbers below run inside. */
    public static function referencePrefix(string $assetCode = 'AW'): string
    {
        return sprintf('%s-%s-%s-', DocumentNumbering::prefixFor('lease'), $assetCode, now()->format('Y'));
    }

    /**
     * The next lease reference in this property-year.
     *
     * **This was `count() + 1`, and that was a deterministic 500.** `leases.reference` is UNIQUE
     * and the model soft-deletes, so `static::count()` — which the soft-delete scope excludes
     * trashed rows from — falls behind the numbers actually issued. Delete one lease of five and
     * the next create computes `…-0005`, which already exists. The insert throws a duplicate-key
     * error, and it throws again on every subsequent attempt, because the count never recovers:
     * **lease creation stays broken for the rest of the calendar year.** Deleting an unused lease
     * is a supported action (`DeletionPolicy` puts Lease in WHEN_UNUSED, and EditLease offers it),
     * so this was reachable by design, not by misuse.
     *
     * Now MAX-of-prefix over `withTrashed()`, which is monotonic and cannot go backwards — the
     * same shape `Invoice::generateNumber()` has used all along, four files away.
     */
    public static function generateReference(string $assetCode = 'AW'): string
    {
        $prefix = static::referencePrefix($assetCode);

        $last = static::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            // LENGTH first: a plain string sort puts `…-9999` above `…-10000`, so once a
            // series passes its zero-padding MAX returns the wrong row (EG-10).
            ->orderByRaw('LENGTH(reference) DESC, reference DESC')
            ->value('reference');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return sprintf('%s%04d', $prefix, $next);
    }

    /**
     * MAX+1 with a collision loop — the belt to the lock's braces.
     *
     * A gap-free MAX is still only correct if nothing has taken the number since we read it. The
     * lock in `AllocatesDocumentNumber` is the primary guard; this loop is what happens when the
     * lock times out and degrades to unlocked allocation.
     */
    protected static function generateUniqueReference(string $assetCode = 'AW'): string
    {
        $candidate = static::generateReference($assetCode);
        $prefix = static::referencePrefix($assetCode);
        $attempts = 0;

        while (static::withTrashed()->where('reference', $candidate)->exists()) {
            if (++$attempts > 100) {
                throw new \RuntimeException('Unable to allocate a unique lease reference after 100 attempts.');
            }

            $candidate = sprintf('%s%04d', $prefix, (int) substr($candidate, strlen($prefix)) + 1);
        }

        return $candidate;
    }
}
