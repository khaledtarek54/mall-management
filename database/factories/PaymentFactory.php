<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * Define the model's default state.
     *
     * Produces a fully valid, persistable payment: a `captured` cash payment in
     * EGP, allocated to no invoice (allocation is a separate, explicit step — see
     * the `capturePayment()` helper in tests/Pest scenarios). The only required
     * foreign key is `tenant_id`, satisfied via TenantFactory; `received_by` is
     * nullable and left null by default.
     *
     * `reference` is intentionally omitted: the model's `creating` hook always
     * (re)generates a collision-free "PAY-YYYYMM-NNNN" reference at save time,
     * so any value set here would be overwritten (mirrors how InvoiceFactory
     * lets the model mint `number`). `currency`/`status` carry DB defaults but
     * are set explicitly so the record's shape is deterministic.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            // Whole-pound amount so allocation maths land on clean 2-dp values.
            'amount' => (float) fake()->numberBetween(500, 100_000),
            'currency' => 'EGP',
            'method' => fake()->randomElement([
                'card',
                'bank_transfer',
                'instapay',
                'wallet',
                'cash',
                'cheque',
                'other',
            ]),
            'status' => 'captured',
            'payment_date' => fake()->dateTimeBetween('-3 months', 'now'),
            'gateway' => null,
            'channel' => null,
            'gateway_transaction_id' => null,
            'gateway_response' => null,
            'cheque_number' => null,
            'cheque_clearance_date' => null,
            'notes' => null,
            'received_by' => null,
            'receipt_notified_at' => null,
        ];
    }

    /**
     * A payment created but not yet captured (e.g. an online flow awaiting the
     * gateway callback). A non-captured payment moves no money on its invoices.
     */
    public function initiated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'initiated',
        ]);
    }

    /**
     * A failed payment — also moves nothing on allocated invoices.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
        ]);
    }

    /**
     * A card payment captured through the Paymob gateway via the public pay
     * link, with a gateway transaction id and raw response payload.
     */
    public function paymob(): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => 'card',
            'status' => 'captured',
            'gateway' => 'Paymob',
            'channel' => Payment::CHANNEL_LINK,
            'gateway_transaction_id' => (string) fake()->unique()->numerify('##########'),
            'gateway_response' => ['success' => true, 'pending' => false],
        ]);
    }

    /**
     * A cheque payment, with cheque number and a forward-dated clearance date.
     */
    public function cheque(): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => 'cheque',
            'cheque_number' => (string) fake()->unique()->numerify('#######'),
            'cheque_clearance_date' => fake()->dateTimeBetween('now', '+1 month'),
        ]);
    }

    /**
     * A payment recorded by a staff user (sets the `received_by` FK).
     */
    public function receivedBy(?User $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'received_by' => $user?->id ?? User::factory(),
        ]);
    }
}
