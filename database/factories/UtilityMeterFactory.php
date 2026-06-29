<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Unit;
use App\Models\UtilityMeter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\UtilityMeter>
 */
class UtilityMeterFactory extends Factory
{
    /**
     * The unit of measurement that matches each meter type.
     *
     * @var array<string, string>
     */
    protected const UNITS = [
        'electric' => 'kWh',
        'water' => 'm3',
        'gas' => 'm3',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(UtilityMeter::TYPES);

        return [
            'asset_id' => Asset::factory(),
            // Common-area meter by default (unit_id is nullable); use ->forUnit() to attach to a unit.
            'unit_id' => null,
            'meter_number' => fake()->unique()->numerify('MTR-########'),
            'type' => $type,
            'provider' => fake()->randomElement(['EEHC', 'Cairo Water', 'TownGas', null]),
            'status' => 'active',
            'unit_of_measurement' => self::UNITS[$type],
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
