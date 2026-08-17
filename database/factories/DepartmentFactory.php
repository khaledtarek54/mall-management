<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * The five core operator (Eltizam) departments. Using these as the default
     * pool keeps a factory-made department's slug aligned with its access-role
     * name (leasing / operations / accounting / marketing / hr) — see
     * Department::roleName(). The model's `creating` hook derives + dedupes the
     * slug, so we never set it here.
     *
     * @var list<array{string, string}>
     */
    protected array $core = [
        ['Human Resources', 'HR'],
        ['Marketing', 'MKT'],
        ['Accounting', 'ACC'],
        ['Leasing', 'LEAS'],
        ['Operations', 'OPS'],
    ];

    /**
     * Define the model's default state.
     *
     * Produces a global (operator-wide) department: asset_id + head_user_id are
     * nullable FKs and are left null by default. Use ->forAsset() / ->headedBy()
     * to scope or lead the department.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Pick a realistic core department label, then append a unique counter so
        // the factory never exhausts (slug, derived from name, must stay unique;
        // the model's `creating` hook dedupes it but we keep names distinct too).
        [$label] = fake()->randomElement($this->core);
        $name = $label.' '.fake()->unique()->numberBetween(1, 1_000_000);

        return [
            'name' => $name,
            // slug is generated + deduped by Department::booted() — omit it.
            'code' => strtoupper(Str::substr(fake()->unique()->bothify('???-####'), 0, 20)),
            'description' => fake()->optional()->sentence(),
            'asset_id' => null,
            'head_user_id' => null,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 50),
            'metadata' => null,
        ];
    }

    /**
     * Scope the department to a single property. Asset has no factory in this
     * codebase, so we mirror the tests/Pest.php makeAsset() shape inline.
     */
    public function forAsset(?Asset $asset = null): static
    {
        return $this->state(fn (array $attributes) => [
            'asset_id' => ($asset ?? Asset::create([
                'name' => 'Asset '.uniqid(),
                'code' => strtoupper(Str::substr(uniqid(), -6)),
                'type' => 'mall',
                'city' => 'Cairo',
                'country' => 'EG',
                'total_area_sqm' => 1000,
                'leasable_area_sqm' => 800,
                'currency' => 'EGP',
                'is_active' => true,
            ]))->id,
        ]);
    }

    /** Give the department a head (operator staff user). */
    public function headedBy(?User $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'head_user_id' => ($user ?? User::factory()->create())->id,
        ]);
    }

    /** An inactive (archived) department. */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
