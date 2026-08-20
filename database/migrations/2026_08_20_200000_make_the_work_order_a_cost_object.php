<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The work order becomes a COST OBJECT — close-out step 2.
 *
 * ## The standard
 *
 * `docs/benchmarks/fm/01-maximo-work-and-asset.md` §4: a work order carries planned and actual cost
 * split by bucket, rolled up to the asset and the location. It is the single structural idea six of
 * the eight benchmark scenarios fail on, and every maintenance report in §11 of that file needs it.
 *
 * ## What was wrong
 *
 * `facility_work_orders` carried no cost at all. `job_value` exists solely to feed the SLA-penalty
 * percent-of-value basis. Parts posted through `StockMovement`, contractor work through
 * `VendorBill`, in-house wages through `Payroll` — **every figure was correctly in the ledger and
 * none of them could be attributed to the job, the machine or the shop.** So "what has this chiller
 * cost us", "what is our maintenance cost per m²" and "repair or replace" were unanswerable, and
 * in-house labour — captured NOWHERE — made internal work free on every report.
 *
 * ## `job_value` is replaced by `est_service_cost`, not kept beside it
 *
 * `job_value` was read by exactly one thing — the SLA percent-of-value penalty basis — and for an
 * external job it IS what the contractor will charge, i.e. the service estimate. Keeping both would
 * be two truths about one number, and nobody reading a penalty could tell which one it used.
 * Backfilled, then dropped; `AssessSlaPenaltyService` now reads the estimate and falls back to the
 * ACTUAL service cost once the bill has landed — which it could never do before, because the actual
 * did not exist.
 *
 * ## Three buckets, not Maximo's four, and that is a stated deviation
 *
 * Maximo splits labour · material · service · **tool**. A mall operator does not hold a tool
 * inventory: the scissor lift is hired, which arrives as a vendor bill or an expense and therefore
 * lands in `service`. Shipping an always-zero column would be a column nobody can use and a report
 * line nobody can read. Folded into service, and said so rather than implied.
 *
 * ## THE WORK ORDER IS NOT A GL SOURCE, and must never become one
 *
 * The money on a job is ALREADY posted, by three different documents that each own their entry:
 *
 *     material  → StockMovement      → InventoryMovementJournalizer
 *     service   → VendorBill/Expense → VendorBillJournalizer / ExpenseJournalizer
 *     labour    → Payroll            → PayrollJournalizer (as salaries_expense, in total)
 *
 * These columns are a **management dimension over already-posted money** — which job, which
 * machine, which trade consumed it. Adding `FacilityWorkOrder` to `LedgerPoster::JOURNALIZERS`
 * would post the same cost twice. `WorkOrderIsACostObjectNotAGlSourceTest` fails the build if
 * anyone tries.
 *
 * The same caution applies to reading: **a job's labour cost does not ADD to the wage bill, it
 * EXPLAINS part of it.** Summing payroll and work-order labour in one report double-counts.
 *
 * ## Estimates are nullable; actuals default to zero
 *
 * An actual is a roll-up and is always known — zero means nothing has been spent yet. An estimate
 * is a judgement nobody may have made, and `0` would be a claim that this job was expected to be
 * free. Planned-vs-actual is the point of the pair, so the difference between "not estimated" and
 * "estimated at nothing" has to survive.
 */
