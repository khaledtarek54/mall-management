<?php

namespace Database\Factories;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            // `slug` is auto-derived from `name` in Vendor::booted(); leave it
            // unset so the model's collision-safe generator owns it.
            'type' => fake()->randomElement([
                'contractor',
                'supplier',
                'service_provider',
                'consultant',
                'other',
            ]),
            'status' => 'active',
            'legal_name' => $name.' LLC',
            'tax_id' => fake()->unique()->numerify('###-###-###'),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->numerify('+20 1## ### ####'),
            'address' => fake()->streetAddress(),
            'city' => fake()->randomElement(['Cairo', 'Giza', 'Alexandria', 'New Cairo']),
            'notes' => null,
            'metadata' => null,
        ];
    }

    /**
     * A contractor (the type most likely to be assigned maintenance work).
     */
    public function contractor(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'contractor',
        ]);
    }

    /**
     * An inactive vendor — kept on file but not selectable for new work.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * A blacklisted vendor — must not receive new assignments.
     */
    public function blacklisted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'blacklisted',
        ]);
    }
}
