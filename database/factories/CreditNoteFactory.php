<?php

namespace Database\Factories;

use App\Models\CreditNote;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\CreditNote>
 */
class CreditNoteFactory extends Factory
{
    protected $model = CreditNote::class;

    /**
     * Define the model's default state.
     *
     * Produces a fully valid, persistable credit note in the 'issued' status.
     *
     * NOT-NULL columns with no DB default that the factory MUST set:
     *   tenant_id, issue_date, reason, subtotal, total.
     * Columns with DB defaults we set explicitly to keep the record's money
     * shape deterministic and invariant-safe:
     *   status, vat_amount, applied_amount, balance, currency.
     *
     * Money invariants mirrored from the codebase (see InvoiceFactory /
     * makeInvoice in tests/Pest.php — VAT 14% on the charge base):
     *   total   = subtotal + vat_amount
     *   balance = total - applied_amount   (and balance <= total)
     *
     * `number` is normally auto-generated in CreditNote::booted(), but it is a
     * UNIQUE NOT-NULL column with no DB default, so we assign a collision-proof
     * sequenced value here so the factory is safe in bulk (count()) creates.
     *
     * Required FK: tenant_id (restrictOnDelete) -> Tenant has a factory.
     * invoice_id / lease_id / issued_by_user_id are all nullable and left null
     * by default; opt in via ->forInvoice() / ->forLease() when needed.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 5000);
        $vatAmount = round($subtotal * 0.14, 2);
        $total = round($subtotal + $vatAmount, 2);

        return [
            'number' => 'CN-AW-' . now()->format('Ym') . '-' . fake()->unique()->numerify('####'),
            'tenant_id' => Tenant::factory(),
            'invoice_id' => null,
            'lease_id' => null,
            'status' => 'issued',
            'issue_date' => fake()->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'reason' => fake()->randomElement(['return', 'dispute', 'adjustment', 'discount', 'refund', 'other']),
            'reason_notes' => fake()->optional()->sentence(),
            'subtotal' => $subtotal,
            'vat_amount' => $vatAmount,
            'total' => $total,
            'applied_amount' => 0,
            'balance' => $total,
            'currency' => 'EGP',
            'issued_by_user_id' => null,
            'applied_at' => null,
            'voided_at' => null,
            'notes' => null,
        ];
    }

    /**
     * A still-editable draft credit note (no balance available to apply yet).
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    /**
     * A fully-applied credit note: applied_amount == total, balance == 0.
     */
    public function applied(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'applied',
            'applied_amount' => $attributes['total'],
            'balance' => 0,
            'applied_at' => now(),
        ]);
    }

    /**
     * A voided credit note (no usable balance).
     */
    public function void(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'void',
            'applied_amount' => 0,
            'balance' => 0,
            'voided_at' => now(),
        ]);
    }

    /**
     * Attach the credit note to an existing invoice (and inherit its tenant).
     */
    public function forInvoice(\App\Models\Invoice $invoice): static
    {
        return $this->state(fn (array $attributes) => [
            'invoice_id' => $invoice->id,
            'lease_id' => $invoice->lease_id,
            'tenant_id' => $invoice->tenant_id,
        ]);
    }
}
