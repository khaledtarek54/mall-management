<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\MeterReading;
use App\Models\UtilityMeter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\MeterReading>
 */
class MeterReadingFactory extends Factory
{
    protected $model = MeterReading::class;

    /**
     * Define the model's default state.
     *
     * Produces a fully valid, persistable reading. The composite unique
     * key is (utility_meter_id, reading_date); each call mints a fresh
     * meter by default, so a fixed-period date is safe. When several
     * readings share one meter, pass distinct dates (see ->forMeter()).
     *
     * `reading_value` is the cumulative meter dial; `consumption` is the
     * delta against the prior reading (>= 0). We keep them internally
     * consistent and bill cost off consumption at a realistic EGP rate.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $consumption = fake()->randomFloat(2, 50, 5_000);
        // Cumulative dial reading sits at or above the period's consumption.
        $readingValue = fake()->randomFloat(2, $consumption, $consumption + 100_000);
        // EGP rate per kWh/m3; cost derived from consumption (DDL default 0).
        $rate = fake()->randomFloat(2, 0.8, 3.5);

        return [
            // UtilityMeter has no factory; mirror the test setup (asset + meter).
            'utility_meter_id' => fn () => UtilityMeter::create([
                'asset_id' => Asset::create([
                    'name' => 'Asset ' . uniqid(),
                    'code' => strtoupper(substr(uniqid(), -6)),
                    'type' => 'mall',
                    'city' => 'Cairo',
                    'country' => 'EG',
                    'total_area_sqm' => 1000,
                    'leasable_area_sqm' => 800,
                    'currency' => 'EGP',
                    'is_active' => true,
                ])->id,
                'unit_id' => null,
                'meter_number' => 'MTR-' . strtoupper(substr(uniqid(), -8)),
                'type' => fake()->randomElement(UtilityMeter::TYPES),
                'status' => 'active',
                'unit_of_measurement' => 'kWh',
            ])->id,
            'reading_date' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'reading_value' => $readingValue,
            'consumption' => $consumption,
            'cost' => round($consumption * $rate, 2),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Attach the reading to an existing meter instead of minting a new one.
     *
     * Pass a date when seeding multiple readings on the same meter to honour
     * the (utility_meter_id, reading_date) unique constraint.
     */
    public function forMeter(UtilityMeter $meter, ?string $date = null): static
    {
        return $this->state(fn (array $attributes) => array_filter([
            'utility_meter_id' => $meter->id,
            'reading_date' => $date,
        ], fn ($value) => $value !== null));
    }

    /**
     * The first reading on a meter: no prior period, so consumption is 0.
     */
    public function initial(): static
    {
        return $this->state(fn (array $attributes) => [
            'consumption' => 0,
            'cost' => 0,
        ]);
    }
}
