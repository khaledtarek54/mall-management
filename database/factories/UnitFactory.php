<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\Unit>
 */
class UnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Asset has no factory; mirror the makeAsset() helper from tests/Pest.php.
            'asset_id' => fn () => Asset::create([
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
            // Unique per (asset_id, code); keep it globally unique to be safe.
            'code' => strtoupper(fake()->bothify('?-##')) . '-' . Str::upper(Str::random(4)),
            // Floors are a per-property register now, not a free-text column. Left null: a
            // factory that invented a floor would have to invent the property's register with it,
            // and every test that cares assigns one explicitly.
            'floor_id' => null,
            'category' => fake()->randomElement([
                'retail',
                'food_beverage',
                'wellness',
                'service',
                'kiosk',
                'office',
                'storage',
            ]),
            'area_sqm' => fake()->randomFloat(2, 20, 500),
            'status' => 'vacant',
            'description' => fake()->optional()->sentence(),
            'features' => fake()->randomElements(
                ['corner_unit', 'glass_facade', 'outdoor_seating', 'mezzanine', 'street_access'],
                fake()->numberBetween(0, 3),
            ),
        ];
    }

    /**
     * Attach the unit to an existing asset instead of creating a new one.
     */
    public function forAsset(Asset $asset): static
    {
        return $this->state(fn (array $attributes) => [
            'asset_id' => $asset->id,
        ]);
    }

    /**
     * Mark the unit as occupied (status is otherwise vacant by default).
     */
    public function occupied(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'occupied',
        ]);
    }
}
