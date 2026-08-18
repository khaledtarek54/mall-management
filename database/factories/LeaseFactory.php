<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lease>
 */
class LeaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Produces a fully valid, persistable lease. Unit and Tenant have no
     * factories of their own, so their required NOT-NULL columns are created
     * inline here, mirroring the makeUnit/makeTenant/makeAsset helpers in
     * tests/Pest.php.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $commencement = fake()->dateTimeBetween('-1 year', 'now');
        $termMonths = fake()->randomElement([12, 24, 36, 60]);
        $expiry = (clone $commencement)->modify("+{$termMonths} months");

        $baseRent = fake()->numberBetween(5_000, 100_000);
        $serviceCharge = (int) round($baseRent * fake()->randomFloat(2, 0.1, 0.25));

        return [
            'reference' => 'LSE-'.strtoupper(fake()->unique()->bothify('??-####-####')),
            // Required FKs: no Unit/Tenant factory exists, so build the parents
            // inline with their NOT-NULL columns (see tests/Pest.php helpers).
            'unit_id' => fn () => Unit::create([
                'asset_id' => Asset::create([
                    'name' => 'Asset '.uniqid(),
                    'code' => strtoupper(substr(uniqid(), -6)),
                    'type' => 'mall',
                    'city' => 'Cairo',
                    'country' => 'EG',
                    'total_area_sqm' => 1000,
                    'leasable_area_sqm' => 800,
                    'currency' => 'EGP',
                    'is_active' => true,
                ])->id,
                'code' => 'U-'.uniqid(),
                'area_sqm' => 100,
                'status' => 'vacant',
                'category' => 'retail',
            ])->id,
            'tenant_id' => fn () => Tenant::create([
                'name' => 'Tenant '.uniqid(),
                'email' => uniqid().'@t.test',
                'type' => 'company',
                'status' => 'active',
            ])->id,
            'previous_lease_id' => null,
            'status' => 'active',
            'commencement_date' => $commencement,
            'expiry_date' => $expiry,
            'term_months' => $termMonths,
            'base_rent_monthly' => $baseRent,
            'service_charge_monthly' => $serviceCharge,
            'currency' => 'EGP',
            'security_deposit' => $baseRent * 2,
            'escalation_rate' => 0,
            'escalation_type' => 'none',
            'next_escalation_date' => null,
            'has_percentage_rent' => false,
            'percentage_rent_threshold' => null,
            'percentage_rent_rate' => null,
            'percentage_rent_calculation_type' => null,
            'billing_day' => null,
            'payment_terms_days' => 7,
            'notes' => null,
            'metadata' => null,
        ];
    }

    /**
     * A draft lease (pre-activation).
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    /**
     * A lease carrying percentage (turnover) rent, with a valid
     * threshold/rate/calculation set.
     */
    public function withPercentageRent(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_percentage_rent' => true,
            'percentage_rent_threshold' => ($attributes['base_rent_monthly'] ?? 10_000) * 12,
            'percentage_rent_rate' => fake()->randomFloat(2, 5, 12),
            'percentage_rent_calculation_type' => 'natural_breakpoint',
        ]);
    }
}
