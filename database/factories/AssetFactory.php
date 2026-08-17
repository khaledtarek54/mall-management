<?php

namespace Database\Factories;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    /**
     * Define the model's default state.
     *
     * Mirrors the `makeAsset()` helper in tests/Pest.php: a fully valid,
     * persistable property row with realistic Egyptian-mall data.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $totalArea = fake()->randomFloat(2, 2_000, 50_000);

        return [
            'name' => fake()->unique()->company().' Mall',
            // NOT NULL + unique. Short uppercase property code, e.g. "HW".
            'code' => strtoupper(fake()->unique()->bothify('??##')),
            // DB enum — must be one of the allowed values.
            'type' => fake()->randomElement(['mall', 'retail_walk', 'mixed_use', 'office', 'residential']),
            'address' => fake()->streetAddress(),
            'city' => fake()->randomElement(['Cairo', 'Giza', 'Alexandria', 'New Cairo', '6th of October']),
            'country' => 'Egypt',
            'total_area_sqm' => $totalArea,
            // Leasable area is always a subset of the total footprint.
            'leasable_area_sqm' => round($totalArea * fake()->randomFloat(2, 0.6, 0.85), 2),
            'currency' => 'EGP',
            'primary_color' => fake()->optional()->hexColor(),
            'metadata' => null,
            'is_active' => true,
        ];
    }

    /**
     * An inactive property.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * The synthetic "All Properties" portfolio pseudo-asset.
     *
     * Note: the production migration already persists this row (code "ALL"),
     * so use this as a `->make()` blueprint or override `code`; calling
     * `->create()` will collide with the seeded row's unique code.
     */
    public function allProperties(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'All Properties',
            'code' => Asset::ALL_PROPERTIES_CODE,
            'type' => 'mall',
            'city' => '—',
            'country' => '—',
            'total_area_sqm' => null,
            'leasable_area_sqm' => null,
            'is_active' => false,
        ]);
    }
}
