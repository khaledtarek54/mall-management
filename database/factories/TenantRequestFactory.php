<?php

namespace Database\Factories;

use App\Enums\TenantRequestType;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\TenantRequest>
 */
class TenantRequestFactory extends Factory
{
    protected $model = TenantRequest::class;

    /**
     * Define the model's default state.
     *
     * Mirrors the makeTenantRequest() helper in tests/Pest.php: an open,
     * just-submitted maintenance request. The NOT-NULL columns with no DB default
     * — reference (unique), tenant_id, unit_id, title, description, submitted_at —
     * are all set; status/priority/request_type/channel carry DB defaults but we
     * set them explicitly so the record's shape is deterministic.
     *
     * tenant_id and unit_id are the only required foreign keys (both
     * restrictOnDelete); the rest (lease_id, assigned_to, assigned_to_vendor_id,
     * department_id) are nullable and left unassigned, matching a freshly-intaken
     * request awaiting triage. `category` is a free-form sub-category that must be
     * a valid member of the chosen request type's subcategories() (or null).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(TenantRequestType::cases());
        $priority = fake()->randomElement(TenantRequest::PRIORITIES);
        $subcategories = $type->subcategories();
        $submittedAt = fake()->dateTimeBetween('-30 days', 'now');

        return [
            // Unique, human-style reference (e.g. MR-AW-2026-0001). Sequenced so
            // parallel inserts never collide on the unique index.
            'reference' => sprintf(
                '%s-AW-%s-%04d',
                $type->referencePrefix(),
                now()->format('Y'),
                fake()->unique()->numberBetween(1, 999999),
            ),
            'tenant_id' => Tenant::factory(),
            'unit_id' => Unit::factory(),
            'lease_id' => null,
            'request_type' => $type,
            'assigned_to' => null,
            'assigned_to_vendor_id' => null,
            'department_id' => null,
            'status' => 'submitted',
            'priority' => $priority,
            'category' => $subcategories === [] ? null : fake()->randomElement($subcategories),
            'channel' => fake()->randomElement(['portal', 'whatsapp', 'phone', 'email', 'walk_in', 'admin']),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'resolution_notes' => null,
            'submitted_at' => $submittedAt,
            'acknowledged_at' => null,
            'resolved_at' => null,
            'closed_at' => null,
            'target_resolution_at' => $type->hasSla()
                ? (clone $submittedAt)->modify('+' . ($type->slaHours()[$priority] ?? 72) . ' hours')
                : null,
            'scheduled_from' => null,
            'scheduled_to' => null,
            'sla_breach_notified_at' => null,
            'csat_rating' => null,
            'csat_comment' => null,
        ];
    }

    /**
     * Pin the request to a specific request type (and a valid sub-category +
     * SLA-derived target for that type).
     */
    public function ofType(TenantRequestType $type): static
    {
        return $this->state(function (array $attributes) use ($type) {
            $priority = $attributes['priority'] ?? 'medium';
            $subcategories = $type->subcategories();
            $submittedAt = $attributes['submitted_at'] ?? now();

            return [
                'request_type' => $type,
                'reference' => sprintf(
                    '%s-AW-%s-%04d',
                    $type->referencePrefix(),
                    now()->format('Y'),
                    fake()->unique()->numberBetween(1, 999999),
                ),
                'category' => $subcategories === [] ? null : fake()->randomElement($subcategories),
                'target_resolution_at' => $type->hasSla()
                    ? (clone $submittedAt)->modify('+' . ($type->slaHours()[$priority] ?? 72) . ' hours')
                    : null,
            ];
        });
    }

    /**
     * A resolved request, with the full close-out trail (acknowledged → resolved
     * → closed) and a satisfaction rating.
     */
    public function resolved(): static
    {
        return $this->state(function (array $attributes) {
            $submittedAt = $attributes['submitted_at'] ?? now();

            return [
                'status' => 'resolved',
                'acknowledged_at' => (clone $submittedAt)->modify('+1 hour'),
                'resolved_at' => (clone $submittedAt)->modify('+1 day'),
                'resolution_notes' => fake()->sentence(),
                'csat_rating' => fake()->numberBetween(1, 5),
                'csat_comment' => fake()->optional()->sentence(),
            ];
        });
    }

    /**
     * A terminal (closed) request — immutable per FR REQ-3.
     */
    public function closed(): static
    {
        return $this->resolved()->state(function (array $attributes) {
            $submittedAt = $attributes['submitted_at'] ?? now();

            return [
                'status' => 'closed',
                'closed_at' => (clone $submittedAt)->modify('+2 days'),
            ];
        });
    }
}
