<?php

namespace App\Models;

use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use App\Support\SalesExclusions;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[DeletionAllowed(reason: 'operational: locking is what makes it billable, and a locked one voids rather than deletes')]
#[PropertyOwned(via: 'lease.unit')]
class TenantSalesDeclaration extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, LogsActivity, SoftDeletes;

    /** Media collection holding the tenant's uploaded sales-report file(s). */
    public const REPORT_COLLECTION = 'sales_report';

    public const STATUSES = ['submitted', 'locked', 'disputed'];

    protected $fillable = [
        'lease_id',
        'period_start',
        'period_end',
        'declared_sales',
        'gross_sales',
        'sales_exclusions',
        'is_estimate',
        'deducted_amount',
        'calculated_percentage_rent',
        'declared_at',
        'declared_by_type',
        'declared_by_id',
        'status',
        'locked_at',
        'locked_by_user_id',
        'audit_notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'declared_at' => 'datetime',
        'locked_at' => 'datetime',
        'declared_sales' => 'decimal:2',
        'gross_sales' => 'decimal:2',
        'sales_exclusions' => 'array',
        'is_estimate' => 'boolean',
        'deducted_amount' => 'decimal:2',
        'calculated_percentage_rent' => 'decimal:2',
    ];

    /**
     * **The latest month a turnover declaration can exist for — the month that has ENDED.**
     *
     * A tenant declares a month's sales AFTER that month closes. Both scheduled commands already
     * said so by defaulting to *the previous month* — `sales:scan-missing-declarations` and
     * `sales:estimate-missing` — and this is that rule named once, so a third caller cannot state
     * it differently. Both now read it from here.
     *
     * It matters most to the two reports that DIVIDE by it. Occupancy cost and MAT take cost from
     * invoices, which are raised on the FIRST of a month, and sales from declarations, which arrive
     * after the LAST — so a window ending inside the running month carries a whole month of cost
     * against no sales at all. Measured on the QA books at 2026-09-05: 250,945.00 of September cost
     * against zero September declarations, and a portfolio ratio of 38.05% where the same books over
     * twelve COMPLETE months read 36.88%. In steady state the distortion is exactly 12/11 — +9.09%
     * relative — which is enough to walk a genuine 23.0% tenant across the 25% danger line the
     * occupancy-cost colour band draws (SW-183).
     *
     * `subMonthNoOverflow()`, because 31 March minus a month is 28 February and not 3 March.
     */
    public static function lastDeclarableMonth(?CarbonImmutable $on = null): CarbonImmutable
    {
        return ($on ?? CarbonImmutable::now())->subMonthNoOverflow()->startOfMonth();
    }

    /** The last DAY that month covers — the far end of any window measured against sales. */
    public static function declaredThrough(?CarbonImmutable $on = null): CarbonImmutable
    {
        return self::lastDeclarableMonth($on)->endOfMonth()->startOfDay();
    }

    /**
     * `$end`, or {@see declaredThrough()}, whichever is EARLIER.
     *
     * Applied to an EXPLICIT end as well as to a default, deliberately: "the twelve months ending
     * September", asked in September, is precisely the question that cannot be answered, and
     * honouring it would leave the defect one keystroke away from the operator. A window that ends
     * in a month already closed is never touched.
     *
     * Compared at DAY granularity so a caller that hands over an end-of-day timestamp for the last
     * day of the closed month keeps its own precision instead of being silently rounded down.
     *
     * **The rule is the CALENDAR, never the data.** "Skip any month with no declaration" is the
     * other available fix and it is worse: it drops a missing month from BOTH the cost and the sales
     * side, so a tenant who simply stops filing reads CHEAPER — inverting the one signal the
     * occupancy-cost report exists to give — and it makes the window per-tenant, so two rows of one
     * table would describe different periods and a moving ANNUAL total would stop being annual.
     * Silence is what `sales:scan-missing-declarations` and `sales:estimate-missing` exist to chase;
     * a gap is reported as it always was, through `months_declared` and a ratio that RISES.
     */
    public static function clampToDeclaredMonths(CarbonImmutable $end, ?CarbonImmutable $on = null): CarbonImmutable
    {
        $through = self::declaredThrough($on);

        return $end->startOfDay()->greaterThan($through) ? $through : $end;
    }

    /**
     * What a lock freezes: everything the TENANT stated, and the period it was stated for.
     *
     * Not `calculated_percentage_rent` — that is the system's own share and `retrueAnnualYear()`
     * restates it on locked months by design. Not `status` or `audit_notes` — `voidLocked()` writes
     * both, and blocking them would seal the very door this guard points at.
     */
    public const FROZEN_ONCE_LOCKED = [
        'declared_sales',
        'gross_sales',
        'sales_exclusions',
        'period_start',
        'period_end',
        'lease_id',
    ];

    protected static function booted(): void
    {
        // ── A declaration is a CERTIFICATE, and `declared_sales` is its bottom line ────────────
        //
        // The tenant reports a gross figure; the lease grants deductions; percentage rent is charged
        // on what is left. `declared_sales` has always been that net figure — every calculation reads
        // it — but nothing recorded what it was net OF, so nobody could tell whether the number
        // included the VAT a shop collects for the state. Because the breakpoint is subtracted first,
        // a 14% error there becomes roughly a 70% error in the overage on a typical clause.
        //
        // Derived in the model, beside the lease's rate-priced rent and deposit derivations and for
        // the same reason: the admin form, the portal, the mobile API, the estimator and the importer
        // all write declarations, and only one of them is a screen.
        //
        // **Gross null = the old shape, untouched.** A declaration recorded before this keeps meaning
        // exactly what it meant, and an operator who simply types a net figure still can.
        static::saving(function (self $declaration) {
            if ($declaration->gross_sales === null) {
                return;
            }

            // AN EXCLUSION THE CATALOGUE DOES NOT KNOW IS REFUSED, NEVER DROPPED (SW-164).
            //
            // `SalesExclusions::total()` skips an unrecognised key, and the field is a free
            // `KeyValue` — so `VAT`, `refunds` or `sales returns` typed by hand deducted NOTHING,
            // and the tenant was billed percentage rent on turnover the operator had agreed to
            // exclude. Silent in both directions: the screen shows the derived total, not the parse.
            //
            // Refusing is the safe direction and costs the operator nothing — `other` exists
            // precisely so a real negotiated clause never has to be invented as a new key. On the
            // MODEL rather than the form, because the portal, the importer and the API reach the
            // same column, and this is the one seam all of them cross.
            $unknown = SalesExclusions::unknownKeys($declaration->sales_exclusions);

            if ($unknown !== []) {
                throw new DomainException(__('admin.validation.sales_exclusions_unknown_type', [
                    'types' => implode(', ', $unknown),
                ]));
            }

            $gross = (float) $declaration->gross_sales;
            $excluded = SalesExclusions::total($declaration->sales_exclusions);

            // Refused rather than clamped: deductions larger than the turnover they come off is a
            // typo or a misread column, and silently flooring it at zero would bill percentage rent
            // on a figure nobody can reconcile to the certificate.
            if ($excluded > $gross) {
                throw new DomainException(__('admin.validation.sales_exclusions_exceed_gross'));
            }

            $declaration->declared_sales = round($gross - $excluded, 2);
        });

        // ── A LOCKED DECLARATION IS EVIDENCE, AND EVIDENCE DOES NOT GET RETYPED ────────────────
        //
        // Locking computes the overage, freezes it on the row and RAISES THE INVOICE for it. The
        // figures the tenant certified were still freely editable afterwards, and nothing
        // recomputed: measured on the demo books, a locked July declaration went from 910,000 to
        // 2,000,000 of sales while its stored overage and its invoice both stayed at 7,700, where
        // 84,000 was due. 76,300 hidden, and the document a dispute would be settled on now says
        // one thing while the money says another.
        //
        // The correction path already exists and is careful — `voidLocked()` reverses the overage,
        // voids the invoice, REFUSES if that invoice has been paid, and re-trues the rest of an
        // annual year. This is the guard that makes an operator use it, exactly as the money
        // documents are corrected through cancel / credit note / reverse rather than by editing.
        //
        // **`calculated_percentage_rent` is deliberately NOT frozen.** `retrueAnnualYear()`
        // legitimately restates it on locked months when a sibling month is voided — the tenant's
        // DECLARATION is evidence, the system's computed share is derived, and freezing the second
        // would break the annual basis rather than protect it.
        static::updating(function (self $declaration) {
            if ($declaration->getOriginal('status') !== 'locked') {
                return;
            }

            foreach (self::FROZEN_ONCE_LOCKED as $column) {
                if ($declaration->isDirty($column)) {
                    throw new DomainException(__('admin.validation.locked_declaration_is_evidence', [
                        'field' => __('admin.fields.'.$column),
                    ]));
                }
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'tenant_sales');
    }

    /**
     * The tenant's uploaded sales report lives on a PRIVATE disk (not
     * web-accessible) — it can contain commercial turnover figures and must
     * never be reachable via a guessable public URL. It's streamed only through
     * authenticated, tenant-scoped endpoints (the mobile API attachment
     * controller) and the authed admin panel. Mirrors TenantRequest attachments.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::REPORT_COLLECTION)->useDisk('local');
    }

    /** Whether the tenant has attached at least one sales-report file. */
    public function hasReport(): bool
    {
        return $this->getMedia(self::REPORT_COLLECTION)->isNotEmpty();
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function declaredBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function lockedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_user_id');
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }

    public function isDisputed(): bool
    {
        return $this->status === 'disputed';
    }

    public function periodLabel(): string
    {
        return $this->period_start->isoFormat('MMM YYYY');
    }
}
