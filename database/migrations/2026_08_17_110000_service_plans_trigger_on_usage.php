<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A preventive plan can fire on USAGE, not only on the calendar.
 *
 * Every plan in module 26 was time-driven: `frequency_unit` × `frequency_value` from
 * `next_due_date`. That is right for a statutory round ("fire extinguishers, annually") and wrong
 * for the machines this mall actually runs. A chiller, a lift and a generator are serviced on
 * **running hours** — "every 500 hours" — because a genset idle for six months needs nothing and
 * one running double shifts needs servicing twice as often. A calendar plan gets both wrong in
 * opposite directions: it over-services the idle machine and under-services the hard-worked one,
 * which is the failure the interval exists to prevent.
 *
 * ## Usage is read from a METER, because the system already counts things
 *
 * `meter_readings.reading_value` is a CUMULATIVE counter and `utility_meters` already carries the
 * per-property register, the operator's reading workflow, the import path and the property scoping.
 * A second "equipment runtime" table would duplicate all of it to hold the same shape of number.
 * So a usage plan points at a meter, and its trigger is the DELTA since the plan last generated.
 *
 * `utility_meters.type` gains `hours` in the same change (a one-line `ValueSets` edit now that no
 * enum survives in the DDL — this is exactly what freeing those columns bought). An hours meter is
 * monitored and never recharged, which the recharge path already handles: no tariff and no override
 * resolves to 0, and `BillMeterReadingService` refuses a zero-cost recharge.
 *
 * ## `trigger_type` is an XOR, deliberately
 *
 * Real CMMS products offer "every 500 hours OR every 12 months, whichever comes first", and that is
 * a genuine pattern. It is NOT built here, because it is a third mode with its own reset semantics
 * (does the calendar clock restart when the usage trigger fires?) and nobody has asked which answer
 * they want. Guessing it into the schema is what this module's own doc warns against on FR-PPM-01.
 *
 * The shape stays open: `trigger_type` is a string in `ValueSets`, so "whichever comes first"
 * arrives as a third value and one branch in the generator — no migration, no backfill.
 *
 * ## Backward compatible by construction
 *
 * `trigger_type` defaults to `time`, and `ServicePlan::scopeDue()` now filters on it. Every existing
 * plan keeps generating exactly as before. **That filter is the load-bearing line**: without it a
 * usage plan would still carry a NOT-NULL `next_due_date`, still match the time scope, and fire on
 * BOTH triggers — raising two work orders for one service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_plans', function (Blueprint $table) {
            // time | usage — see the class docblock for why this is an XOR and how it opens up.
            $table->string('trigger_type', 16)->default('time')->after('plan_type');

            // The counter this plan watches. Null for a time plan, required for a usage one
            // (enforced on the model + the form, not by the column, because an existing time plan
            // must stay valid).
            $table->foreignId('utility_meter_id')
                ->nullable()
                ->after('trigger_type')
                ->constrained()
                ->nullOnDelete();

            // The delta that raises the job — 500 (hours), 100000 (kWh). In the METER's unit, which
            // is why the form shows that unit as the suffix rather than naming one here.
            $table->decimal('usage_threshold', 14, 2)->nullable()->after('utility_meter_id');

            // The reading the plan was last serviced at. The baseline the delta is measured from —
            // seeded when the plan starts watching a meter, advanced each time it generates.
            //
            // Seeded rather than left null-means-zero: a meter installed years ago reads 40,000
            // hours, and measuring the first delta from zero would raise eighty overdue work orders
            // on the first nightly run.
            $table->decimal('usage_at_last_generation', 14, 2)->nullable()->after('usage_threshold');

            // The scan's own filter: active usage plans, cheapest first.
            $table->index(['is_active', 'trigger_type']);
        });
    }

    public function down(): void
    {
        Schema::table('service_plans', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'trigger_type']);
            $table->dropConstrainedForeignId('utility_meter_id');
            $table->dropColumn(['trigger_type', 'usage_threshold', 'usage_at_last_generation']);
        });
    }
};
