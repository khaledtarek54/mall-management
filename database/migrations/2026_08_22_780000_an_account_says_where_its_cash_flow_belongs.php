<?php

use App\Support\CashFlowSection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The cash-flow statement stops classifying by literal code prefixes (EG-28, finding S-4).
 *
 * It read `111`, `121`, `122`, `12`, `22` and `222` off the account code, so it was correct only
 * about the chart this project happens to ship. The failure mode is the dangerous one: a different
 * Egyptian chart numbered 1–5 by nature but with different sub-ranges **saves fine** — the save-time
 * guard only checks the leading digit — and then silently misclassifies every cash flow. Nothing
 * errors, the statement still balances, and the figures are wrong.
 *
 * It matters now rather than hypothetically: the operator's real chart is still pending, and the
 * one supplied so far is recorded in `docs/accounting/` as a dummy template.
 *
 * ## Backfilled from the prefixes, so today's numbers do not move
 *
 * Every existing account is classified here using exactly the rules the report used, in exactly the
 * order it used them ({@see CashFlowSection::forShippedChart()}). So the shipped chart produces an
 * identical statement, and what changes is that the classification is now VISIBLE, editable, and no
 * longer inferred from how somebody numbered the chart.
 *
 * Revenue and expense accounts are left null on purpose: they net into `net_income` by TYPE, which
 * is already chart-agnostic. Giving them a section would let an operator move revenue into
 * investing and break the statement's own arithmetic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->string('cash_flow_section', 16)->nullable()->after('type');
        });

        // Chunked: a real chart is a few hundred rows, but an operator importing one from the system
        // they are leaving can bring thousands, and a single query per row is what makes an install
        // feel broken.
        DB::table('ledger_accounts')->select('id', 'code', 'type')->orderBy('id')->chunk(500, function ($accounts) {
            foreach ($accounts as $account) {
                $section = CashFlowSection::forShippedChart((string) $account->code, (string) $account->type);

                if ($section !== null) {
                    DB::table('ledger_accounts')->where('id', $account->id)->update(['cash_flow_section' => $section]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->dropColumn('cash_flow_section');
        });
    }
};
