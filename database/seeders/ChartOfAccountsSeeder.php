<?php

namespace Database\Seeders;

use App\Models\LedgerAccount;
use Illuminate\Database\Seeder;

/**
 * دليل الحسابات القياسي — a standard, bilingual starter Chart of Accounts for an
 * Egyptian mall operator. The accountant can rename, deactivate, or extend any
 * of these. Hierarchy mirrors the familiar Egyptian coding style:
 *   1 → 11 → 111 → 11101 → 11101001 (only the deepest leaves are postable).
 *
 * Parent links are resolved automatically from the code (longest existing prefix),
 * so the flat list below is all that needs maintaining.
 */
class ChartOfAccountsSeeder extends Seeder
{
    /** @var array<int, array{0:string,1:string,2:string,3:string,4:bool}> [code, name_en, name_ar, type, is_postable] */
    private const ACCOUNTS = [
        // ===== 1 ASSETS — الأصول =====
        ['1', 'Assets', 'الأصول', 'asset', false],
        ['11', 'Current Assets', 'الأصول المتداولة', 'asset', false],
        ['111', 'Cash & Banks', 'النقد بالخزينة والبنوك', 'asset', false],
        ['11101', 'Cash on Hand', 'نقد بالخزينة', 'asset', false],
        ['11101001', 'Main Cashier', 'الصندوق العام', 'asset', true],
        ['11102', 'Banks', 'البنوك', 'asset', false],
        ['11102001', 'Bank Account', 'حساب بنكي', 'asset', true],
        ['112', 'Accounts Receivable', 'المدينون', 'asset', false],
        ['11201', 'Trade Receivables — Tenants', 'ذمم العملاء (المستأجرون)', 'asset', false],
        ['11201001', 'Tenant Receivables', 'عملاء تجاريون', 'asset', true],
        ['11202', 'Other Receivables', 'ذمم مدينة أخرى', 'asset', false],
        ['11202001', 'Other Debtors', 'مدينون متنوعون', 'asset', true],
        ['11203', 'Employee Advances', 'سلف العاملين', 'asset', false],
        ['11203001', 'Employee Advances & Loans', 'سلف وقروض العاملين', 'asset', true],
        ['11204', 'Custodies', 'العُهد', 'asset', false],
        ['11204001', 'Custodies (Imprest)', 'عُهد نقدية', 'asset', true],
        // Post-dated cheques (شيكات آجلة) are how Egyptian tenants commonly settle a
        // lease — a note received sits here until it clears into the bank.
        ['11205', 'Notes Receivable', 'أوراق القبض', 'asset', false],
        ['11205001', 'Notes Receivable (Post-dated Cheques)', 'أوراق قبض (شيكات آجلة)', 'asset', true],
        // Contra-asset: carries a CREDIT balance against tenant AR, exactly like
        // accumulated depreciation (12201001) does against fixed assets.
        ['11206', 'Allowance for Doubtful Debts', 'مخصص الديون المشكوك فيها', 'asset', false],
        ['11206001', 'Allowance for Doubtful Debts', 'مخصص ديون مشكوك فيها', 'asset', true],
        ['113', 'Inventory', 'المخزون', 'asset', false],
        ['11301', 'Inventory / Stock', 'المخزون', 'asset', false],
        ['11301001', 'Inventory / Stock', 'مخزون', 'asset', true],
        ['114', 'Tax Receivables', 'ضرائب مدينة', 'asset', false],
        ['11401', 'VAT Recoverable (input)', 'ض.ق.م قابلة للخصم', 'asset', false],
        ['11401001', 'VAT Recoverable', 'ض.ق.م مدخلات', 'asset', true],
        ['115', 'Prepaid Expenses', 'المصروفات المدفوعة مقدماً', 'asset', false],
        ['11501', 'Prepaid Expenses', 'مصروفات مدفوعة مقدماً', 'asset', false],
        ['11501001', 'Prepaid Expenses', 'مصروفات مدفوعة مقدماً', 'asset', true],
        // Eltizam (operator) ↔ Jawad (owner) and any affiliate settlements.
        ['116', 'Due from Related Parties', 'المستحق من أطراف ذات علاقة', 'asset', false],
        ['11601', 'Due from Related Parties', 'المستحق من أطراف ذات علاقة', 'asset', false],
        ['11601001', 'Due from Related Parties', 'المستحق من أطراف ذات علاقة', 'asset', true],
        ['12', 'Non-current Assets', 'الأصول غير المتداولة', 'asset', false],
        ['121', 'Fixed Assets', 'الأصول الثابتة', 'asset', false],
        ['12101', 'Furniture & Equipment', 'أثاث ومعدات', 'asset', false],
        ['12101001', 'Furniture & Equipment', 'أثاث ومعدات', 'asset', true],
        ['122', 'Accumulated Depreciation', 'مجمع الإهلاك', 'asset', false],
        ['12201', 'Accumulated Depreciation', 'مجمع الإهلاك', 'asset', false],
        ['12201001', 'Accumulated Depreciation — Fixed Assets', 'مجمع إهلاك الأصول الثابتة', 'asset', true],

        // ===== 2 LIABILITIES — الخصوم =====
        ['2', 'Liabilities', 'الخصوم', 'liability', false],
        ['21', 'Current Liabilities', 'الخصوم المتداولة', 'liability', false],
        ['211', 'Accounts Payable', 'الموردون والدائنون', 'liability', false],
        ['21101', 'Trade Payables — Vendors', 'ذمم الموردين', 'liability', false],
        ['21101001', 'Vendor Payables', 'موردون تجاريون', 'liability', true],
        // The payable mirror of 11205 — a cheque issued to a vendor, not yet cleared.
        ['21102', 'Notes Payable', 'أوراق الدفع', 'liability', false],
        ['21102001', 'Notes Payable (Post-dated Cheques)', 'أوراق دفع (شيكات آجلة)', 'liability', true],
        ['212', 'Tenant Deposits Held', 'التأمينات المحتجزة', 'liability', false],
        ['21201', 'Tenant Deposits', 'تأمينات المستأجرين', 'liability', false],
        ['21201001', 'Tenant Deposits Held', 'تأمينات محتجزة', 'liability', true],
        ['213', 'Taxes Payable', 'الضرائب المستحقة', 'liability', false],
        ['21301', 'VAT Payable', 'ضريبة القيمة المضافة المستحقة', 'liability', false],
        ['21301001', 'VAT Payable', 'ض.ق.م مستحقة', 'liability', true],
        ['21302', 'Salary Tax Payable', 'ضريبة كسب العمل المستحقة', 'liability', false],
        ['21302001', 'Salary Tax Payable', 'ضريبة كسب العمل مستحقة', 'liability', true],
        ['216', 'Social Insurance Payable', 'التأمينات الاجتماعية المستحقة', 'liability', false],
        ['21601', 'Social Insurance Payable', 'التأمينات الاجتماعية المستحقة', 'liability', false],
        ['21601001', 'Social Insurance Payable', 'تأمينات اجتماعية مستحقة', 'liability', true],
        ['214', 'Accrued Expenses', 'المصروفات المستحقة', 'liability', false],
        ['21401', 'Accrued Expenses', 'مصروفات مستحقة', 'liability', false],
        ['21401001', 'Accrued Expenses', 'مصروفات مستحقة الدفع', 'liability', true],
        ['215', 'Unearned Revenue', 'الإيرادات المقدمة', 'liability', false],
        ['21501', 'Customer Advances', 'دفعات مقدمة من العملاء', 'liability', false],
        ['21501001', 'Unearned / Deferred Revenue', 'إيرادات غير مكتسبة', 'liability', true],
        ['217', 'Inventory Received (Clearing)', 'مخزون وارد (تسوية)', 'liability', false],
        ['21701', 'Goods Received Not Invoiced', 'بضاعة واردة غير مفوترة', 'liability', false],
        ['21701001', 'Goods Received Not Invoiced (GRNI)', 'بضاعة واردة غير مفوترة', 'liability', true],
        ['218', 'Due to Related Parties', 'المستحق لأطراف ذات علاقة', 'liability', false],
        ['21801', 'Due to Related Parties', 'المستحق لأطراف ذات علاقة', 'liability', false],
        ['21801001', 'Due to Related Parties', 'المستحق لأطراف ذات علاقة', 'liability', true],
        // What the property owes each owner between finalising their statement and paying
        // the disbursement (module 27). A disbursement clears this against Bank; it nets to
        // zero once every owner is fully paid. Owners are related parties, hence under 218.
        ['21802', 'Distributions Payable to Owners', 'توزيعات مستحقة للملاك', 'liability', false],
        ['21802001', 'Distributions Payable to Owners', 'توزيعات مستحقة للملاك', 'liability', true],
        ['22', 'Non-current Liabilities', 'الخصوم غير المتداولة', 'liability', false],
        ['221', 'Long-term Loans', 'قروض طويلة الأجل', 'liability', false],
        ['22101', 'Long-term Loans', 'قروض طويلة الأجل', 'liability', false],
        ['22101001', 'Long-term Loans', 'قروض طويلة الأجل', 'liability', true],
        // Provisions (222…) are NON-CASH accruals, so the cash-flow statement carves the
        // 222 branch out of the "22 → financing" rule and treats it as an operating
        // add-back (see LedgerReportService::cashFlow). Keep new provisions under 222.
        ['222', 'Provisions', 'المخصصات', 'liability', false],
        ['22201', 'Provisions', 'المخصصات', 'liability', false],
        ['22201001', 'Provision — End of Service', 'مخصص ترك الخدمة', 'liability', true],
        ['22201002', 'Provision — Staff Leave', 'مخصص إجازات', 'liability', true],

        // ===== 3 EQUITY — حقوق الملكية =====
        ['3', 'Equity', 'حقوق الملكية', 'equity', false],
        ['31', 'Capital', 'رأس المال', 'equity', false],
        ['31101', 'Capital', 'رأس المال', 'equity', false],
        ['31101001', 'Owner Capital', 'رأس المال', 'equity', true],
        ['32', 'Retained Earnings', 'الأرباح المحتجزة', 'equity', false],
        ['32101', 'Retained Earnings', 'الأرباح المحتجزة', 'equity', false],
        ['32101001', 'Retained Earnings', 'أرباح محتجزة', 'equity', true],
        ['33', 'Current Year Result', 'نتيجة العام الحالي', 'equity', false],
        ['33101', 'Current Year Result', 'نتيجة العام', 'equity', false],
        ['33101001', 'Profit / Loss for the Year', 'أرباح / خسائر العام', 'equity', true],
        // Owner distributions (module 27) — a contra-equity draw. Finalising an owner
        // statement debits this (Cr Distributions Payable 21802001); the debit balance
        // correctly REDUCES equity via the balance sheet's credit−debit math. `type` is
        // equity so `normal_balance` is credit (derived + gate-enforced) — the account is
        // "credit-normal" yet carries a debit balance, exactly like a dividends account.
        ['34', 'Owner Distributions', 'توزيعات الملاك', 'equity', false],
        ['34101', 'Owner Distributions', 'توزيعات الملاك', 'equity', false],
        ['34101001', 'Owner Distributions', 'توزيعات الملاك', 'equity', true],

        // ===== 4 REVENUE — الإيرادات =====
        ['4', 'Revenue', 'الإيرادات', 'revenue', false],
        ['41', 'Operating Revenue', 'إيرادات التشغيل', 'revenue', false],
        ['411', 'Property Revenue', 'إيرادات العقار', 'revenue', false],
        ['41101', 'Rent Revenue', 'إيرادات الإيجار', 'revenue', false],
        ['41101001', 'Base Rent Revenue', 'إيرادات الإيجار الأساسي', 'revenue', true],
        ['41102', 'Service Charge Revenue', 'إيرادات الخدمات', 'revenue', false],
        ['41102001', 'Service Charge Revenue', 'إيرادات خدمات', 'revenue', true],
        ['41103', 'CAM Recovery Revenue', 'إيرادات استرداد الصيانة', 'revenue', false],
        ['41103001', 'CAM Recovery Revenue', 'إيرادات استرداد المصروفات المشتركة', 'revenue', true],
        ['41104', 'Utility Revenue', 'إيرادات المرافق', 'revenue', false],
        ['41104001', 'Utility Revenue', 'إيرادات مرافق', 'revenue', true],
        ['41105', 'Percentage Rent Revenue', 'إيرادات الإيجار النسبي', 'revenue', false],
        ['41105001', 'Percentage Rent Revenue', 'إيرادات إيجار نسبي', 'revenue', true],
        ['41106', 'Marketing Levy Revenue', 'إيرادات رسوم التسويق', 'revenue', false],
        ['41106001', 'Marketing Levy Revenue', 'إيرادات رسوم تسويق', 'revenue', true],
        ['41107', 'Late Fee Income', 'إيرادات غرامات التأخير', 'revenue', false],
        ['41107001', 'Late Fee Income', 'إيرادات غرامات تأخير', 'revenue', true],
        // The CAM administrative fee (10-15% the landlord adds on top of the recovered pool) is
        // margin the landlord SELLS — its own revenue account, distinct from the cost pass-through.
        ['41108', 'CAM Admin Fee Revenue', 'إيرادات رسوم إدارة المصروفات المشتركة', 'revenue', false],
        ['41108001', 'CAM Admin Fee Revenue', 'إيرادات رسوم إدارة المصروفات المشتركة', 'revenue', true],
        ['42', 'Other Income', 'إيرادات أخرى', 'revenue', false],
        ['42101', 'Miscellaneous Income', 'إيرادات متنوعة', 'revenue', false],
        ['42101001', 'Miscellaneous Income', 'إيرادات متنوعة', 'revenue', true],
        ['42102', 'Gain on Disposal of Assets', 'أرباح بيع أصول ثابتة', 'revenue', false],
        ['42102001', 'Gain on Disposal of Assets', 'أرباح بيع أصول ثابتة', 'revenue', true],
        ['43', 'Sales Returns & Allowances', 'مردودات ومسموحات المبيعات', 'revenue', false],
        ['43101', 'Sales Returns & Allowances', 'مردودات ومسموحات المبيعات', 'revenue', false],
        ['43101001', 'Sales Returns & Allowances', 'مردودات ومسموحات المبيعات', 'revenue', true],

        // ===== 5 EXPENSES — المصروفات =====
        ['5', 'Expenses', 'المصروفات', 'expense', false],
        ['51', 'Operating Expenses', 'مصروفات التشغيل', 'expense', false],
        ['51101', 'Salaries & Wages', 'رواتب وأجور', 'expense', false],
        ['51101001', 'Salaries & Wages', 'رواتب وأجور', 'expense', true],
        ['51102', 'Repairs & Maintenance', 'صيانة وإصلاحات', 'expense', false],
        ['51102001', 'Repairs & Maintenance', 'صيانة وإصلاحات', 'expense', true],
        ['51103', 'Utilities Expense', 'مصروف المرافق', 'expense', false],
        ['51103001', 'Utilities Expense', 'مصروف مرافق', 'expense', true],
        ['51104', 'Cleaning & Security', 'نظافة وأمن', 'expense', false],
        ['51104001', 'Cleaning & Security', 'نظافة وأمن', 'expense', true],
        ['51105', 'Marketing Expense', 'مصروف التسويق', 'expense', false],
        ['51105001', 'Marketing & Advertising', 'مصروف تسويق ودعاية', 'expense', true],
        ['51106', 'General & Admin', 'مصروفات إدارية وعمومية', 'expense', false],
        ['51106001', 'General & Admin Expense', 'مصروفات إدارية وعمومية', 'expense', true],
        ['51107', 'Depreciation Expense', 'مصروف الإهلاك', 'expense', false],
        ['51107001', 'Depreciation Expense', 'مصروف إهلاك', 'expense', true],
        ['51108', 'Inventory Adjustment', 'تسويات المخزون', 'expense', false],
        ['51108001', 'Inventory Adjustment', 'تسوية مخزون (عجز/زيادة)', 'expense', true],
        // The P&L counterpart of the 11206001 allowance (Dr Bad Debt / Cr Allowance).
        ['51109', 'Bad Debt Expense', 'مصروف الديون المشكوك فيها', 'expense', false],
        ['51109001', 'Bad Debt Expense', 'مصروف ديون مشكوك فيها', 'expense', true],
        ['52', 'Other Expenses', 'مصروفات أخرى', 'expense', false],
        ['52101', 'Bank Charges', 'مصروفات بنكية', 'expense', false],
        ['52101001', 'Bank Charges', 'مصروفات بنكية', 'expense', true],
        ['52102', 'Loss on Disposal of Assets', 'خسائر بيع أصول ثابتة', 'expense', false],
        ['52102001', 'Loss on Disposal of Assets', 'خسائر بيع أصول ثابتة', 'expense', true],
        // Split out from 52101 so the accountant can read a bank statement line-for-line:
        // fees vs commission vs the interest cost of borrowing.
        ['52103', 'Bank Commission', 'العمولات البنكية', 'expense', false],
        ['52103001', 'Bank Commission', 'عمولات بنكية', 'expense', true],
        ['52104', 'Interest Expense', 'الفوائد البنكية', 'expense', false],
        ['52104001', 'Interest Expense', 'فوائد بنكية', 'expense', true],
    ];

    public function run(): void
    {
        $idByCode = [];

        // ACCOUNTS is authored parent-before-child, but sort by code anyway so a
        // proper prefix (an ancestor) is always processed before its descendants.
        $accounts = self::ACCOUNTS;
        usort($accounts, fn ($a, $b) => strcmp($a[0], $b[0]));

        foreach ($accounts as [$code, $nameEn, $nameAr, $type, $isPostable]) {
            $parentId = $this->resolveParentId($code, $idByCode);

            $account = LedgerAccount::updateOrCreate(
                ['code' => $code],
                [
                    'parent_id' => $parentId,
                    'name_en' => $nameEn,
                    'name_ar' => $nameAr,
                    'type' => $type,
                    'is_postable' => $isPostable,
                    'is_active' => true,
                ],
            );

            $idByCode[$code] = $account->id;
        }
    }

    /** Parent = the longest already-created code that is a strict prefix of $code. */
    private function resolveParentId(string $code, array $idByCode): ?int
    {
        for ($len = strlen($code) - 1; $len >= 1; $len--) {
            $prefix = substr($code, 0, $len);
            if (isset($idByCode[$prefix])) {
                return $idByCode[$prefix];
            }
        }

        return null;
    }
}
