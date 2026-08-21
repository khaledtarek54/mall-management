<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use App\Support\CostNature;
use Illuminate\Database\Seeder;

/**
 * The six categories Atriom shipped with, plus the Egyptian overheads it had nowhere to put.
 *
 * The six ACTIVE rows are exactly the `private const` this replaced, with the same cost natures, so
 * a database that runs this behaves identically to one that does not — `ValueSets` keeps the same
 * six as its floor and every code here is already in it.
 *
 * The five INACTIVE rows are the point. `docs/EGYPT-MARKET-FIT.md` (D-1) named them: insurance,
 * government fees and licences, bank charges, legal and professional, generator fuel. Every one of
 * them was collapsing into `admin_expense` behind a `Log::warning`, and in an Egyptian mall they are
 * most of the overhead. Shipping them switched OFF means an operator turns one on once they have
 * decided which account it books to — and turning one on cannot change anything already posted.
 *
 * `ledger_account_id` is null on every row: that is the floor, and it reproduces the map exactly.
 * Which account a category books to is the accountant's ruling and needs the real Egyptian chart.
 *
 * Idempotent on `code`, and deliberately does NOT touch `is_active`, `cost_nature` or
 * `ledger_account_id` on an existing row — an operator who retired a category, re-natured one, or
 * pointed one at an account must not have that undone by a deploy.
 */
class ExpenseCategorySeeder extends Seeder
{
    /**
     * code, EN, AR, nature, active, sort
     *
     * @var array<int, array{0:string,1:string,2:string,3:string,4:bool,5:int}>
     */
    private const CATEGORIES = [
        ['maintenance',       'Maintenance',              'صيانة',                  CostNature::VARIABLE, true,  10],
        ['utilities',         'Utilities',                'مرافق',                  CostNature::VARIABLE, true,  20],
        ['cleaning_security', 'Cleaning & security',      'نظافة وأمن',             CostNature::FIXED,    true,  30],
        ['marketing',         'Marketing',                'تسويق',                  CostNature::VARIABLE, true,  40],
        ['admin',             'Administrative',           'إدارية',                 CostNature::FIXED,    true,  50],
        ['other',             'Other',                    'أخرى',                   CostNature::VARIABLE, true,  99],

        // Present and switched OFF — the overheads that were landing in `admin_expense`.
        ['insurance',         'Insurance',                'تأمين',                  CostNature::FIXED,    false, 60],
        ['government_fees',   'Government fees & licences', 'رسوم حكومية وتراخيص', CostNature::FIXED,    false, 61],
        ['bank_charges',      'Bank charges',             'مصاريف بنكية',           CostNature::VARIABLE, false, 62],
        ['legal_professional', 'Legal & professional',    'أتعاب قانونية ومهنية',   CostNature::VARIABLE, false, 63],
        ['fuel',              'Fuel & generator',         'وقود ومولدات',           CostNature::VARIABLE, false, 64],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as [$code, $en, $ar, $nature, $active, $sort]) {
            $existing = ExpenseCategory::query()->where('code', $code)->first();

            if ($existing !== null) {
                // Names and ordering are ours to correct; the operator's decisions are not.
                $existing->fill(['name_en' => $en, 'name_ar' => $ar, 'sort_order' => $sort])->save();

                continue;
            }

            ExpenseCategory::create([
                'code' => $code,
                'name_en' => $en,
                'name_ar' => $ar,
                'cost_nature' => $nature,
                'is_active' => $active,
                'sort_order' => $sort,
            ]);
        }
    }
}
