<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[DeletionAllowed(reason: 'operational: soft-delete IS the retirement path, and the energy trend already excludes retired meters')]
#[PropertyOwned]
class UtilityMeter extends Model
{
    use HasFactory, HasSearchText, SoftDeletes;

    public const TYPES = ['electric', 'water', 'gas'];

    public const STATUSES = ['active', 'inactive', 'faulty'];

    protected $fillable = [
        'asset_id',
        'unit_id',
        'meter_number',
        'type',
        'provider',
        'status',
        'unit_of_measurement',
        'utility_tariff_id',
        'rate_per_unit',
    ];

    /**
     * The number stamped on the meter, and the utility provider.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->meter_number,
            $this->provider,
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class)->orderBy('reading_date');
    }

    public function latestReading(): ?MeterReading
    {
        return $this->readings()->orderByDesc('reading_date')->first();
    }

    public function isCommonArea(): bool
    {
        return $this->unit_id === null;
    }

    /** @return BelongsTo<UtilityTariff, $this> */
    public function utilityTariff(): BelongsTo
    {
        return $this->belongsTo(UtilityTariff::class);
    }

    /**
     * **The one place that answers what this meter charges per unit on a date.**
     *
     * Precedence, and each step is a real state:
     *
     *   1. `rate_per_unit` — the per-meter OVERRIDE. Set means somebody chose this price for this
     *      meter (a rate negotiated with one tenant, a sub-meter billed at a blended figure), and a
     *      chosen price beats a published one.
     *   2. the tariff's rung in force on `$on` — the published price, resolved for the date the
     *      consumption is being priced at rather than for today.
     *   3. `0.0` — monitored but not recharged, which is a landlord / common-area meter's normal
     *      state. `BillMeterReadingService` refuses a zero-cost recharge, so this stays the safe
     *      direction: a reading nobody priced cannot quietly bill a tenant nothing.
     *
     * This mirrors {@see Charge::resolvedVatRate()} against the tax catalogue exactly — same
     * override-wins shape, same "null is the normal state", same reason. Every origination point
     * calls THIS rather than reading `rate_per_unit`; a call site that reads the column directly
     * cannot see the tariff and prices a tariffed meter at 0.
     *
     * Pass the READING's date, never `now()`: a reading keyed a week late must be priced at what the
     * supply cost when it was consumed, which is the whole point of the ladder.
     */
    public function resolvedRatePerUnit(CarbonInterface|string|null $on = null): float
    {
        if ($this->rate_per_unit !== null) {
            return (float) $this->rate_per_unit;
        }

        return $this->utilityTariff?->rateOn($on) ?? 0.0;
    }

    /** Does this meter depart from its tariff — i.e. did somebody choose its price? */
    public function hasRateOverride(): bool
    {
        return $this->rate_per_unit !== null;
    }

    /**
     * What a reading of this consumption costs on a date. The rule the form, the importer and any
     * future service all share, so an imported reading and a typed one price identically.
     */
    public function costFor(float $consumption, CarbonInterface|string|null $on = null): float
    {
        return round($consumption * $this->resolvedRatePerUnit($on), 2);
    }
}
