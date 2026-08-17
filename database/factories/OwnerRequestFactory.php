<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\OwnerRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnerRequest>
 */
class OwnerRequestFactory extends Factory
{
    protected $model = OwnerRequest::class;

    /**
     * Define the model's default state.
     *
     * Produces a fully valid, persistable owner request (FR OWN-1/2): an open
     * request raised by an owner user to the Eltizam operator team. `asset_id`
     * and `assigned_to_user_id` are nullable in the schema, so they default to
     * null (use ->forAsset() / ->assignedTo() to attach them).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // NOT NULL + unique. Mirrors OwnerRequest::generateReference() shape.
            'reference' => 'OR-'.now()->format('Y').'-'.fake()->unique()->numberBetween(1, 999999),
            // NOT NULL FK -> users (the Jawad owner who raised it).
            'created_by_user_id' => User::factory(),
            // Nullable FK -> assets.
            'asset_id' => null,
            // DB enum (default 'operator').
            'recipient' => 'operator',
            // Nullable FK -> users.
            'assigned_to_user_id' => null,
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            // DB enum (default 'medium').
            'priority' => fake()->randomElement(OwnerRequest::PRIORITIES),
            // DB enum (default 'open').
            'status' => 'open',
            'scheduled_from' => null,
            'scheduled_to' => null,
            'resolution_notes' => null,
            'resolved_at' => null,
            'closed_at' => null,
        ];
    }

    /**
     * Attach the request to a specific property.
     */
    public function forAsset(int|Asset $asset): static
    {
        return $this->state(fn (array $attributes) => [
            'asset_id' => $asset instanceof Asset ? $asset->id : $asset,
        ]);
    }

    /**
     * Assign the request to an operator/owner user.
     */
    public function assignedTo(int|User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_to_user_id' => $user instanceof User ? $user->id : $user,
            'status' => 'in_progress',
        ]);
    }

    /**
     * A request addressed to another owner user rather than the operator team.
     */
    public function toOwner(): static
    {
        return $this->state(fn (array $attributes) => [
            'recipient' => 'owner',
        ]);
    }

    /**
     * A resolved (but not yet closed) request.
     */
    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'resolved',
            'resolution_notes' => fake()->sentence(),
            'resolved_at' => now(),
        ]);
    }

    /**
     * A terminal (closed) request — immutable per REQ-3.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
            'resolution_notes' => fake()->sentence(),
            'resolved_at' => now()->subDay(),
            'closed_at' => now(),
        ]);
    }
}
