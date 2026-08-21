<?php

namespace Database\Seeders;

use App\Models\ViolationCategory;
use Illuminate\Database\Seeder;

/**
 * The seven kinds `Violation::CATEGORIES` held, as rows.
 *
 * Names come from the EXISTING `admin.violations.categories` group — the same words the form, the
 * filter and the fine invoice already showed — so a database that runs this reads identically to one
 * that does not.
 *
 * **No tariff ships.** `default_fine_amount` is null on all seven, because the schedule of penalties
 * is the operator's house rules and inventing figures would put numbers in front of a field officer
 * that nobody agreed. Nothing prefills until Eltizam fills the book in.
 *
 * Idempotent on `code`, and deliberately does not touch `is_active` or `default_fine_amount` on a
 * row that already exists — an operator who retired a rule or set its fine must not have that undone
 * by a deploy.
 */
class ViolationCategorySeeder extends Seeder
{
    /**
     * code => [EN, AR]
     *
     * @var array<string, array{0:string,1:string}>
     */
    private const CATEGORIES = [
        'signage' => ['Signage', 'اللافتات'],
        'operating_hours' => ['Operating hours', 'ساعات العمل'],
        'cleanliness' => ['Cleanliness', 'النظافة'],
        'safety' => ['Safety', 'السلامة'],
        'unauthorized_works' => ['Unauthorised works', 'أعمال غير مصرّح بها'],
        'noise' => ['Noise', 'الإزعاج'],
        'other' => ['Other', 'أخرى'],
    ];

    public function run(): void
    {
        $sort = 0;

        foreach (self::CATEGORIES as $code => [$en, $ar]) {
            $sort += 10;

            $existing = ViolationCategory::query()->where('code', $code)->first();

            if ($existing !== null) {
                // Names and ordering are ours to correct; the tariff and whether it is active are
                // the operator's.
                $existing->fill(['name_en' => $en, 'name_ar' => $ar, 'sort_order' => $sort])->save();

                continue;
            }

            ViolationCategory::create([
                'code' => $code,
                'name_en' => $en,
                'name_ar' => $ar,
                'sort_order' => $sort,
            ]);
        }
    }
}
