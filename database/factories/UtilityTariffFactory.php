<?php

namespace Database\Factories;

use App\Models\UtilityTariff;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UtilityTariff> */
class UtilityTariffFactory extends Factory
{
    protected $model = UtilityTariff::class;

    public function definition(): array
    {
        return [
            'code' => 'TRF-'.$this->faker->unique()->numberBetween(1000, 9999),
            'name_en' => 'Commercial electricity',
            'name_ar' => 'كهرباء تجاري',
            'utility_type' => 'electric',
            'unit_of_measurement' => 'kWh',
            'provider' => 'North Cairo Electricity Distribution',
            'is_active' => true,
        ];
    }
}
