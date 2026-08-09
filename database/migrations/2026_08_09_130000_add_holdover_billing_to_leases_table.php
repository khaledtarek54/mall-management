<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holdover becomes billable (story LE-04, scenario S9).
 *
 * **What was wrong.** A lease past its expiry date but still occupied — a holdover — kept its unit
 * marked occupied and appeared on the ActionRequired dashboard, and billed **nothing**:
 * `isBillableForPeriod()` refuses any period starting after expiry. So a held-over tenant traded
 * rent-free, in the mall's own space, until somebody renewed or terminated them. The alert was the
 * end of the story rather than the prompt for an action.
 *
 * Two columns, because holdover has two facts: the agreed multiple of the last rent (typically
 * 150% in Egyptian commercial practice, and a deterrent by design), and the date the parties
 * actually continued from. `holdover_from` being NULL is what keeps every existing lease behaving
 * exactly as it does today — **nothing converts itself**. An operator confirms, because "they are
 * still in the unit" is a fact only a human knows, and inventing rent for a tenant who has already
 * moved out would be worse than billing nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->decimal('holdover_rate_pct', 6, 2)->nullable()->after('expiry_date');
            $table->date('holdover_from')->nullable()->after('holdover_rate_pct');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn(['holdover_rate_pct', 'holdover_from']);
        });
    }
};
