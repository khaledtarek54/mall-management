<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FR-PPM-07: a checklist item must be marked **pass/fail** — not merely "checked" —
 * before its work order can close, and FR-CM-01 raises corrective maintenance from a
 * *failed* item. The old `is_done` boolean could not express failure: a failed
 * inspection was indistinguishable from a passed one.
 *
 * `result` REPLACES `is_done` rather than sitting beside it — two columns encoding the
 * same fact inevitably drift. `is_done` survives as a derived accessor on the model.
 *
 * `done_at`/`done_by_user_id` become `marked_at`/`marked_by_user_id`: "done" reads wrong
 * next to `result = fail`, and it collided with the work order's own `completed_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_work_order_items', function (Blueprint $table) {
            $table->enum('result', ['pending', 'pass', 'fail'])
                ->default('pending')
                ->after('label');
        });

        // Backfill before dropping the source column. A ticked item was an item that
        // passed — the only reading available, since failure was previously unrecordable.
        DB::table('maintenance_work_order_items')->where('is_done', true)->update(['result' => 'pass']);

        Schema::table('maintenance_work_order_items', function (Blueprint $table) {
            $table->dropColumn('is_done');
            $table->renameColumn('done_at', 'marked_at');
            $table->renameColumn('done_by_user_id', 'marked_by_user_id');
        });

        Schema::table('maintenance_work_order_items', function (Blueprint $table) {
            // The completion gate counts unmarked items per order on every complete()
            // and renders a progress badge per row on the list.
            $table->index(['maintenance_work_order_id', 'result'], 'mwoi_order_result_index');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_work_order_items', function (Blueprint $table) {
            $table->dropIndex('mwoi_order_result_index');
            $table->renameColumn('marked_at', 'done_at');
            $table->renameColumn('marked_by_user_id', 'done_by_user_id');
            $table->boolean('is_done')->default(false)->after('label');
        });

        // Both pass and fail were "checked" under the old boolean.
        DB::table('maintenance_work_order_items')->whereIn('result', ['pass', 'fail'])->update(['is_done' => true]);

        Schema::table('maintenance_work_order_items', function (Blueprint $table) {
            $table->dropColumn('result');
        });
    }
};
