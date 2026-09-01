<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Everything a working install needs BEFORE any demo data — roles, the chart, the catalogues.
     *
     * A constant rather than an inline list because a seeder that stands in for `DemoSeeder`
     * (`ValPlazaSeeder`) needs exactly the same prerequisites, and `--seeder=` runs ONE class and
     * nothing else. Re-listing them there would be a second copy free to drift, and the failure is
     * silent until the run dies partway through on a missing role.
     *
     * @var array<int, class-string<Seeder>>
     */
    public const REFERENCE = [
        RolesPermissionsSeeder::class,
        // After roles: the bands reference permissions those roles are granted.
        ApprovalRulesSeeder::class,
        DepartmentSeeder::class,
        AccountingSeeder::class,
        // Without this the holiday register is empty on every dev machine, across the whole
        // test suite and in the QA baseline — so the one screen that decides SLA deadlines
        // is never exercised on realistic data and renders a blank table.
        PaymentMethodSeeder::class,
        ExpenseCategorySeeder::class,
        TenantRequestSubcategorySeeder::class,
        RetailCategorySeeder::class,
        ViolationCategorySeeder::class,
        VendorDocumentTypeSeeder::class,
        HolidaySeeder::class,
    ];

    public function run(): void
    {
        $this->call([...self::REFERENCE, DemoSeeder::class]);
    }
}
