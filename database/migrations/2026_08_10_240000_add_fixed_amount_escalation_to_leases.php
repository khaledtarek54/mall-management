<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escalation by a fixed AMOUNT — the last open half of gap-analysis row 61.
 *
 * Yardi stores an escalation as a percentage, a fixed amount, or an index. Atriom had the
 * percentage (`escalation_rate`) and a deliberately-unimplemented index (`cpi`, skipped by the sweep
 * because inventing an index number would be inventing data). The amount was simply missing, and it
 * is the one of the three that needs no external feed at all: *"rent increases by EGP 5,000 per
 * month each year"* is an ordinary Egyptian anchor-tenant term, and until now it could only be
 * honoured by an operator remembering to raise the rent by hand every anniversary — which is exactly
 * the revenue leak `RentEscalationService` was built to close for percentage leases.
 *
 * Nullable rather than defaulted to zero: zero is a legitimate amount to escalate by (it just does
 * nothing), whereas "no amount recorded" is what a percentage lease has. `escalation_rate` keeps its
 * own default and meaning; the two never both apply, because `escalation_type` picks one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            // Monthly rent money, so it matches base_rent_monthly's precision rather than the
            // rate's decimal(5,2) — an increase of EGP 12,500.00 must be expressible.
            $table->decimal('escalation_amount', 14, 2)->nullable()->after('escalation_rate');
        });

        // `escalation_type` was created as a DB-LEVEL ENUM in 2024 — `enum('none','fixed_percent',
        // 'cpi')` — which predates the project's "never `$table->enum(...)`" rule and is exactly why
        // that rule exists: adding a value needs a migration, and the failure is invisible in tests.
        // SQLite (`:memory:`, what the suite runs on) does not enforce enum membership, so a
        // `fixed_amount` lease saved and read back perfectly green; MySQL — local and production —
        // would have REJECTED the write, or silently stored an empty string in non-strict mode.
        // A whole feature would have passed CI and failed on the first real save.
        //
        // Converted to a string rather than widened to a four-value enum, so the next escalation
        // kind is a code change and not a schema change. Validation lives in the model + form, per
        // the convention.
        Schema::table('leases', function (Blueprint $table) {
            $table->string('escalation_type', 32)->default('none')->change();
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->enum('escalation_type', ['none', 'fixed_percent', 'cpi'])->default('none')->change();
            $table->dropColumn('escalation_amount');
        });
    }
};
