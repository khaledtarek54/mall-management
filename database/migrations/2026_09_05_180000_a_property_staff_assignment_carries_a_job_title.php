<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `asset_user.title` — the staff member's job title AT THIS PROPERTY.
 *
 * Reported by the tester: typing a title on the Assigned Staff modal saved, showed "Saved", and
 * left the column reading "—". The field wrote nowhere. `2026_07_29_090000_drop_role_from_asset_user`
 * removed the column it was bound to and the FORM FIELD and TABLE COLUMN survived the drop — the
 * inert-control shape this codebase keeps finding, and the worst version of it, because a save that
 * silently discards what the operator typed is indistinguishable from one that worked.
 *
 * **This is not a revert of that drop, and must not become one.** The July migration dropped `role`
 * because nothing read it and its three writers each meant something different by it — a job title,
 * a Spatie role, and nothing at all — so the column was a trap for whoever implemented per-property
 * RBAC later and reached for the populated, wrong field already sitting there. That reasoning still
 * holds and the name is most of it: `role` is in `CLASSIFICATION_SUFFIXES`, so a column of that name
 * is swept as a closed value set and would need a `ValueSets` entry it can never honestly have,
 * while what an operator types here is free text nobody enumerates.
 *
 * So the column comes back under the name of the thing it actually holds, which is what both the
 * screen and its helper text have called it the whole time ("Title at this property — e.g. Property
 * Manager, Site Engineer, Leasing Lead"). A future per-property-RBAC implementer reaching for
 * `role` now finds nothing, which is the outcome the July migration wanted.
 *
 * It is deliberately NOT backfilled from anywhere. The old `role` values are gone, `employees`
 * holds a different population (an Employee is an HR record; a property's assigned staff are panel
 * users, and a super_admin assigned to a mall is not an employee row), and inventing titles for
 * existing assignments would put words in the operator's mouth on a field they can see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_user', function (Blueprint $table) {
            $table->string('title', 100)->nullable()->after('asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('asset_user', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
