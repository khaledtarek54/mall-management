<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<TenantUser>
 */
class TenantUserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Tenant has no factory yet — create the company record inline using
            // the same valid shape as the makeTenant() Pest helper.
            'tenant_id' => fn () => Tenant::create([
                'name' => 'Tenant '.fake()->unique()->numerify('######'),
                'email' => fake()->unique()->safeEmail(),
                'type' => 'company',
                'status' => 'active',
            ])->id,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'is_admin' => false,
        ];
    }

    /**
     * An admin portal login (may submit/write in the portal).
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
        ]);
    }

    /**
     * Attach this user to an existing tenant company.
     */
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }
}
