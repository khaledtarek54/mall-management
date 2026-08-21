<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which clock this job's SLA was promised on — frozen when the deadline was stamped.
 *
 * The alternative was to resolve it at read time, and that would have been a money bug. A PENDING
 * SLA penalty is recomputed and rewritten on every hourly scan (`AssessSlaPenaltyService::assess()`
 * returns early only for a non-pending one), and `SlaPenalty.amount` is DERIVED in
 * `App\Support\ChangeImpact`, so its posted journal entry is void-and-reposted when it changes. An
 * operator flipping the setting would therefore have re-priced every job in flight and moved the
 * books under them — against the rule the penalty path already keeps, that a vendor is judged by
 * the terms in force when the job ran.
 *
 * Frozen for the same reason `facility_work_order_labour` freezes the craft rate at entry.
 *
 * **Nullable, and null means the calendar.** Not a NOT-NULL column with a default: `FacilityWorkOrder`
 * carries model-level `$attributes` defaults, and a default there would make the `??=` in
 * `stampSlaClocks()` never fire — the feature would ship stamped `calendar` for ever with no test
 * able to notice. Null is also the honest value for the population that never gets one: a PPM order
 * has no SLA clock at all, because `stampSlaClocks()` returns early for anything non-corrective.
 *
 * No backfill. Every existing order was promised on the calendar and must keep being measured that
 * way; null already says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facility_work_orders', function (Blueprint $table) {
            $table->string('sla_clock', 16)->nullable()->after('target_resolution_at');
        });
    }

    public function down(): void
    {
        Schema::table('facility_work_orders', function (Blueprint $table) {
            $table->dropColumn('sla_clock');
        });
    }
};
