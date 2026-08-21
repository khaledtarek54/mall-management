<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Egypt's FIXED-DATE public holidays, and deliberately only those.
 *
 * The moon-sighted ones — Eid al-Fitr, Eid al-Adha, the Islamic New Year, the Prophet's birthday —
 * are **not** seeded, and that is the whole point of the register. They move every year, they are
 * announced a day or two ahead, and any list shipped in code would be a guess that reads as an
 * authority. Coptic Easter and Sham El-Nessim move too. What ships is the set that genuinely does
 * not move; the operator adds the rest each year, which is the annual task the screen guide names.
 *
 * A shipped holiday is portfolio-wide (`asset_id` null) and applies to every mall.
 *
 * Idempotent, and re-runnable: `updateOrCreate` on (asset_id, date) so a reseed cannot double a
 * date, and an operator's own edit to a seeded row's name survives nothing — which is why the
 * seeder only ever runs on install and on a deliberate reseed.
 */
class HolidaySeeder extends Seeder
{
    /** month => day => [en, ar]. Fixed Gregorian dates only. */
    private const FIXED = [
        [1, 7, 'Coptic Christmas', 'عيد الميلاد المجيد'],
        [1, 25, 'Revolution Day / Police Day', 'عيد ثورة ٢٥ يناير وعيد الشرطة'],
        [4, 25, 'Sinai Liberation Day', 'عيد تحرير سيناء'],
        [5, 1, 'Labour Day', 'عيد العمال'],
        [6, 30, 'June 30 Revolution Day', 'ثورة ٣٠ يونيو'],
        [7, 23, 'Revolution Day', 'عيد ثورة ٢٣ يوليو'],
        [10, 6, 'Armed Forces Day', 'عيد القوات المسلحة'],
    ];

    public function run(): void
    {
        $year = CarbonImmutable::now()->year;

        // This year and next: a calendar that stops in December is a calendar that silently expires
        // on New Year's Day, and the failure mode is an SLA quietly measured across a holiday.
        foreach ([$year, $year + 1] as $on) {
            foreach (self::FIXED as [$month, $day, $en, $ar]) {
                Holiday::updateOrCreate(
                    ['asset_id' => null, 'date' => CarbonImmutable::create($on, $month, $day)->toDateString()],
                    ['kind' => Holiday::KIND_CLOSURE, 'name_en' => $en, 'name_ar' => $ar, 'is_active' => true],
                );
            }
        }
    }
}
