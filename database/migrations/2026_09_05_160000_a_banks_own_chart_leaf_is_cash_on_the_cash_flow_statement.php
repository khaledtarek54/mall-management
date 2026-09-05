<?php

use App\Support\CashFlowSection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A chart leaf that a bank account posts through is CASH on the cash-flow statement.
 *
 * `MintBankLedgerAccountService::mint()` — the method behind the bank picker's create button, and
 * what `LearningSeeder` and the soak seeder mint through — wrote the leaf with no
 * `cash_flow_section`. `CashFlowSection::for()` floors an unclassified asset account to OPERATING,
 * which is the right error for working capital and exactly the wrong one for the one account whose
 * identity is "money in the bank": every receipt and payment routed through a minted bank read as an
 * operating working-capital movement, and only the generic `bank` ROLE account counted as cash. The
 * statement still balanced, which is why nothing said so.
 *
 * The service writes the section now; this reaches the leaves minted before it did. Scoped to
 * accounts a `bank_accounts` row actually names, and only where nothing was chosen — an operator's
 * own classification is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ledger_accounts')
            ->whereNull('cash_flow_section')
            ->whereIn('id', DB::table('bank_accounts')->whereNotNull('ledger_account_id')->select('ledger_account_id'))
            ->update(['cash_flow_section' => CashFlowSection::CASH]);
    }

    public function down(): void
    {
        // Deliberately nothing: reverting a classification back to "unstated" would move the
        // cash-flow statement of every install that minted a bank, and the pre-migration value was
        // an omission rather than a decision.
    }
};
