<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lease's late-fee clause gains its ceiling (EG-35, finding M-8).
 *
 * `leases.late_fee_percent`, `late_fee_grace_days` and `late_fee_minimum` have been negotiable
 * since 2026-08-09. The cap was the one term of the same sentence that could not be stated — so
 * *"2% per month, minimum EGP 50, capped at EGP 5,000"* was two thirds expressible, and the
 * remaining third had to live in somebody's head.
 *
 * It matters more than the minimum does. A percentage of an arrears has **no upper bound**: a
 * tenant six months behind on a large invoice draws a penalty proportional to the size of the debt
 * rather than to the breach, and that is the figure a tenant disputes and an operator waives by
 * hand.
 *
 * Nullable, like its three siblings: null means "no clause of its own, ask the property then the
 * portfolio", and the portfolio ships **0 = no cap**, which is what every install did before this
 * column existed. So nothing moves on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->decimal('late_fee_maximum', 12, 2)->nullable()->after('late_fee_minimum');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('late_fee_maximum');
        });
    }
};
