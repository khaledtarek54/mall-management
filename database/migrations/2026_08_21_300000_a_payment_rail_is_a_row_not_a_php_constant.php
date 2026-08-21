<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Egypt's payment rails keep moving — Fawry, Meeza, Aman, Vodafone Cash — and `ValueSets`' own
 * docblock said so while keeping them in a PHP `const`. Adding one was a 9–14 file deploy across
 * two lang catalogues, two `->only()` filter lists and a hardcoded count in a test.
 *
 * Worse, there were FOUR parallel lists that had already drifted: `payments.method` (7),
 * `vendor_bill_payments.method` (5), `deposit_transactions.method` + `expenses.paid_from` (2 each),
 * and `Disbursement::METHODS` (3) outside `ValueSets` entirely. A security deposit received by
 * InstaPay could not be recorded as InstaPay.
 *
 * One catalogue now, shaped like `charge_codes`: a row, a bilingual name, a posting role, and a
 * direction. `posting_role` is what closes the real defect — every non-cash rail debited one `bank`
 * account on CAPTURE day while the money lands T+1/T+2 (longer for Fawry), so the bank
 * reconciliation would show a gross unmatched population every month. Null means "use the floor",
 * which reproduces today's behaviour exactly, so nothing moves until an operator points a rail at a
 * clearing account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();

            // The value the money documents already store, so no data migration is needed.
            $table->string('code', 32)->unique();

            $table->string('name_en', 64);
            $table->string('name_ar', 64);

            // The GL account this rail's money lands in — the chart row DIRECTLY, the way
            // `bank_accounts.ledger_account_id` already does, not a `PostingRoles` key.
            //
            // A posting ROLE would have been the wrong shape here and it is worth saying why, because
            // the role pattern is right nearly everywhere else in this system. A role exists so a
            // CODE PATH can ask for "the bank account" without knowing the chart. A rail is operator
            // data pointing at operator data — there is no code path that wants "the Fawry account"
            // by name. Worse, `Health::accountingReadiness()` requires EVERY `PostingRoles` key to be
            // mapped, so adding a clearing role per rail would turn a BLOCKING health row red on
            // every existing install until the accountant mapped them. And two rails could never
            // have two different clearing accounts without two more roles.
            //
            // NULL is the normal state and means "take the floor" — `cash` for cash, `bank` for
            // everything else, which is exactly what the journalizers hard-coded. So this ships
            // behaviour-identical and an operator opts in per rail.
            $table->foreignId('ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();

            // Which direction a rail can be used in. Cash and bank transfer work both ways; a
            // collection network is inbound; a payroll disbursement rail is outbound. This is what
            // lets ONE catalogue serve four columns without offering nonsense on either side.
            $table->boolean('for_inbound')->default(true);
            $table->boolean('for_outbound')->default(true);

            // How many days after capture the money is expected to actually land. Informational
            // today — it is what a future settlement-ageing report reads — and the reason a
            // clearing account is needed at all.
            $table->unsignedSmallInteger('settlement_days')->default(0);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'for_inbound']);
            $table->index(['is_active', 'for_outbound']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
