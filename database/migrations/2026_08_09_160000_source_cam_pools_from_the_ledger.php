<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The recovery pool stops being two numbers somebody typed (stories RC-01 and RC-05).
 *
 * **What was wrong.** `total_actual_expense` and `total_estimated_collected` were hand-keyed. The
 * actual was re-keyed from a spreadsheet somebody built from the vendor bills, so no tenant charge
 * could drill through to the invoices behind it and any transcription slip billed the whole mall.
 * The estimated was a single portfolio figure sliced pro-rata — meaning `estimated_paid` on an
 * allocation was **not what that tenant actually paid**, it was their share of a number a human
 * kept roughly equal to what had been billed.
 *
 * Both become derivable:
 *
 * - `expense_basis = ledger` sums POSTED journal lines on the accounts attached to the pool, for
 *   the pool's own property and year. `cam_pool_accounts` is that attachment.
 * - `estimate_basis = billed` sums what each lease was actually invoiced as service charge in the
 *   year, so the estimate reconciled is the estimate billed, by construction.
 *
 * **Both columns default to `stated`**, the legacy hand-keyed behaviour, so no existing pool
 * changes basis and no reconciled year is restated. New pools are created on the derived bases by
 * the form. The derived total is WRITTEN to `total_actual_expense` rather than queried live: a late
 * journal entry posted after a reconciliation must not silently restate allocations that have
 * already been billed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cam_expense_pools', function (Blueprint $table) {
            // String, not a DB enum — the house rule.
            $table->string('expense_basis')->default('stated')->after('total_estimated_collected');
            $table->string('estimate_basis')->default('stated')->after('expense_basis');
            $table->timestamp('expense_synced_at')->nullable()->after('estimate_basis');
        });

        Schema::create('cam_pool_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cam_expense_pool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['cam_expense_pool_id', 'ledger_account_id'], 'cam_pool_account_unique');
        });

        Schema::table('cam_allocations', function (Blueprint $table) {
            // Next year's monthly estimate this reconciliation proposes (RC-05), and the date it
            // was accepted onto the lease's charge schedule. Nullable: proposing is not applying,
            // and an operator who disagrees with the proposal simply never accepts it.
            $table->decimal('proposed_monthly_estimate', 12, 2)->nullable()->after('cap_absorbed_amount');
            $table->timestamp('estimate_applied_at')->nullable()->after('proposed_monthly_estimate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cam_pool_accounts');

        Schema::table('cam_expense_pools', function (Blueprint $table) {
            $table->dropColumn(['expense_basis', 'estimate_basis', 'expense_synced_at']);
        });

        Schema::table('cam_allocations', function (Blueprint $table) {
            $table->dropColumn(['proposed_monthly_estimate', 'estimate_applied_at']);
        });
    }
};
