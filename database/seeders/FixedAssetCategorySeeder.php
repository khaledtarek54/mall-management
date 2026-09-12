<?php

namespace Database\Seeders;

use App\Models\FixedAssetCategory;
use App\Support\TaxDepreciation;
use Illuminate\Database\Seeder;

/**
 * The asset classes a fresh install ships, as rows (meeting 2026-09-02, points 11 · 13 · 14).
 *
 * The eight codes `admin.enums.category_suggestions.fixed_asset` already labelled in both
 * languages — so a database that runs this reads exactly as one that never did — each with the
 * series prefix its tags are numbered under, a proposed useful life, the Law 91/2005 pool it
 * usually falls in, and the MEMO value.
 *
 * **The memo value is 1.00 on every class** — SAP's rule, and the accountant's "salvage default 1":
 * a fully-depreciated asset then stays on the register at one pound rather than at nil. The lives
 * are proposals in the range the market uses (furniture 5 years, plant 7–10, IT 4); the accountant
 * sets them on the screen, and nothing here is read again once an asset is registered.
 *
 * Idempotent on `code`, and deliberately does not touch the defaults, the prefix or `is_active` on
 * a row that already exists — a class the operator re-lived, re-prefixed or retired must not have
 * that undone by a deploy. Names and ordering are ours to correct.
 */
class FixedAssetCategorySeeder extends Seeder
{
    /**
     * code => [EN, AR, prefix, life in months, tax pool]
     *
     * @var array<string, array{0:string,1:string,2:string,3:int,4:string}>
     */
    private const CATEGORIES = [
        'furniture' => ['Furniture', 'أثاث', 'FUR', 60, TaxDepreciation::GENERAL],
        'equipment' => ['Equipment', 'معدات', 'EQP', 84, TaxDepreciation::GENERAL],
        'HVAC' => ['HVAC', 'تكييف وتهوية', 'HVAC', 120, TaxDepreciation::GENERAL],
        'IT' => ['IT', 'تقنية المعلومات', 'IT', 48, TaxDepreciation::COMPUTERS],
        'vehicles' => ['Vehicles', 'مركبات', 'VEH', 60, TaxDepreciation::GENERAL],
        'fit-out' => ['Fit-out', 'تجهيز الوحدة', 'FIT', 120, TaxDepreciation::GENERAL],
        'generator' => ['Generator', 'مولد كهربائي', 'GEN', 180, TaxDepreciation::GENERAL],
        'elevator' => ['Elevator', 'مصعد', 'ELV', 240, TaxDepreciation::GENERAL],
    ];

    public function run(): void
    {
        $sort = 0;

        foreach (self::CATEGORIES as $code => [$en, $ar, $prefix, $life, $pool]) {
            $sort += 10;

            $existing = FixedAssetCategory::query()->where('code', $code)->first();

            if ($existing !== null) {
                $existing->fill(['name_en' => $en, 'name_ar' => $ar, 'sort_order' => $sort])->save();

                continue;
            }

            // The migration that created the table skipped every SHIPPED code and left it to this
            // seeder, so a shipped class always arrives with its life, pool and prefix; a prefix
            // already taken by an operator's OWN class (a free-text value the migration rowed as
            // `FUR`) yields to it, and the model derives another.
            $taken = FixedAssetCategory::query()->where('tag_prefix', $prefix)->exists();

            FixedAssetCategory::create([
                'code' => $code,
                'name_en' => $en,
                'name_ar' => $ar,
                'tag_prefix' => $taken ? null : $prefix,
                'default_useful_life_months' => $life,
                'default_salvage_value' => 1.00,
                'default_tax_pool' => $pool,
                'sort_order' => $sort,
            ]);
        }
    }
}
