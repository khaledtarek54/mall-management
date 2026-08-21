<?php

namespace Database\Seeders;

use App\Models\RetailCategory;
use Illuminate\Database\Seeder;

/**
 * The twelve categories the `Tenant::RETAIL_CATEGORIES` const held, as rows.
 *
 * Names come from the EXISTING `admin.retail_categories` lang group — the same words the form and
 * the directory already showed — so a database that runs this reads identically to one that does
 * not. Nothing new is switched on, unlike the payment-rail and expense-category catalogues: the
 * point here is that the operator can now revise the list, not that the shipped list was wrong.
 *
 * Idempotent on `code`, and deliberately does not touch `is_active` — an operator who retired a
 * category must not have that undone by a deploy.
 */
class RetailCategorySeeder extends Seeder
{
    /**
     * code => [EN, AR]
     *
     * @var array<string, array{0:string,1:string}>
     */
    private const CATEGORIES = [
        'fashion' => ['Fashion', 'أزياء'],
        'food_beverage' => ['Food & beverage', 'مأكولات ومشروبات'],
        'electronics' => ['Electronics', 'إلكترونيات'],
        'health_beauty' => ['Health & beauty', 'صحة وتجميل'],
        'home_lifestyle' => ['Home & lifestyle', 'منزل ونمط حياة'],
        'kids_toys' => ['Kids & toys', 'أطفال وألعاب'],
        'sports' => ['Sports', 'رياضة'],
        'jewellery_accessories' => ['Jewellery & accessories', 'مجوهرات وإكسسوارات'],
        'entertainment' => ['Entertainment', 'ترفيه'],
        'services' => ['Services', 'خدمات'],
        'hypermarket' => ['Hypermarket', 'هايبر ماركت'],
        'other' => ['Other', 'أخرى'],
    ];

    public function run(): void
    {
        $sort = 0;

        foreach (self::CATEGORIES as $code => [$en, $ar]) {
            $sort += 10;

            $existing = RetailCategory::query()->where('code', $code)->first();

            if ($existing !== null) {
                // Names and ordering are ours to correct; whether it is active is the operator's.
                $existing->fill(['name_en' => $en, 'name_ar' => $ar, 'sort_order' => $sort])->save();

                continue;
            }

            RetailCategory::create([
                'code' => $code,
                'name_en' => $en,
                'name_ar' => $ar,
                'sort_order' => $sort,
            ]);
        }
    }
}
