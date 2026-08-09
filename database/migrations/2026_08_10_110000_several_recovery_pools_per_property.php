<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * More than one recovery pool per property-year (story RC-02).
 *
 * **What a single pool could not say.** Yardi's model is many named pools on a property — CAM, real
 * estate tax, insurance, utilities, security, HVAC — each with its own participants, basis, admin
 * fee and cap (03-yardi-recoveries-percentage-rent.md §A2). Atriom allowed exactly one per
 * `(asset_id, period_year)`, so "everyone shares CAM, but only the food court shares grease-trap
 * cleaning" was unrepresentable: the cost either went into the one pool and was charged to tenants
 * who never used it, or it stayed with the landlord.
 *
 * **`pool_code` defaults to `cam` and the scope defaults to `all`**, so every existing pool becomes
 * the property's CAM pool with every active lease participating — exactly what it already was. The
 * unique key widens rather than changes meaning: one pool per code per year, as before per year.
 *
 * **Participants are scoped by AREA, not by a list.** Atriom already has areas/zones as a
 * first-class concept with `units.area_id`, and the Yardi example is literally a zone — the food
 * court. Scoping to real data means the participant set answers correctly on its own when a lease
 * moves units, where a hand-maintained lease list would go stale silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cam_expense_pools', function (Blueprint $table) {
            // String, not a DB enum — the house rule, and operators name their own pools.
            $table->string('pool_code', 32)->default('cam')->after('period_year');
            $table->string('name')->nullable()->after('pool_code');
            $table->string('participant_scope')->default('all')->after('name');
            $table->foreignId('participant_area_id')->nullable()->after('participant_scope')
                ->constrained('areas')->nullOnDelete();
        });

        // ADD the wider key before dropping the narrow one. `asset_id` carries a foreign key, and
        // MySQL will not drop the only index that can serve it — the narrow unique starts with
        // `asset_id`, so it IS that index. Creating the wide one first (which also starts with
        // `asset_id`) gives the constraint somewhere to land, and the drop then succeeds.
        Schema::table('cam_expense_pools', function (Blueprint $table) {
            $table->unique(['asset_id', 'period_year', 'pool_code'], 'cam_pool_asset_year_code_unique');
        });

        Schema::table('cam_expense_pools', function (Blueprint $table) {
            $table->dropUnique('cam_pool_asset_year_unique');
        });
    }

    public function down(): void
    {
        // Same ordering in reverse, for the same reason.
        Schema::table('cam_expense_pools', function (Blueprint $table) {
            $table->unique(['asset_id', 'period_year'], 'cam_pool_asset_year_unique');
        });

        Schema::table('cam_expense_pools', function (Blueprint $table) {
            $table->dropUnique('cam_pool_asset_year_code_unique');
        });

        Schema::table('cam_expense_pools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('participant_area_id');
            $table->dropColumn(['pool_code', 'name', 'participant_scope']);
        });
    }
};
