<?php

namespace Database\Seeders;

use App\Models\UtilityTariff;
use Illuminate\Database\Seeder;

/**
 * The published utility tariffs a mall recharges against — reference data, like charge codes.
 *
 * **Why this exists (2026-08-20).** The tariff catalogue shipped and nothing ever created one:
 * `atriom:install` laid down roles, approvals, departments and the accounting reference data, and
 * no tariffs; `DemoSeeder` created 48 meters with neither a tariff nor a `rate_per_unit` override.
 * So on a fresh install AND in the demo, recording a meter reading priced it at **0.00** —
 * `UtilityMeter::resolvedRatePerUnit()` falls through both steps — and `BillMeterReadingService`
 * then correctly refuses to bill a zero-cost recharge. The capability was complete and the data to
 * make it work did not exist, which reads to an operator as a feature that does nothing.
 *
 * **Seeded WITHOUT rungs, deliberately.** A rate is a published figure an operator must confirm
 * against their own bill, and inventing one would silently recharge every tenant at a number nobody
 * checked — worse than not billing. `UtilityTariff::rateOn()` returns null with no rungs, which the
 * tariffs screen already renders in danger as *"no rate yet"*: the operator sees three tariffs
 * asking to be priced, and entering one rung prices every meter on it at once, which is the whole
 * point of the ladder.
 *
 * Idempotent on `code` — re-running never duplicates, and never overwrites a rate somebody entered.
 */
class UtilityTariffSeeder extends Seeder
{
    /** The three supplies an Egyptian mall meters and recharges. */
    private const TARIFFS = [
        ['code' => 'ELEC-COMM', 'type' => 'electric', 'unit' => 'kWh',
            'en' => 'Electricity — commercial', 'ar' => 'كهرباء — تجاري', 'provider' => 'EgyptERA'],
        ['code' => 'WATER-COMM', 'type' => 'water', 'unit' => 'm³',
            'en' => 'Water — commercial', 'ar' => 'مياه — تجاري', 'provider' => 'HCWW'],
        ['code' => 'GAS-COMM', 'type' => 'gas', 'unit' => 'm³',
            'en' => 'Natural gas — commercial', 'ar' => 'غاز طبيعي — تجاري', 'provider' => 'EGAS'],
    ];

    public function run(): void
    {
        foreach (self::TARIFFS as $t) {
            UtilityTariff::updateOrCreate(
                ['code' => $t['code']],
                [
                    'utility_type' => $t['type'],
                    'unit_of_measurement' => $t['unit'],
                    'name_en' => $t['en'],
                    'name_ar' => $t['ar'],
                    'provider' => $t['provider'],
                    'is_active' => true,
                ],
            );
        }
    }
}
