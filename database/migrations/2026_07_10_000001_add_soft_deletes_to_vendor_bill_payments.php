<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give vendor_bill_payments soft-deletes so a deleted payment self-heals its GL:
 * the `accounting:sync-ledger` sweep voids the journal entry of a *trashed* source
 * (it visits withTrashed rows), but a HARD delete leaves no row to reconcile and
 * orphans the payment's entry (an overstated cash/AP movement on the books).
 * Every other posting source already soft-deletes; this closes the one gap.
 * (GL integrity hardening — Phase 0, F7.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_bill_payments', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_bill_payments', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
