<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the five core ERP departments as operator-wide (global) org units.
 * asset_id is null = applies across every property. See
 * docs/requirements/FUNCTIONAL-REQUIREMENTS.md §5 (DEPT-1). Idempotent — keyed on slug.
 */
class DepartmentSeeder extends Seeder
{
    /** @var array<int, array{name: string, code: string}> */
    private const CORE = [
        ['name' => 'HR',         'code' => 'HR'],
        ['name' => 'Marketing',  'code' => 'MKT'],
        ['name' => 'Accounting', 'code' => 'ACC'],
        ['name' => 'Leasing',    'code' => 'LEAS'],
        ['name' => 'Operations', 'code' => 'OPS'],
    ];

    public function run(): void
    {
        foreach (self::CORE as $i => $dept) {
            Department::updateOrCreate(
                ['slug' => Str::slug($dept['name'])],
                [
                    'name' => $dept['name'],
                    'code' => $dept['code'],
                    'asset_id' => null,
                    'is_active' => true,
                    'sort_order' => $i + 1,
                ],
            );
        }
    }
}
