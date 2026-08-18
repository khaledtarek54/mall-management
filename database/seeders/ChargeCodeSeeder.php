<?php

namespace Database\Seeders;

use App\Models\ChargeCode;
use App\Models\TaxCode;
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
    /**
     * `tax` names a row in the tax catalogue ({@see TaxCodeSeeder}) rather than restating that
     * tax's own properties. It reproduces exactly what the system billed before taxability became
     * data — the same set `Vat::EXEMPT_TYPES` names, plus parking, which was exempt by default
     * under the settings toggle that preceded it. `ChargeCodeVatTreatmentConformanceTest` asserts
     * the two agree, so the floor in `Vat` and the catalogue seeded here can never state different
     * tax policy.
     *
     * A code pointed at `VAT_EXEMPT` bills nothing whatever the standard rate does, which is the
     * property that has to survive every future rate change.
     *
     * @var array<int, array{code: string, en: string, ar: string, role: ?string, tax: string, sort: int}>
     */
    private const CODES = [
        // Rent is outside the scope of VAT under the Egyptian VAT Law — the oldest rule in the
        // system and the one an accountant is least likely to change.
        ['code' => 'base_rent', 'en' => 'Base rent', 'ar' => 'الإيجار الأساسي', 'role' => 'rent_revenue', 'tax' => 'VAT_EXEMPT', 'sort' => 10],
        ['code' => 'service_charge', 'en' => 'Service charge', 'ar' => 'رسوم الخدمة', 'role' => 'service_charge_revenue', 'tax' => 'VAT_14', 'sort' => 20],
        // The levy follows rent today. Flagged for the accountant as possibly a taxable service —
        // which is now their edit to make, on this row, rather than a code change.
        ['code' => 'marketing', 'en' => 'Marketing levy', 'ar' => 'رسوم التسويق', 'role' => 'marketing_revenue', 'tax' => 'VAT_EXEMPT', 'sort' => 30],
        ['code' => 'utility', 'en' => 'Utility recharge', 'ar' => 'إعادة تحميل المرافق', 'role' => 'utility_revenue', 'tax' => 'VAT_14', 'sort' => 40],
        // Parking is a licence to use a space rather than a lease of it, and the VAT Law schedules
        // settle which it is. Ships exempt — under-charging beats collecting tax that may not be
        // due and having to refund it — and was a settings toggle until 2026-08-11.
        ['code' => 'parking', 'en' => 'Parking & rentable items', 'ar' => 'المواقف والوحدات المؤجَّرة', 'role' => 'parking_revenue', 'tax' => 'VAT_EXEMPT', 'sort' => 50],
        ['code' => 'percentage_rent', 'en' => 'Percentage rent', 'ar' => 'الإيجار النسبي', 'role' => 'percentage_rent_revenue', 'tax' => 'VAT_EXEMPT', 'sort' => 60],
        ['code' => 'cam_recovery', 'en' => 'CAM recovery', 'ar' => 'استرداد المصروفات المشتركة', 'role' => 'cam_recovery_revenue', 'tax' => 'VAT_14', 'sort' => 70],
        ['code' => 'security_deposit', 'en' => 'Security deposit', 'ar' => 'تأمين', 'role' => 'deposits_held', 'tax' => 'VAT_EXEMPT', 'sort' => 85],
        ['code' => 'cam_admin_fee', 'en' => 'CAM administration fee', 'ar' => 'رسوم إدارة المصروفات المشتركة', 'role' => 'cam_admin_fee_revenue', 'tax' => 'VAT_14', 'sort' => 80],
        ['code' => 'late_fee', 'en' => 'Late fee', 'ar' => 'غرامة تأخير', 'role' => 'late_fee_income', 'tax' => 'VAT_EXEMPT', 'sort' => 90],
        // A penalty is not consideration for a supply — it books to miscellaneous (non-operating)
        // income, and it is VAT-exempt. Mapped explicitly, not left to the fallback, so the
        // treatment is intentional and the accountant can reclassify it to a penalty-income
        // account by editing this row rather than the code.
        ['code' => 'violation_fine', 'en' => 'Violation fine', 'ar' => 'غرامة مخالفة', 'role' => 'misc_income', 'tax' => 'VAT_EXEMPT', 'sort' => 100],
        ['code' => 'nsf_fee', 'en' => 'Returned-cheque fee', 'ar' => 'رسوم شيك مرتد', 'role' => 'misc_income', 'tax' => 'VAT_EXEMPT', 'sort' => 110],
        // The escape hatch defaults to taxable: an ad-hoc charge is more often a service than not,
        // and the operator can zero the rate on the line.
        ['code' => 'other', 'en' => 'Other', 'ar' => 'أخرى', 'role' => null, 'tax' => 'VAT_14', 'sort' => 999],
    ];

    public function run(): void
    {
        foreach (self::CODES as $row) {
            $code = ChargeCode::firstOrNew(['code' => $row['code']]);

            // Taxability is set on CREATE only. Everything else here is structural and safe to
            // re-assert, but which tax a supply is billed under is the accountant's ruling: a
            // reseed that quietly re-taxed a supply they had exempted would change what the next
            // invoice charges the tenant, and nobody would be looking at the seeder when it
            // happened.
            if (! $code->exists) {
                $code->tax_code = $row['tax'];
            }

            $code->fill([
                'name_en' => $row['en'],
                'name_ar' => $row['ar'],
                'posting_role' => $row['role'],
                'sort_order' => $row['sort'],
                'is_active' => true,
            ])->save();
        }

        // The charge codes above name tax codes; if this seeder ran without the tax catalogue
        // (a test seeding only this one), say so rather than leaving every charge silently on the
        // floor. Cheap: one query against a table of fifteen.
        if (TaxCode::query()->doesntExist()) {
            $this->command?->warn('Charge codes seeded, but the tax catalogue is empty — run TaxCodeSeeder or every charge will resolve through the Vat floor.');
        }
    }
}
