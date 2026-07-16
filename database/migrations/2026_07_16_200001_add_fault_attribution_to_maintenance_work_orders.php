<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fault attribution + cost bearer on a work order (FR-CM-12, FR-CM-13).
 *
 * The FRD, verbatim:
 *   FR-CM-12 — "For parts sourced from outside, the system shall **determine responsibility**
 *               (and who bears the cost) based on **who caused the part to fail, as recorded on
 *               the work order**."
 *   FR-CM-13 — "The system shall **record** whether the mall or the tenant is financially
 *               responsible for a repair, based on who caused the damage."
 *
 * Note the verbs: *determine* and *record*. **No requirement anywhere in the FRD asks the system
 * to invoice, bill, or recharge a tenant**, and its own "Open Items" list never raises it. So this
 * records the finding and derives the bearer — and stops there. Recharging is a separate,
 * unbuilt seam pending the client (BUSINESS-RULES open question 14). Nothing here can bill anyone.
 *
 * Columns live on the work order rather than in their own table because FR-CM-12 says the cause is
 * "as recorded on the work order" — one job has exactly one finding about what caused it. A child
 * table would imply many, and invite the question of which one counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            // WHO caused it (FR-CM-12/13's shared input). Null = not yet determined; a job whose
            // cause nobody has ruled on is the normal state while the work is still going on.
            $table->string('fault_party', 30)->nullable()->after('job_value');

            // WHO pays (FR-CM-13) — mall | tenant. Derived from fault_party, overridable with a
            // reason. Stored rather than computed: it is a commercial decision that must survive
            // someone later editing the derivation rules.
            $table->string('cost_bearer', 10)->nullable()->after('fault_party');

            // Provenance. This record is an assertion that a real tenant owes real money later, so
            // it has to survive an argument: who said so, when, and on what evidence.
            $table->text('fault_notes')->nullable()->after('cost_bearer');
            $table->foreignId('fault_recorded_by_user_id')->nullable()->after('fault_notes')
                ->constrained('users')->nullOnDelete();
            $table->dateTime('fault_recorded_at')->nullable()->after('fault_recorded_by_user_id');

            // "Which jobs did tenants cause this month, and on which property?" — the report the
            // operator actually wants out of FR-CM-13.
            $table->index(['asset_id', 'cost_bearer'], 'mwo_asset_cost_bearer_index');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            $table->dropIndex('mwo_asset_cost_bearer_index');
            $table->dropConstrainedForeignId('fault_recorded_by_user_id');
            $table->dropColumn(['fault_party', 'cost_bearer', 'fault_notes', 'fault_recorded_at']);
        });
    }
};