return new class extends Migration
{
    /**
     * Every step is guarded, so a re-run after a partial failure completes instead of dying on the
     * first column it already added.
     *
     * Not defensiveness for its own sake: MySQL does not roll DDL back, so a migration that alters
     * three tables and creates a fourth leaves a deploy stranded halfway if any step fails — and
     * the operator's only recovery is hand-written SQL against a production database. Learned by
     * doing exactly that on this one (2026-08-20).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('facility_work_orders', 'est_labour_hours')) {
            Schema::table('facility_work_orders', function (Blueprint $table) {
                // ---- Planned. Null = nobody estimated; see the class docblock. ----
                $table->decimal('est_labour_hours', 8, 2)->nullable()->after('priority');
                $table->decimal('est_labour_cost', 14, 2)->nullable()->after('est_labour_hours');
                $table->decimal('est_material_cost', 14, 2)->nullable()->after('est_labour_cost');
                $table->decimal('est_service_cost', 14, 2)->nullable()->after('est_material_cost');
                $table->decimal('est_total_cost', 14, 2)->nullable()->after('est_service_cost');

                // ---- Actual. Roll-ups, so zero is a true statement. ----
                $table->decimal('act_labour_hours', 8, 2)->default(0)->after('est_total_cost');
                $table->decimal('act_labour_cost', 14, 2)->default(0)->after('act_labour_hours');
                $table->decimal('act_material_cost', 14, 2)->default(0)->after('act_labour_cost');
                $table->decimal('act_service_cost', 14, 2)->default(0)->after('act_material_cost');
                $table->decimal('act_total_cost', 14, 2)->default(0)->after('act_service_cost');

                // "What has this machine cost this year" and "cost by trade" both scan these.
                $table->index(['asset_id', 'completed_at']);
                $table->index(['equipment_id', 'completed_at']);
            });
        }

        /**
         * Hours reported against a job — the primitive that did not exist.
         *
         * **Cost is a consequence of reporting time, never a number somebody types on the job.**
         * Nobody is asked "what did this cost"; they are asked "how long did it take and who did
         * it", which is a question a technician can answer truthfully. That is Maximo §5 and it is
         * the whole reason in-house work stops being free.
         */
        if (! Schema::hasTable('facility_work_order_labour')) {
            Schema::create('facility_work_order_labour', function (Blueprint $table) {
                $table->id();
                $table->foreignId('facility_work_order_id')->constrained()->cascadeOnDelete();

                // The CRAFT, which is where the rate comes from. Nullable and defaulted from the work
                // order's own trade: an electrician helping on an HVAC job is real, and forcing the
                // job's trade onto their hours would misreport both.
                $table->foreignId('trade_id')->nullable()->constrained()->nullOnDelete();

                // Who did the work. Nullable because a crew of three is often booked as one row of
                // hours by a supervisor, and refusing that would push people to book nothing.
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

                $table->date('worked_on');
                $table->decimal('hours', 6, 2);

                // **Frozen at entry, exactly like every other rate in this system.** A rise in the
                // trade's standard rate must not silently re-price work that was done last March.
                // Null when the trade has no rate — the hours are still recorded, and the cost is
                // visibly missing rather than invented.
                $table->decimal('hourly_rate', 12, 2)->nullable();
                $table->decimal('cost', 14, 2)->nullable();

                $table->string('notes', 255)->nullable();
                $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                // Named explicitly: Laravel's generated name would be
                // `facility_work_order_labour_facility_work_order_id_worked_on_index` — 65
                // characters, and MySQL's identifier limit is 64. SQLite does not enforce it, so
                // the whole suite passes and the FIRST real deploy fails on the index.
                $table->index(['facility_work_order_id', 'worked_on'], 'fwo_labour_order_date_index');
            });
        }

        // ---- `job_value` is REPLACED, not kept beside its successor ----
        //
        // It was the hand-typed value of the job, read by exactly one thing: the SLA
        // percent-of-value penalty basis. For an external job that number IS what the contractor
        // will charge — which is `est_service_cost`. Two columns holding the same figure is two
        // truths about one question, and the reader cannot tell which the penalty used.
        //
        // Backfilled first so no penalty basis loses its number, then dropped.
        if (Schema::hasColumn('facility_work_orders', 'job_value')) {
            DB::statement('update facility_work_orders set est_service_cost = job_value where job_value is not null');

            Schema::table('facility_work_orders', function (Blueprint $table) {
                $table->dropColumn('job_value');
            });
        }

        // ---- The service bucket: which job an AP document paid for ----
        //
        // Neither table could say. A contractor's invoice for fixing a chiller was correctly in
        // accounts payable and attributable to nothing, so the chiller's own cost history was
        // empty however many times it was repaired.
        if (! Schema::hasColumn('vendor_bills', 'facility_work_order_id')) {
            Schema::table('vendor_bills', function (Blueprint $table) {
                $table->foreignId('facility_work_order_id')->nullable()->after('purchase_request_id')
                    ->constrained()->nullOnDelete();
            });
        }

        // Petty-cash and direct costs reach a job the same way; leaving expenses out would make
        // the cost object true only for work that happened to be billed.
        if (! Schema::hasColumn('expenses', 'facility_work_order_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->foreignId('facility_work_order_id')->nullable()->after('asset_id')
                    ->constrained()->nullOnDelete();
            });
        }
    }

    /**
     * Reverses the SCHEMA exactly — round-tripped against the live database on 2026-08-20:
     * `est_service_cost` goes back into `job_value` with no mismatches, and forward again the same.
     *
     * **It cannot reverse the DATA this feature created.** Rolling back drops
     * `facility_work_order_labour` and both `facility_work_order_id` columns, so every hour anyone
     * booked and every job↔invoice attribution is gone and no backfill can reconstruct them. A
     * rollback after go-live means the maintenance cost history restarts from zero.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['facility_work_order_id']);
            $table->dropColumn('facility_work_order_id');
        });

        Schema::table('vendor_bills', function (Blueprint $table) {
            $table->dropForeign(['facility_work_order_id']);
            $table->dropColumn('facility_work_order_id');
        });

        Schema::dropIfExists('facility_work_order_labour');

        Schema::table('facility_work_orders', function (Blueprint $table) {
            $table->decimal('job_value', 14, 2)->nullable()->after('priority');
        });

        DB::statement('update facility_work_orders set job_value = est_service_cost where est_service_cost is not null');

        Schema::table('facility_work_orders', function (Blueprint $table) {
            $table->dropIndex(['asset_id', 'completed_at']);
            $table->dropIndex(['equipment_id', 'completed_at']);
            $table->dropColumn([
                'est_labour_hours', 'est_labour_cost', 'est_material_cost', 'est_service_cost',
                'est_total_cost', 'act_labour_hours', 'act_labour_cost', 'act_material_cost',
                'act_service_cost', 'act_total_cost',
            ]);
        });
    }
};
