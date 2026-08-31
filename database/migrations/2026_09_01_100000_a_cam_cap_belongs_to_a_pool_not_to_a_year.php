<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A CAM CAP BELONGS TO A RECOVERY POOL, NOT TO A YEAR.
 *
 * `lease_cam_terms` was `unique(lease_id, effective_year)` — ONE ceiling per lease per year,
 * resolved by `Lease::resolveCamCeiling(int $year)`, which takes a year and nothing else. So every
 * pool reconciling that year applied the SAME ceiling independently, and a tenant in two pools could
 * bear twice their stated cap. Measured on the demo books: Zööba trades in `cam` and `fc_grease` in
 * 2025 under a 45,000 term, and each pool caps at 45,000 — 90,000 borne against a contract that says
 * 45,000. Worse, `camCapHeadroomBankedBefore()` filters on `period_year <` alone, so headroom banked
 * under a grease-trap pool was spendable against the CAM cap.
 *
 * Yardi is unambiguous: a property runs several recovery pools — CAM, real-estate tax, insurance,
 * utilities, security, HVAC — "each with a different recovery basis, a different set of participants
 * AND A DIFFERENT CAP". The cap is a property of the lease's setup FOR THAT POOL.
 *
 * `pool_code` is NULLABLE and null is the existing meaning: a term that names no pool governs any
 * pool without one of its own. Every row written before today is null, so nothing an install
 * reconciles changes on deploy — what changes is that a per-pool cap becomes EXPRESSIBLE, and that
 * headroom stops leaking between pools (a term's headroom is scoped to the pools it governs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_cam_terms', function (Blueprint $table) {
            // No FK: `cam_expense_pools.pool_code` is not unique on its own (it is unique per
            // property and year), so this names a POOL CODE the way the pool itself does — the same
            // shape `CamExpensePool::pool_code` already is.
            $table->string('pool_code', 32)->nullable()->after('effective_year');
        });

        // ORDER MATTERS. MySQL satisfies `lease_id`'s foreign key from the LEFTMOST PREFIX of the
        // old unique index, so dropping it first fails with "needed in a foreign key constraint".
        // The new index also leads with `lease_id`, so creating it first hands the FK another index
        // to stand on and the old one becomes droppable.
        Schema::table('lease_cam_terms', function (Blueprint $table) {
            $table->unique(['lease_id', 'pool_code', 'effective_year'], 'lease_cam_terms_lease_pool_year_unique');
        });

        Schema::table('lease_cam_terms', function (Blueprint $table) {
            $table->dropUnique('lease_cam_terms_lease_id_effective_year_unique');
        });
    }

    public function down(): void
    {
        // Irreversible in the direction that matters: a lease carrying BOTH a portfolio-wide term
        // and a pool-specific one for the same year cannot be squeezed back into
        // unique(lease_id, effective_year) without deciding which cap to throw away — and throwing
        // away a cap silently bills a tenant in full. Refused rather than guessed.
        $conflicts = DB::table('lease_cam_terms')
            ->selectRaw('lease_id, effective_year, count(*) as c')
            ->groupBy('lease_id', 'effective_year')
            ->havingRaw('count(*) > 1')
            ->exists();

        if ($conflicts) {
            throw new RuntimeException(
                'Cannot roll back: a lease carries more than one CAM cap term for a year, and the old '
                .'unique index cannot hold both. Decide which cap survives before rolling back.'
            );
        }

        // Same ordering constraint in reverse.
        Schema::table('lease_cam_terms', function (Blueprint $table) {
            $table->unique(['lease_id', 'effective_year'], 'lease_cam_terms_lease_id_effective_year_unique');
        });

        Schema::table('lease_cam_terms', function (Blueprint $table) {
            $table->dropUnique('lease_cam_terms_lease_pool_year_unique');
            $table->dropColumn('pool_code');
        });
    }
};
