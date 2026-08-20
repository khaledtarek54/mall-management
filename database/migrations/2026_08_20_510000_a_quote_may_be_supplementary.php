<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quote is either the whole price, or EXTRA on top of one already agreed.
 *
 * Found reviewing step 6, on the live database. Approving a second quote replaced the job's estimate
 * — which is right for a REVISED price and silently wrong for a SUPPLEMENT, the ordinary case where
 * a contractor opens a wall, finds more work, and quotes for the extra:
 *
 *     approved 38,000  → ceiling 38,000, estimate 38,000
 *     supplement 8,000 → ceiling 38,000 (the max() ignored it)
 *                        estimate 8,000  ← the job now reads as 38,000 overspent
 *
 * Worse than unsupported: it corrupts the figure planned-vs-actual is measured against, and nothing
 * about the screen told the operator they were replacing rather than adding.
 *
 * So the quote says which it is. A full quote REPLACES (the whole price, revised) and a
 * supplementary one ADDS to both the ceiling and the estimate. Defaulting to full because that is
 * the normal case, and because a supplement that was meant to be a revision overstates the
 * authorisation, which is the safer direction to be wrong in — it is visible as a ceiling nobody
 * recognises, where the reverse was invisible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_proposals', function (Blueprint $table) {
            $table->boolean('is_supplementary')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_proposals', function (Blueprint $table) {
            $table->dropColumn('is_supplementary');
        });
    }
};
