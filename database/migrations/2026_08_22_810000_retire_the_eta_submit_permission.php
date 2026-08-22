<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Remove `invoices.submit_to_eta` from an existing install's permission catalogue.
 *
 * `RolesPermissionsSeeder` is the catalogue, but it only ever ADDS — it uses `findOrCreate`
 * semantics and never prunes, so dropping a line from the seeder leaves the row on every database
 * that has already been seeded. The roles matrix renders one checkbox per row, so without this the
 * ETA freeze would still show "Submit invoices to the Egyptian Tax Authority" as a grantable right
 * on `/admin/roles` — on exactly the screen an operator goes to when deciding what a role may do.
 *
 * The pivot rows go with it (spatie has no cascade on `role_has_permissions` in every driver we
 * run), otherwise the grant survives as an orphan pointing at a deleted permission id.
 *
 * Restoring it is one line in the seeder plus a re-run; see App\Support\Modules::FROZEN.
 */
return new class extends Migration
{
    private const PERMISSION = 'invoices.submit_to_eta';

    public function up(): void
    {
        $ids = DB::table('permissions')->where('name', self::PERMISSION)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Not reversible: re-creating the permission without its grants would read as a right that
        // no role holds, which is the confusing half of the problem this closes. Unfreeze the
        // module and re-run RolesPermissionsSeeder instead.
    }
};
