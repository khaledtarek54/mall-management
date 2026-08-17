<?php

namespace Database\Factories;

use App\Models\UtilityTariff;
use App\Models\UtilityTariffRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UtilityTariffRate> */
class UtilityTariffRateFactory extends Factory
{
    protected $model = UtilityTariffRate::class;

    public function definition(): array
    {
        return [
            'utility_tariff_id' => UtilityTariff::factory(),
            'rate_per_unit' => 1.45,
            'effective_from' => now()->startOfYear()->toDateString(),
            'note' => null,
        ];
    }
}
