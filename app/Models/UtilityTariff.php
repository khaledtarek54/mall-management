<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A published price for a utility — "EGPC commercial electricity" — and the ladder of what it has
 * cost over time.
 *
 * The identity a meter points at. Its price lives on {@see UtilityTariffRate} as dated rungs, for
 * the reasons set out in the migration: a tariff moves by decree, announced before it takes effect,
 * and a single number per meter has nowhere to put the rise until the morning it starts.
 *
 * **Portfolio-shared, like {@see TaxCode}.** A public electricity tariff is not a property of one
 * mall. A property that genuinely has its own price gets its own tariff row and points its meters
 * at it — which states the difference explicitly, rather than hiding it in a nullable `asset_id`
 * that every read would then have to remember to resolve.
 *
 * **Editing a rung that is already in force is safe**, the same as on a tax code:
 * `meter_readings.cost` is computed and stored when the reading is entered, so nothing already
 * priced is re-priced. An edit changes what is billed NEXT.
 *
 * **Not search-indexed**, for the reason {@see TaxCode} is not: this is a short catalogue reached
 * from its own screen and maintained by scrolling. Nobody types "EGPC-COMM" into the top bar to
 * find a record; they open the screen to change a price. Registered as such in
 * `App\Support\SearchPolicy`.
 */
#[DeletableWhenUnused(blockedBy: ['meters'], instead: 'deactivate the tariff — it leaves the meter picker immediately and still explains what past readings were priced at')]
// Master data an operator maintains once for the whole portfolio; see the class docblock.
#[PortfolioShared]
class UtilityTariff extends Model
{
    use HasFactory, LogsActivity, RefusesDeletionWhenReferenced, SoftDeletes;

    protected $fillable = [
        'code',
        'name_en',
        'name_ar',
        'utility_type',
        'unit_of_measurement',
        'provider',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'utility_tariff');
    }

    /** @return HasMany<UtilityTariffRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(UtilityTariffRate::class)->orderByDesc('effective_from');
    }

    /**
     * The meters priced by this tariff — and the relation `DeletableWhenUnused` checks.
     *
     * @return HasMany<UtilityMeter, $this>
     */
    public function meters(): HasMany
    {
        return $this->hasMany(UtilityMeter::class);
    }

    /**
     * The price in force on a date — the latest rung starting on or before it.
     *
     * Null when the ladder is empty or the date precedes the first rung. **Null is not zero:** a
     * caller that cannot find a price has to decide what that means, and for a meter it means
     * "priced at 0, therefore not billable", which `BillMeterReadingService` already refuses.
     * Returning 0.0 here would make "nobody has entered a price" indistinguishable from "this supply
     * is free" — and the second one silently bills a tenant nothing.
     */
    public function rateOn(CarbonInterface|string|null $on = null): ?float
    {
        $date = $on !== null ? CarbonImmutable::parse($on) : CarbonImmutable::now();

        // `rates` is ordered effective_from DESC, so the first rung at or before the date is the one
        // in force. Reads the LOADED collection when there is one: a readings table resolving a rate
        // per row would otherwise issue a query per row, which is the N+1 this ordering exists to
        // let callers avoid via `with('utilityTariff.rates')`.
        $rung = $this->relationLoaded('rates')
            ? $this->rates->first(fn (UtilityTariffRate $r) => $r->effective_from->lessThanOrEqualTo($date))
            : $this->rates()->whereDate('effective_from', '<=', $date->toDateString())->first();

        return $rung === null ? null : (float) $rung->rate_per_unit;
    }

    /** The name in the reader's language — what `OptionDisplay` and the activity log show. */
    public function label(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }
}
