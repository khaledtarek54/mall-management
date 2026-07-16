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
            DemoSeeder::class,
        ]);
    }
}
