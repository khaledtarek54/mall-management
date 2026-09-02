<?php

use App\Support\StatementSection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The income statement learns where its NET OPERATING INCOME line goes.
 *
 * The P&L ran revenue − expenses = net profit with nothing in between, so the cost of cleaning the
 * mall sat in the same total as the interest on the loan secured against it and the depreciation of
 * its lifts. That is a general-ledger income statement; a PROPERTY income statement stops halfway
 * and states NOI, because a mall's value is roughly its NOI divided by a cap rate and every owner,
 * valuer and lender reads that subtotal first. Yardi, MRI and Entrata all print it.
 *
 * ## Backfilled from the prefixes, so no figure moves
 *
 * Every existing account is classified with the rules {@see StatementSection::forShippedChart()}
 * states, so the shipped chart keeps producing an identical bottom line — what changes is that the
 * statement can now show the subtotal, and the classification is visible and editable instead of
 * being inferred from how somebody numbered the chart.
 *
 * The prefix rule cannot survive contact with another operator's chart, which is the whole reason
 * this is a column: in the shipped chart `42101` Miscellaneous Income belongs inside NOI and
 * `42102` Gain on Disposal does not, and no rule reading `42` can tell those two apart.
 *
 * Balance-sheet accounts are left null: they have no result to place on an income statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table) {
            // Beside `cash_flow_section`, which answers the same shape of question for the other
            // statement — where does this account's movement belong.
            $table->string('statement_section', 16)->nullable()->after('cash_flow_section');
        });

        // Chunked for the same reason the cash-flow backfill is: a shipped chart is a few hundred
        // rows, and an operator importing the chart from the system they are leaving can bring
        // thousands.
        DB::table('ledger_accounts')->select('id', 'code', 'type')->orderBy('id')->chunk(500, function ($accounts) {
            foreach ($accounts as $account) {
                $section = StatementSection::forShippedChart((string) $account->code, (string) $account->type);

                if ($section !== null) {
                    DB::table('ledger_accounts')->where('id', $account->id)->update(['statement_section' => $section]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->dropColumn('statement_section');
        });
    }
};
