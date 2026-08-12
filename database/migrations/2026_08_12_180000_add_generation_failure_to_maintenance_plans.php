<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A preventive plan that cannot generate says so on its own row.
 *
 * `GeneratePreventiveWorkOrdersService` contains a failure per plan — correctly, so one bad row
 * cannot stop every other property getting its work orders — and the whole cycle rolls back
 * together, `advanceDue()` included. That is also correct: a statutory round must not be skipped
 * just because tonight's attempt failed. But the two together mean the plan retries the same cycle
 * every night forever, and the only trace was a `Log::warning` plus a non-zero exit code from a
 * cron job with no `onFailure` hook. **The lift inspection stops and nobody is told.**
 *
 * The stamp lives on the plan because that is where somebody would look. A notification is seen
 * once; a badge on the row it concerns is there whenever the question is asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_plans', function (Blueprint $table) {
            $table->timestamp('last_generation_failed_at')->nullable()->after('last_generated_at');
            $table->string('last_generation_error', 500)->nullable()->after('last_generation_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_plans', function (Blueprint $table) {
            $table->dropColumn(['last_generation_failed_at', 'last_generation_error']);
        });
    }
};
