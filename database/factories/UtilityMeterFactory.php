<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Unit;
use App\Models\UtilityMeter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UtilityMeter>
 */
class UtilityMeterFactory extends Factory
{
    /**
     * The unit of measurement that matches each meter type.
     *
     * **Must cover every type in `ValueSets::allowed('utility_meters','type')`.** This map is the
     * same hand-written second source of truth that `UtilityMeter::types()` used to be: when `hours`
     * (a running-hours meter) was added to the registry on 2026-08-17, `types()` was corrected to
     * derive — and this was left behind saying three when the column accepted four. The factory then
     * picked `hours` one time in four and died on an undefined key, so `FactoriesSmokeTest` failed
     * ~25% of runs: the guard that exists to keep factories honest, made unreliable by exactly the
     * drift it is meant to catch.
     *
     * The `?? ` fallback below is the backstop, not the fix — a NEW type still belongs in this map,
     * and the fallback only guarantees it cannot crash the suite while nobody has noticed.
     *
     * @var array<string, string>
     */
    protected const UNITS = [
        'electric' => 'kWh',
        'water' => 'm3',
        'gas' => 'm3',
        'hours' => 'h',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(UtilityMeter::types());

        return [
            'asset_id' => Asset::factory(),
            // Common-area meter by default (unit_id is nullable); use ->forUnit() to attach to a unit.
            'unit_id' => null,
            'meter_number' => fake()->unique()->numerify('MTR-########'),
            'type' => $type,
            'provider' => fake()->randomElement(['EEHC', 'Cairo Water', 'TownGas', null]),
            'status' => 'active',
            'unit_of_measurement' => self::UNITS[$type] ?? 'unit',
        ];
    }

    /**
     * Attach the meter to a specific unit (and its asset).
     */
    public function forUnit(?Unit $unit = null): static
    {
        return $this->state(function (array $attributes) use ($unit) {
            if ($unit) {
                return [
                    'asset_id' => $unit->asset_id,
                    'unit_id' => $unit->id,
                ];
            }

            return ['unit_id' => Unit::factory()];
        });
    }

    /**
     * Indicate that the meter is faulty.
     */
    public function faulty(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'faulty',
        ]);
    }
}
