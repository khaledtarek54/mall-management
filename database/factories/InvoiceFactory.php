<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Produces a fully valid, persistable invoice (subtotal + 14% VAT = total,
     * unpaid by default so balance == total). The required lease_id / tenant_id
     * FKs are satisfied by building a self-contained Asset → Unit → Lease graph
     * inline — the related models declare HasFactory but ship no factory classes,
     * and the tests/Pest.php make* helpers are not autoloaded outside the suite.
     *
     * `number` and `payment_link_token` are intentionally omitted: the model's
     * `creating` hook always (re)generates a collision-free number from the
     * lease's asset code and mints the pay-link token.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Whole-pound base so VAT and totals land on clean 2-dp values.
        $subtotal = (float) fake()->numberBetween(1_000, 100_000);
        $vat = round($subtotal * 0.14, 2);
        $total = round($subtotal + $vat, 2);

        $issueDate = fake()->dateTimeBetween('-3 months', 'now');
        $dueDate = (clone $issueDate)->modify('+7 days');
        $periodStart = (new \DateTimeImmutable($issueDate->format('Y-m-01')));
        $periodEnd = $periodStart->modify('last day of this month');

        return [
            'lease_id' => fn () => $this->makeLease()->id,
            // Keep the invoice's tenant in lock-step with its lease's tenant.
            'tenant_id' => fn (array $attrs) => Lease::find($attrs['lease_id'])->tenant_id,
            'status' => 'issued',
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'subtotal' => $subtotal,
            'vat_amount' => $vat,
            'total' => $total,
            'paid_amount' => 0,
            'credit_applied_amount' => 0,
            'balance' => $total,
            'currency' => 'EGP',
            'notes' => null,
        ];
    }

    /**
     * A draft invoice (not yet issued to the tenant).
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    /**
     * A fully-settled invoice — paid in full, zero balance.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
            'paid_amount' => $attributes['total'],
            'balance' => 0,
        ]);
    }

    /**
     * An overdue invoice: issued, unpaid, and past its due date.
     */
    public function overdue(): static
    {
        return $this->state(function (array $attributes) {
            $issueDate = fake()->dateTimeBetween('-6 months', '-2 months');

            return [
                'status' => 'overdue',
                'issue_date' => $issueDate,
                'due_date' => (clone $issueDate)->modify('+7 days'),
                'paid_amount' => 0,
                'balance' => $attributes['total'],
            ];
        });
    }

    /**
     * Build a self-contained, persistable Lease (with its Asset, Unit and
     * Tenant). Mirrors the data shapes used by the tests/Pest.php make*
     * helpers so factory-built leases match hand-rolled test fixtures.
     */
    protected function makeLease(): Lease
    {
        $asset = Asset::create([
            'name' => 'Asset '.uniqid(),
            'code' => strtoupper(substr(uniqid(), -6)),
            'type' => 'mall',
            'city' => 'Cairo',
            'country' => 'EG',
            'total_area_sqm' => 1000,
            'leasable_area_sqm' => 800,
            'currency' => 'EGP',
            'is_active' => true,
        ]);

        $unit = Unit::create([
            'asset_id' => $asset->id,
            'code' => 'U-'.uniqid(),
            'area_sqm' => 100,
            'status' => 'vacant',
            'category' => 'retail',
        ]);

        $tenant = Tenant::create([
            'name' => 'Tenant '.uniqid(),
            'email' => uniqid().'@t.test',
            'type' => 'company',
            'status' => 'active',
        ]);

        return Lease::create([
            'reference' => 'L-'.Str::random(8),
            'unit_id' => $unit->id,
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'commencement_date' => '2026-01-01',
            'expiry_date' => '2027-12-31',
            'term_months' => 24,
            'base_rent_monthly' => 10000,
            'service_charge_monthly' => 2000,
            'currency' => 'EGP',
            'payment_terms_days' => 7,
        ]);
    }
}
