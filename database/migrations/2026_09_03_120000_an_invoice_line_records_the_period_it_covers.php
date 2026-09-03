<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **WHICH SERVICE MONTHS A LINE PAID FOR — the fact the billing run inferred and never wrote down.**
 *
 * `MonthlyBillingService::coveredWindow()` has always known it: an advance row covers the invoice's
 * own period, an arrears row covers the previous cycle, and on the LAST cycle an arrears row covers
 * both because no later invoice will exist. That window decided the amount, the proration and the
 * label — and then it was thrown away. It survived only as English inside `description`
 * ("Service Charge - Jul-Aug 2026 (in arrears)"), which nothing can query.
 *
 * So the run's only defence against billing a month twice, `alreadyBilledForMonth()`, keys on the
 * INVOICE's own period rather than on the months the LINES covered. Those are different questions,
 * and the day they diverge is the day a lease continues past a final settle: the August invoice
 * covers July–August as the final cycle, the lease is converted to holdover or simply extended, and
 * the September invoice covers August again. Aug 1–31 against Sep 1–30 — no overlap, nothing
 * refuses, and both documents read plausibly on their own ("Jul-Aug 2026", then "Aug 2026").
 *
 * **Null is the normal state for every row written before today**, and it means *not recorded*
 * rather than *covers nothing*: the clamp in the planner skips a null, so no historical invoice is
 * re-interpreted and nothing an install has already billed changes. A backfill was considered and
 * refused — the honest source for a legacy row is prose, and guessing the period from the invoice
 * would be right for advance rows and wrong for exactly the arrears rows this exists to protect,
 * which could SUPPRESS a legitimate bill. Losing a month of revenue is worse than the duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->date('covered_start')->nullable()->after('charge_id');
            $table->date('covered_end')->nullable()->after('covered_start');

            // The clamp asks "what is the latest month already covered for THIS charge", so the
            // index leads on the charge and orders by the end of the window.
            $table->index(['charge_id', 'covered_end'], 'invoice_items_charge_covered_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropIndex('invoice_items_charge_covered_idx');
            $table->dropColumn(['covered_start', 'covered_end']);
        });
    }
};
