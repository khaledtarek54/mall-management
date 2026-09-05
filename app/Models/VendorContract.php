<?php

namespace App\Models;

use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[DeletionAllowed(reason: 'operational: expired/terminated rather than removed')]
#[PropertyOwned]
class VendorContract extends Model
{
    use LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'vendor_contract');
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

    /** @return HasMany<VendorBill, $this> */
    public function bills(): HasMany
    {
        return $this->hasMany(VendorBill::class);
    }

    // ============ Commitment tracking (committed vs actual) ============
    //
    // `value` is what was committed when the contract was signed. Without these, it was a
    // decorative number: nothing compared it to what the vendor has actually invoiced, so a
    // EGP 500k contract could quietly absorb EGP 5m of bills. Cancelled bills don't consume
    // the commitment — they were withdrawn, not incurred.

    /** @return HasMany<VendorContractAmendment, $this> */
    public function amendments(): HasMany
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
            // A termination is DATED. `terminated` means "closed early", and until 2026-09-05 the
            // status moved while `end_date` kept the original term — so a retainer schedule bounded
            // by the contract (SW-242) went on billing to a date the contract no longer ran to.
            // The termination day becomes the end date unless an earlier one was stated.
            if ($contract->status === 'terminated'
                && $contract->isDirty('status')
                && ($contract->end_date === null || $contract->end_date->gt(now()->startOfDay()))) {
                $contract->end_date = now()->startOfDay();
            }

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

    /**
     * The contracts an operator standing in `$ids` may see — and there is ONE definition of that.
     *
     * **`whereIn` never matches NULL, and a null `asset_id` here means EVERY mall.** The form says
     * so in as many words (`ContractsRelationManager::form()`: *"a null here is a PORTFOLIO-WIDE
     * contract covering every mall"*), and the migration that introduced `holidays` cites
     * `vendor_contracts` as the shape it copied. Five readers answered the question and only one —
     * the relation-manager table, i.e. the only screen where somebody would have noticed — agreed
     * with that:
     *
     *   - `VendorsTable`'s *contract notice due* chase filter: `whereIn('asset_id', $ids)`
     *   - `ActionRequired`'s notice-due card: the same, under a comment claiming the opposite
     *     (*"null = portfolio-wide, so it scopes directly"*)
     *   - `ActionRequired`'s COI card, through `whereHas('contracts', …)` — so a vendor engaged
     *     ONLY under a portfolio-wide contract had no lapsed certificate on anyone's card
     *   - `VendorResource::getNavigationBadge()`: `where('asset_id', $currentId)`, stricter still
     *
     * So a portfolio-wide contract at its notice deadline was absent from the chase list, from the
     * count beside it and from the badge — while `vendors:scan-contract-renewals`, which scopes not
     * at all, went on e-mailing about it nightly. Measured on `mall_management_qa` (2026-09-04):
     * `select count(*), sum(asset_id is null) from vendor_contracts` → 7 rows, 0 portfolio-wide, so
     * the demo data cannot show this and no screen was visibly wrong — which is why it survived.
     *
     * `$ids === null` is an unrestricted operator: no narrowing. An EMPTY array narrows to the
     * portfolio-wide rows alone, where `->when([], …)` used to skip the clause entirely and show
     * everything.
     *
     * NOT `#[PropertyOwned(portfolioRowsWhenNull: true)]`, deliberately: that flag also feeds
     * `atriom:audit-property-dimension`, which reports a null `asset_id` as a DEFECT unless the
     * model's own RESOURCE is in `PropertyField::PORTFOLIO_LEVEL` — and a contract is edited from a
     * relation manager, so it has no resource. Flipping it would make the first legitimate
     * portfolio-wide contract fail `atriom:preflight` and block a deploy. The consequence is that
     * `OptionDisplay::scope()` still hides such a contract from the vendor-bill and recurring-cost
     * pickers; that half needs the registry flip AND the audit taught, and is recorded rather than
     * half-done.
     *
     * @param  array<int, int>|null  $ids
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInProperties(Builder $query, ?array $ids): Builder
    {
        if ($ids === null) {
            return $query;
        }

        return $query->where(fn (Builder $where) => $where
            ->whereIn($query->qualifyColumn('asset_id'), $ids)
            ->orWhereNull($query->qualifyColumn('asset_id')));
    }
}
