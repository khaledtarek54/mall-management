<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\MarketingPost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingPost>
 */
class MarketingPostFactory extends Factory
{
    protected $model = MarketingPost::class;

    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'tenant_id' => null,
            'type' => MarketingPost::TYPE_OFFER,
            'status' => MarketingPost::STATUS_DRAFT,
            'audience' => MarketingPost::AUDIENCE_VISITORS,
            'title' => '20% off everything',
            'title_ar' => 'خصم ٢٠٪ على كل شيء',
            'summary' => 'This weekend only.',
            'discount_label' => '20% OFF',
            // Deliberately a LIVE window by default, so a test that publishes without setting dates
            // exercises the ordinary case rather than tripping the already-over refusal.
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addWeek(),
        ];
    }

    /** Live and on the shopper feed — with the artwork publishing requires. */
    public function published(): static
    {
        return $this->state(fn () => [
            'status' => MarketingPost::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    /** Sitting in the operator's review queue. */
    public function pending(): static
    {
        return $this->state(fn () => ['status' => MarketingPost::STATUS_PENDING]);
    }

    /** Past its window — what the expiry sweep is looking for. */
    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => MarketingPost::STATUS_PUBLISHED,
            'published_at' => now()->subMonth(),
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);
    }
}
