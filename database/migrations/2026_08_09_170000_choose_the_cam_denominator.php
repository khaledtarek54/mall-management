<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The share denominator becomes a term, not a convention (story RC-03).
 *
 * **What was wrong.** The denominator was hard-coded to the summed area of the leases that happen
 * to be active — "occupied area". That is one of at least three bases real leases specify, and it
 * has a particular consequence: it recovers 100% of the pool from whoever is trading, so **a
 * half-empty mall bills its remaining tenants for the whole service charge**. Some leases say
 * exactly that; many say the opposite (share of GROSS leasable area, landlord bears the vacancy);
 * and a good number simply state the tenant's percentage outright.
 *
 * Three pool bases and one per-lease override:
 *
 * - `occupied` — the legacy behaviour and the **column default**, so no existing pool changes.
 * - `gla` — the property's gross leasable area, so vacancy sits with the landlord where the lease
 *   says it should. (Grossing that back up to an occupancy assumption is RC-04, still to come.)
 * - `fixed` — a stated m² figure, for a pool whose denominator is contractually pinned.
 * - `lease_cam_terms.stated_share_pct` — a lease whose contract names its percentage directly,
 *   which is common in Egyptian leases and which no basis can derive.
 *
 * **`landlord_unrecovered_amount` is what keeps the books honest.** `Σ allocated_amount =
 * total_actual_expense` is a hard tie-out in `BooksReconciliationService`, and it silently encodes
 * "the pool is always fully recovered" — true only under `occupied`. Under `gla` the shares
 * deliberately sum to less than 100%. Rather than loosen the check, the unrecovered remainder is
 * STORED, and the tie-out becomes `Σ allocated + unrecovered = total`. It is 0.00 for every
 * existing pool, so the check is byte-identical where nothing changed — and the landlord's share of
 * its own vacancy becomes a number on a screen instead of drift in a report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cam_expense_pools', function (Blueprint $table) {
            $table->string('denominator_basis')->default('occupied')->after('estimate_basis');
            $table->decimal('denominator_fixed_sqm', 12, 2)->nullable()->after('denominator_basis');
            $table->decimal('denominator_used_sqm', 12, 2)->nullable()->after('denominator_fixed_sqm');
            $table->decimal('landlord_unrecovered_amount', 14, 2)->default(0)->after('denominator_used_sqm');
        });

        Schema::table('lease_cam_terms', function (Blueprint $table) {
            // A share the contract names outright. Nullable — almost every lease derives its share
            // from area, and inventing a percentage for those would be worse than deriving one.
            $table->decimal('stated_share_pct', 7, 4)->nullable()->after('cap_type');
        });
    }

    public function down(): void
    {
        Schema::table('cam_expense_pools', function (Blueprint $table) {
            $table->dropColumn([
                'denominator_basis', 'denominator_fixed_sqm',
                'denominator_used_sqm', 'landlord_unrecovered_amount',
            ]);
        });

        Schema::table('lease_cam_terms', function (Blueprint $table) {
            $table->dropColumn('stated_share_pct');
        });
    }
};
