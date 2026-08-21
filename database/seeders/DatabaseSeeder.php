<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
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
            HolidaySeeder::class,
            DemoSeeder::class,
        ]);
    }
}
