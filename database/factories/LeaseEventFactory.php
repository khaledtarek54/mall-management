<?php

namespace Database\Factories;

use App\Models\Lease;
use App\Models\LeaseEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaseEvent> */
class LeaseEventFactory extends Factory
{
    protected $model = LeaseEvent::class;

    public function definition(): array
    {
        return [
            'lease_id' => Lease::factory(),
            'type' => LeaseEvent::TYPE_RENT_MODIFICATION,
            'effective_date' => now()->startOfMonth()->toDateString(),
            'reason' => 'Negotiated rent reduction pending anchor re-letting.',
            'document_reference' => null,
            'user_id' => null,
            'payload' => null,
        ];
    }
}
