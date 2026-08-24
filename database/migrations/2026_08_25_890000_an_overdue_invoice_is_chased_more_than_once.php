<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many times this invoice has been chased — the dunning ladder (1A-16).
 *
 * **The gap.** `billing:remind-overdue-tenants` filtered on `whereNull(tenant_overdue_notified_at)`
 * and stamped it once, so **every overdue invoice reminded its tenant exactly once, for its whole
 * life**. After that first email nothing chased anybody: no second notice, no final demand, no
 * record of how often a tenant had been asked. In a market this codebase's own importer describes as
 * arrears-chasing-first, that is the daily job of the people who will use this system, done by hand.
 *
 * The WORDING half already shipped (EG-15 slice 2 — `dunning.overdue_reminder` and its subject are
 * operator-editable per property, in both languages). What was missing was the CADENCE and the
 * COUNT, which is what this column and the two settings beside it add.
 *
 * **Nothing moves on deploy.** `BillingSettings::dunning_followup_days` ships **0**, which means
 * "chase once" — byte-identical to today's behaviour — exactly as `late_fee_recurrence_days` ships 0
 * for the same reason. Repeat chasing is a commercial decision about tone with tenants an operator
 * has to keep, not a switch to flip on their behalf.
 *
 * `dunning_level` is the notice NUMBER (0 = never chased, 1 = first reminder sent, …). Paired with
 * the existing `tenant_overdue_notified_at` — the date of the LAST notice — those two columns answer
 * "how many times, and when last?", which is the whole of the notice history a collections
 * conversation actually needs. A separate history table would record the same two facts per row and
 * be a second place for them to disagree.
 *
 * It resets to 0 whenever the invoice stops being overdue-and-unpaid, so a tenant who falls behind
 * again next quarter starts at a first reminder rather than a final demand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedTinyInteger('dunning_level')
                ->default(0)
                ->after('tenant_overdue_notified_at')
                ->comment('How many overdue notices have been sent for this invoice (0 = none)');
        });

        // Every invoice already chased is at notice 1 — the stamp is the evidence. Without this an
        // install that switches the cadence on would send a "first reminder" to every tenant it had
        // already written to.
        Schema::getConnection()
            ->table('invoices')
            ->whereNotNull('tenant_overdue_notified_at')
            ->update(['dunning_level' => 1]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('dunning_level');
        });
    }
};
