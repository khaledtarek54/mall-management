<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop `asset_user.role` — an unread column that meant three different things.
 *
 * It looked like per-property RBAC and was not: **nothing in the application ever read it.** Its
 * three writers each put something different in:
 *
 *   - `DemoSeeder`        → a job title      ("Operations Manager", "Leasing Lead")
 *   - `RegisterProperty`  → a Spatie role    ("manager")
 *   - the Users form      → nothing at all   (the multi-select writes no pivot data, so every
 *                                             assignment made through the UI left it NULL)
 *
 * That is a trap for whoever implements per-property roles later: the column they would reach for
 * already exists, is already populated, and is already wrong.
 *
 * Nothing is lost. "Who works at this property and as what" is already modelled properly by
 * `employees` (`asset_id` + `position`), which holds the very same strings this column duplicated.
 * `assigned_at` / `ended_at` stay — those ARE read (assignment tenure, guarded by
 * AssignedAssetsLapsedScopeTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_user', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('asset_user', function (Blueprint $table) {
            $table->string('role')->nullable()->after('asset_id');
        });
    }
};
