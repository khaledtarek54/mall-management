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
        ['name' => 'HR',         'name_ar' => 'الموارد البشرية', 'code' => 'HR'],
        ['name' => 'Marketing',  'name_ar' => 'التسويق',          'code' => 'MKT'],
        ['name' => 'Accounting', 'name_ar' => 'الحسابات',         'code' => 'ACC'],
        ['name' => 'Leasing',    'name_ar' => 'التأجير',          'code' => 'LEAS'],
        ['name' => 'Operations', 'name_ar' => 'التشغيل',          'code' => 'OPS'],
    ];

    public function run(): void
    {
        foreach (self::CORE as $i => $dept) {
            Department::updateOrCreate(
                ['slug' => Str::slug($dept['name'])],
                [
                    'name' => $dept['name'],
                    // Backfilled on every run: the column arrived after these five rows existed, so
                    // an upgraded box has them null until this writes them.
                    'name_ar' => $dept['name_ar'],
                    'code' => $dept['code'],
                    'asset_id' => null,
                    'is_active' => true,
                    'sort_order' => $i + 1,
                ],
            );
        }
    }
}
