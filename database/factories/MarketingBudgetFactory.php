<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\MarketingBudget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\MarketingBudget>
 */
class MarketingBudgetFactory extends Factory
{
    protected $model = MarketingBudget::class;

    /**
     * Define the model's default state.
     *
     * Produces a fully valid per-property, per-year marketing fund row
     * (FR MKT-3/5). accrued_amount is the 5% marketing levy income; spend is a
     * subset of that, so spent_amount <= accrued_amount keeps balance() >= 0.
     *
     * The (asset_id, period_year) pair is UNIQUE, so callers persisting many
     * rows for one property should vary period_year (or use the ->forAsset()
     * helper + a unique year).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $accrued = fake()->randomFloat(2, 50_000, 1_000_000);

        return [
            // Required FK — Asset has its own factory.
            'asset_id' => Asset::factory(),
            // NOT NULL, unique together with asset_id. Recent fiscal years.
            'period_year' => fake()->numberBetween(2023, 2027),
            // Income side: accrued 5% marketing levy.
            'accrued_amount' => $accrued,
            // Spend is always a subset of accrued so balance() never goes negative.
            'spent_amount' => round($accrued * fake()->randomFloat(2, 0, 0.9), 2),
            // DB enum — must be one of the allowed values.
            'status' => 'open',
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * A closed (finalized) budget period.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
        ]);
    }

    /**
     * A fully-spent budget: spend equals accrued, leaving a zero balance().
     */
    public function fullySpent(): static
    {
        return $this->state(fn (array $attributes) => [
            'spent_amount' => $attributes['accrued_amount'],
        ]);
    }
}
