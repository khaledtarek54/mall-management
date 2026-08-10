<?php

namespace Database\Seeders;

use App\Models\ChargeCode;
use Illuminate\Database\Seeder;

/**
 * The charge-code catalogue (gap-analysis row 216).
 *
 * **Every row here reproduces exactly what `InvoiceJournalizer::REVENUE_ROLE` mapped before this
 * table existed.** That is the one hard requirement: the catalogue replaced a hard-coded map, and
 * if a single code books somewhere new then revenue moved accounts on the day of a refactor that
 * was supposed to change nothing. `ChargeCodeGlMappingConformanceTest` asserts the two agree
 * code-for-code, so drift is a red build rather than a misstated income statement.
 *
 * `other` maps to NULL deliberately — the escape hatch for an ad-hoc charge with no standing
 * revenue account, which takes the journalizer's `misc_income` fallback. Giving it a dedicated role
 * would invite billing real recurring revenue through a line nobody reports on.
 *
 * Idempotent (`updateOrCreate` on the code), so reseeding never duplicates and an operator's own
 * codes are left alone.
 */
class ChargeCodeSeeder extends Seeder
{
    /** @var array<int, array{code: string, en: string, ar: string, role: ?string, sort: int}> */
    private const CODES = [
        ['code' => 'base_rent', 'en' => 'Base rent', 'ar' => 'الإيجار الأساسي', 'role' => 'rent_revenue', 'sort' => 10],
        ['code' => 'service_charge', 'en' => 'Service charge', 'ar' => 'رسوم الخدمة', 'role' => 'service_charge_revenue', 'sort' => 20],
        ['code' => 'marketing', 'en' => 'Marketing levy', 'ar' => 'رسوم التسويق', 'role' => 'marketing_revenue', 'sort' => 30],
        ['code' => 'utility', 'en' => 'Utility recharge', 'ar' => 'إعادة تحميل المرافق', 'role' => 'utility_revenue', 'sort' => 40],
        ['code' => 'parking', 'en' => 'Parking & rentable items', 'ar' => 'المواقف والوحدات المؤجَّرة', 'role' => 'parking_revenue', 'sort' => 50],
        ['code' => 'percentage_rent', 'en' => 'Percentage rent', 'ar' => 'الإيجار النسبي', 'role' => 'percentage_rent_revenue', 'sort' => 60],
        ['code' => 'cam_recovery', 'en' => 'CAM recovery', 'ar' => 'استرداد المصروفات المشتركة', 'role' => 'cam_recovery_revenue', 'sort' => 70],
        ['code' => 'cam_admin_fee', 'en' => 'CAM administration fee', 'ar' => 'رسوم إدارة المصروفات المشتركة', 'role' => 'cam_admin_fee_revenue', 'sort' => 80],
        ['code' => 'late_fee', 'en' => 'Late fee', 'ar' => 'غرامة تأخير', 'role' => 'late_fee_income', 'sort' => 90],
        // A penalty is not consideration for a supply — it books to miscellaneous (non-operating)
        // income, and it is VAT-exempt. Mapped explicitly, not left to the fallback, so the
        // treatment is intentional and the accountant can reclassify it to a penalty-income
        // account by editing this row rather than the code.
        ['code' => 'violation_fine', 'en' => 'Violation fine', 'ar' => 'غرامة مخالفة', 'role' => 'misc_income', 'sort' => 100],
        ['code' => 'nsf_fee', 'en' => 'Returned-cheque fee', 'ar' => 'رسوم شيك مرتد', 'role' => 'misc_income', 'sort' => 110],
        ['code' => 'other', 'en' => 'Other', 'ar' => 'أخرى', 'role' => null, 'sort' => 999],
    ];

    public function run(): void
    {
        foreach (self::CODES as $row) {
            ChargeCode::updateOrCreate(
                ['code' => $row['code']],
                [
                    'name_en' => $row['en'],
                    'name_ar' => $row['ar'],
                    'posting_role' => $row['role'],
                    'sort_order' => $row['sort'],
                    'is_active' => true,
                ],
            );
        }
    }
}
