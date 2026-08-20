<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * التخصص — a trade: the kind of work, and the kind of contractor who does it.
 *
 * **The spine of the facility model**, per `docs/benchmarks/fm/02-servicechannel-contractor-loop.md`
 * §2. A trade routes the work, decides which vendors may be dispatched to it, and is the axis every
 * maintenance-spend report groups by. Until 2026-08-20 it was a `Select` populated from a
 * translation array, which meant the column was unenforced, the list could not be extended without
 * a deploy in two languages, and — because `vendors` carried no trade at all — nothing in the
 * system could say who was allowed to do the job.
 *
 * ## Trade and craft are one register, deliberately
 *
 * Maximo keeps the **trade** (what the work is) apart from the **craft** (what a person is) and
 * carries the labour rate on the craft. For a mall those are the same list — an HVAC technician
 * does HVAC work — and two registers an operator must keep in step would buy nothing at this
 * scale. So {@see $standard_hourly_rate} lives here, and the work-order cost object reads it to
 * turn reported hours into money. Split them the day one trade genuinely needs several rates.
 *
 * ## Eligibility is a SUGGESTION on the picker, never a filter
 *
 * `vendors()` is what makes "who can take an HVAC fault?" answerable, and the work-order form uses
 * it to open the vendor picker on the eligible ones. It does **not** hide the rest: a hard filter
 * refuses a legitimate value at validation (Filament rejects what a picker cannot label), and the
 * real world has the day the usual contractor is unavailable. The gate that genuinely blocks a
 * dispatch is {@see Vendor::isDispatchable()} — compliance, which is a decision the operator has
 * actually made about that vendor.
 */
#[DeletableWhenUnused(
    blockedBy: ['workOrders', 'servicePlans', 'equipment', 'vendors'],
    instead: 'deactivate it — a trade that has routed work is the dimension every past maintenance-spend report grouped by, and deleting it strands that history',
)]
#[PortfolioShared] // a trade is a trade in every mall; what differs is which vendor covers it
class Trade extends Model
{
    use HasFactory, LogsActivity, RefusesDeletionWhenReferenced, SoftDeletes;

    protected $fillable = [
        'code', 'name_en', 'name_ar', 'standard_hourly_rate', 'default_nte', 'is_active', 'sort_order', 'notes',
    ];

    protected $casts = [
        'standard_hourly_rate' => 'decimal:2',
        'default_nte' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('trade')
            ->logOnly(['code', 'name_en', 'name_ar', 'standard_hourly_rate', 'default_nte', 'is_active', 'sort_order'])
            ->logOnlyDirty();
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(FacilityWorkOrder::class);
    }

    public function servicePlans(): HasMany
    {
        return $this->hasMany(ServicePlan::class);
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    /** The vendors who do this trade — the answer to "who may we dispatch?" */
    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The operator's own language, not a translation key.
     *
     * A trade is a ROW now, so its name is data the operator typed — which is the whole point of
     * the change. `label()` picks the column for the reader's locale rather than resolving
     * `admin.facility.categories.*`, and a trade the operator adds tomorrow is named correctly in
     * both languages without a deploy.
     */
    public function label(): string
    {
        return app()->getLocale() === 'ar'
            ? ($this->name_ar ?: $this->name_en)
            : ($this->name_en ?: $this->name_ar);
    }

    /**
     * The `id => label` map every trade picker reads.
     *
     * **`$keep` is not optional politeness — without it, retiring a trade breaks editing.**
     * Deactivating is the register's own documented alternative to deleting, and the screen guide
     * tells the operator to do exactly that. But Filament validates a `Select` by checking the
     * submitted value against its options with `Rule::in`, so a work order carrying a retired trade
     * would fail validation on a field nobody touched — an operator fixing a typo in the title gets
     * an error on the trade. Measured, not theorised: `RetiredTradeStillEditableTest` fails on the
     * version without this.
     *
     * So the record's CURRENT value is always offered, flagged `⚠` because it is no longer a choice
     * anyone should make afresh — the same convention `Vendor::assignableOptions()` uses for a
     * vendor who has stopped being dispatchable.
     *
     * @param  int|array<int, int|string>|null  $keep  value(s) to offer even if retired
     * @return array<int, string>
     */
    public static function options(int|array|null $keep = null, bool $activeOnly = true): array
    {
        $options = static::query()
            ->when($activeOnly, fn (Builder $q) => $q->active())
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (self $t): array => [$t->id => $t->label()]);

        $keepIds = array_filter(array_map('intval', is_array($keep) ? $keep : [$keep]));

        foreach (array_diff($keepIds, $options->keys()->all()) as $id) {
            if ($retired = static::find($id)) {
                $options->put($retired->id, $retired->label().' ⚠');
            }
        }

        return $options->all();
    }
}
