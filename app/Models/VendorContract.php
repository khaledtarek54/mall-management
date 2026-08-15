<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[DeletionAllowed(reason: 'operational: expired/terminated rather than removed')]
class VendorContract extends Model
{
    use LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status', 'value', 'start_date', 'end_date'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('vendor_contract');
    }

    protected $fillable = [
        'vendor_id',
        'asset_id',
        'reference',
        'name',
        'status',
        'start_date',
        'end_date',
        'value',
        'notice_period_days',
        'auto_renews',
        'renewal_alert_for',
        'sla_penalty_basis',
        'sla_penalty_rate',
        'currency',
        'scope',
        'notes',
    ];

    protected $casts = [
        'sla_penalty_rate' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'notice_period_days' => 'integer',
        'auto_renews' => 'boolean',
        'notice_deadline' => 'date',
        'renewal_alert_for' => 'date',
        'value' => 'decimal:2',
    ];

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<VendorBill, $this> */
    public function bills(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VendorBill::class);
    }

    // ============ Commitment tracking (committed vs actual) ============
    //
    // `value` is what was committed when the contract was signed. Without these, it was a
    // decorative number: nothing compared it to what the vendor has actually invoiced, so a
    // EGP 500k contract could quietly absorb EGP 5m of bills. Cancelled bills don't consume
    // the commitment — they were withdrawn, not incurred.

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<VendorContractAmendment, $this> */
    public function amendments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VendorContractAmendment::class);
    }

    /** Total invoiced against this contract to date (gross, excluding cancelled bills). */
    public function billedToDate(): float
    {
        return (float) $this->bills()->where('status', '!=', 'cancelled')->sum('total');
    }

    /**
     * The commitment as it stands TODAY: the signed value plus every approved change order.
     *
     * `value` alone is what was agreed at signature. Comparing spend against it would flag a
     * legitimately varied contract as over-run forever — and a flag that cries wolf is one the
     * operator stops reading.
     */
    public function effectiveValue(): float
    {
        return round((float) $this->value + (float) $this->amendments()->sum('value_delta'), 2);
    }

    /** Commitment left before the contract is fully drawn; negative once it is over-run. */
    public function remainingValue(): float
    {
        return round($this->effectiveValue() - $this->billedToDate(), 2);
    }

    /** Has the vendor invoiced more than the contract committed? A flag to investigate, not a block. */
    public function isOverCommitted(): bool
    {
        return $this->effectiveValue() > 0 && $this->remainingValue() < 0;
    }

    // ============ Renewal notice ============

    /**
     * Keep the derived notice deadline in step with the term.
     *
     * `notice_deadline` is stored, not computed in SQL: "a date minus a COLUMN of days" has no
     * portable expression across MySQL and SQLite, and as a real column it stays indexable and
     * sortable. Recomputed on every save so editing the end date or the notice period can never
     * leave a stale deadline behind — the class of bug that would silently stop the chase.
     */
    protected static function booted(): void
    {
        static::saving(function (VendorContract $contract) {
            $contract->notice_deadline = ($contract->end_date !== null && $contract->notice_period_days !== null)
                ? $contract->end_date->copy()->subDays((int) $contract->notice_period_days)->startOfDay()
                : null;
        });
    }

    /**
     * The last day written notice can be served — end_date minus the agreed notice period.
     *
     * THIS is the date a contract manager works to, not end_date: by the end date every decision
     * has already been made for you. Null when no notice period was agreed.
     */
    public function noticeDeadline(): ?Carbon
    {
        return $this->notice_deadline === null
            ? null
            : Carbon::instance($this->notice_deadline)->startOfDay();
    }

    /** Is the notice deadline reached (or passed) on an active contract? */
    public function isNoticeDue(?Carbon $on = null): bool
    {
        $deadline = $this->noticeDeadline();

        return $this->status === 'active'
            && $deadline !== null
            && ($on ?? Carbon::today())->startOfDay()->gte($deadline);
    }

    /** Days left to serve notice; negative once the deadline has passed. */
    public function daysToNoticeDeadline(?Carbon $on = null): ?int
    {
        $deadline = $this->noticeDeadline();

        return $deadline === null
            ? null
            : (int) ($on ?? Carbon::today())->startOfDay()->diffInDays($deadline, false);
    }

    /**
     * Active contracts whose notice deadline has arrived — the decision list.
     *
     * Shared by `vendors:scan-contract-renewals`, the Action Required card and the table filter,
     * so the nightly nag, the live count and the list can never disagree.
     */
    public function scopeNoticeDue(Builder $query, ?Carbon $on = null): Builder
    {
        return $query->where('status', 'active')
            ->whereNotNull('notice_deadline')
            ->whereDate('notice_deadline', '<=', ($on ?? Carbon::today())->startOfDay()->toDateString());
    }
}
