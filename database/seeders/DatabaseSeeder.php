<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Everything a working install needs BEFORE any demo data — roles, the chart, the catalogues.
     *
     * THE list, in THE order: `atriom:install` runs it on a real first deploy, `LearningSeeder`
     * (and so `ValPlazaSeeder`) runs it under the empty mall, and `migrate:fresh --seed` runs it
     * under the demo. It was three lists until 2026-09-05, and they had drifted: this one had no
     * `UtilityTariffSeeder`, so every dev machine and the QA baseline carried an EMPTY tariff
     * catalogue and ~50 demo meters priced every reading at 0.00 — the pre-2026-08-20 state
     * InstallCommand's own comment describes as fixed.
     *
     * Order: roles first (the approval bands reference permissions), then the catalogues, and
     * AccountingSeeder LAST because a charge code names the tax code it bills under.
     *
     * @var array<int, class-string<Seeder>>
     */
    public const REFERENCE = [
        RolesPermissionsSeeder::class,
        // After roles: the bands reference permissions those roles are granted.
        ApprovalRulesSeeder::class,
        DepartmentSeeder::class,
        // Seeded WITHOUT rates — a published figure is the operator's to confirm — but seeded,
        // because a missing catalogue makes every meter reading price at 0.00 and be refused.
        UtilityTariffSeeder::class,
        PaymentMethodSeeder::class,
        ExpenseCategorySeeder::class,
        TenantRequestSubcategorySeeder::class,
        RetailCategorySeeder::class,
        ViolationCategorySeeder::class,
        VendorDocumentTypeSeeder::class,
        // Egypt's fixed-date public holidays. Without this the calendar is EMPTY, and a missing
        // holiday is silent — an SLA measured straight across Eid with nothing to say why.
        HolidaySeeder::class,
        AccountingSeeder::class,
    ];

    public function run(): void
    {
        $this->call([...self::REFERENCE, DemoSeeder::class]);
    }
}
