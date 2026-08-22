<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **An escalation clause states its own interval** — EG-30 (M-6).
 *
 * `RentEscalationService` rolled the next step with a literal `->addYear()`, and no column existed
 * to say otherwise. So a biennial clause, an 18-month step, or the six-monthly review an Egyptian
 * operator writes into a short fit-out lease could not be automated at all: the sweep either
 * escalated it every year (wrong) or the operator turned escalation off and did it by hand (which
 * is what actually happens, and is how a step gets missed).
 *
 * ## Null is the normal state, and means twelve
 *
 * Nullable with no default, exactly as `charges.vat_rate` and `bank_accounts.ledger_account_id`
 * are: every existing lease keeps escalating annually, the sweep behaves identically on deploy, and
 * an operator opts one clause at a time into something else. A `default(12)` would have been the
 * same arithmetic and a worse record — it would claim every lease in the table had been ruled on,
 * when none of them had.
 *
 * Months rather than a `{annual|biennial|…}` set, because the set is not bounded: 6, 12, 18, 24 and
 * 36 are all ordinary, and a classification column would need a `ValueSets` entry that grows every
 * time a lawyer writes a new clause. It is a count, so the guard is arithmetic (≥ 1) rather than
 * membership — see `Lease::escalationIntervalMonths()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            // Unsigned small: 12 is the common case and 36 the longest anyone writes; the model
            // clamps anything absurd rather than the column refusing it, because a refusal here
            // surfaces as a save that fails with no field to point at.
            $table->unsignedSmallInteger('escalation_interval_months')
                ->nullable()
                ->after('escalation_type');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('escalation_interval_months');
        });
    }
};
