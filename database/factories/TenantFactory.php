<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * Mirrors the makeTenant() helper in tests/Pest.php: a company tenant in
     * the 'active' status. `name`, `type` and `status` are the only NOT-NULL
     * columns (type/status carry DB defaults but we set them explicitly so the
     * record's shape is deterministic). Everything else on the tenants table is
     * nullable; we still populate the common registration / contact fields with
     * realistic Egyptian-flavoured fake data. Tenant is a root entity — it has
     * no required foreign keys.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $companyName = fake()->company();

        return [
            'name' => $companyName,
            'legal_name' => $companyName . ' LLC',
            'type' => fake()->randomElement(['individual', 'company']),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
            'phone' => fake()->numerify('+2010########'),
            'whatsapp' => fake()->numerify('+2010########'),
            'tax_id' => fake()->unique()->numerify('###-###-###'),
            'national_id' => fake()->unique()->numerify('##############'),
            'commercial_register' => (string) fake()->unique()->numberBetween(10000, 999999),
            'address' => fake()->address(),
            'contact_person' => fake()->name(),
            'contact_person_phone' => fake()->numerify('+2010########'),
            'status' => 'active',
            'metadata' => null,
        ];
    }

    /**
     * An individual (sole-trader) tenant rather than a company.
     */
    public function individual(): static
    {
        return $this->state(function (array $attributes) {
            $name = fake()->name();

            return [
                'type' => 'individual',
                'name' => $name,
                'legal_name' => $name,
            ];
        });
    }

    /**
     * A blacklisted tenant. Such a tenant cannot access the portal panel
     * (Tenant::canAccessPanel() requires status === 'active').
     */
    public function blacklisted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'blacklisted',
        ]);
    }

    /**
     * An inactive tenant (also blocked from the portal panel).
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
