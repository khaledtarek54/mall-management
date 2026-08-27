<?php

use App\Support\DeletionPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Retire the remaining forty-three `{module}.delete` permissions (2026-08-26).
 *
 * The 2026-07-31 migration beside this one retired the nine on money records. These were left, and
 * `PermissionReach::NEVER_CHECKED` has carried the standing note about them ever since: *"either
 * honour them or drop them — what should not continue is a permission that reads as a right and
 * grants nothing."*
 *
 * **Honouring them was never available.** `RoleGatedActions::canDelete()` asks `DeletionPolicy` and
 * `canDeleteAny()` asks `isSuperAdmin()`; neither consults a permission, because deletion is
 * super-admin-only project-wide by the operator's decision of 2026-07-31. Giving `holidays.delete`
 * a meaning would REVERSE that decision rather than implement it.
 *
 * **This changes no access.** Verified before removal: outside `DeletionPolicy` itself, the string
 * `'{module}.delete'` appeared nowhere in `app/`. What it changes is the Roles screen, which
 * rendered forty-three checkboxes that granted nothing and read as though they granted something —
 * and four roles genuinely held some of them (`accounting` had `charge_codes.delete`,
 * `tax_codes.delete`, `utility_tariffs.delete`, `account_mappings.delete`), which is exactly the
 * confusion the note warned about.
 *
 * Removing them from `RolesPermissionsSeeder` covers a fresh install and a re-seed; production is
 * migrated rather than re-seeded, so without this the rows would survive. Deleting a permission
 * cascades its role and user assignments through `role_has_permissions` / `model_has_permissions`.
 *
 * `down()` recreates the bare permissions without re-granting them, for the same reason the earlier
 * migration gives: rolling back should not silently hand a role a right it did not have.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')
            ->whereIn('name', DeletionPolicy::retiredDeletePermissions())
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $now = now();

        foreach (DeletionPolicy::retiredDeletePermissions() as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'web'],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
