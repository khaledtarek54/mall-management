<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesPermissionsSeeder::class,
            DepartmentSeeder::class,
            AccountingSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
