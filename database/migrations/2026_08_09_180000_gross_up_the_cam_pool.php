<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gross-up (story RC-04).
 *
 * **The problem it solves.** RC-03 gave pools a GLA denominator, so vacancy stays with the
 * landlord. But that over-corrects on the VARIABLE half of the pool: a mall at 40% occupancy spends
 * less on cleaning and common-area utilities than a full one, and its trading tenants consume those
 * services at full intensity. Left alone, they pay 40% of a deflated number — less than they would
 * in a busy mall — and the landlord subsidises their consumption on top of its own vacancy.
 *
 * Gross-up scales the variable portion up to an occupancy ASSUMPTION (typically 95%) before
 * apportioning, so a tenant pays what they would pay in a full centre. Fixed costs are not grossed:
 * a security contract costs the same empty or full, and the vacancy share of it is genuinely the
 * landlord's.
 *
 * **Only meaningful when the denominator includes vacancy.** Under `occupied` the shares already
 * sum to 100%, so grossing up would charge tenants MORE than the landlord actually spent. The
 * service refuses to apply it there rather than quietly over-recovering.
 *
 * **`cost_nature` on the pivot defaults to FIXED, not variable** — the opposite of
 * `App\Support\CostNature`'s default, deliberately. There, "variable" is the conservative reading
 * of an unclassified cost (it is not treated as a committed obligation). Here the same word is the
 * AGGRESSIVE one: it grosses the account up and charges tenants more. An unclassified account must
 * not quietly raise everyone's bill the day gross-up is switched on.
 *
 * All three columns are nullable / defaulted so every existing pool is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cam_expense_pools', function (Blueprint $table) {
            // The occupancy the variable portion is grossed up to, as a percentage. NULL = no
            // gross-up at all, which is every pool that exists today.
            $table->decimal('gross_up_pct', 5, 2)->nullable()->after('landlord_unrecovered_amount');
            // The variable share of the pool, for a pool whose expense was typed rather than
            // sourced from accounts. NULL on a ledger pool, where it is derived per account.
            $table->decimal('variable_pct', 5, 2)->nullable()->after('gross_up_pct');
            // The apportionment basis actually used, after grossing. Stored, like every other
            // figure a tenant statement quotes, so it can be replayed rather than re-derived.
            $table->decimal('grossed_up_expense', 14, 2)->nullable()->after('variable_pct');
        });

        Schema::table('cam_pool_accounts', function (Blueprint $table) {
            $table->string('cost_nature')->default('fixed')->after('ledger_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('cam_expense_pools', function (Blueprint $table) {
            $table->dropColumn(['gross_up_pct', 'variable_pct', 'grossed_up_expense']);
        });

        Schema::table('cam_pool_accounts', function (Blueprint $table) {
            $table->dropColumn('cost_nature');
        });
    }
};
