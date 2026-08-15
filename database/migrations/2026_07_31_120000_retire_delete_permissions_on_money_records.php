<?php

use App\Support\DeletionPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Retire the `.delete` permissions on money records (operator decision, 2026-07-31).
 *
 * Removing them from `RolesPermissionsSeeder` covers a fresh install and any environment where the
 * seeder is re-run — production is migrated, not re-seeded, so the rows would otherwise survive.
 * That matters: a permission row still exists in the Roles screen's checkbox list, so an
 * administrator could grant `invoices.delete` to a role and reasonably expect it to do something.
 * It would grant a right nothing can exercise, and would restore a Delete button the moment anyone
 * re-added the action.
 *
 * Deleting the permission cascades its role/user assignments through
 * `role_has_permissions` / `model_has_permissions`.
 *
 * Down() recreates the bare permissions without re-granting them to anyone: rolling a migration
 * back should not silently hand a role the ability to delete a paid invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')
            ->whereIn('name', DeletionPolicy::retiredPermissions())
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $now = now();

        foreach (DeletionPolicy::retiredPermissions() as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'web'],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
