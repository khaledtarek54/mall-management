<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesPermissionsSeeder extends Seeder
{
    public const ROLES = [
        'super_admin' => 'Full access — create, edit, delete, view everything.',
        'manager' => 'Day-to-day operations — create + edit, no delete.',
        'viewer' => 'Read-only access for stakeholders + auditors.',
        'owner' => 'Property owner — read-only access to their portfolio via /owner panel.',
        'leasing_manager' => 'Leasing pipeline, tenant onboarding, lease renewals + terminations.',
        'maintenance_manager' => 'Maintenance request triage + vendor dispatch + SLA management.',
    ];

    public function run(): void
    {
        // Reset cached roles so freshly-seeded roles take effect immediately.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(self::ROLES) as $name) {
            Role::findOrCreate($name, 'web');
        }
    }
}
