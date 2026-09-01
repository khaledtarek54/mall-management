<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recurring cost says which rail it leaves by, and which bank account.
 *
 * `GenerateRecurringExpensesService::recordExpense()` never set `paid_from`, and the schedule had
 * nowhere to say it — so every generated expense fell to the column default and credited CASH.
 * Real-estate tax, municipal levies, a licence renewal and a fixed retainer all leave a BANK
 * account, so the entire recurring-cost stream was posting its credit leg to the wrong side of the
 * chart, silently and every month.
 *
 * `bank_account_id` comes with it because `App\Support\MoneyAccount` resolves in three tiers —
 * the document's own bank account, then the RAIL's mapped account, then the posting role — and
 * giving the schedule only the rail would leave a mall banking in two places unable to say which.
 * Both nullable: an existing schedule keeps behaving exactly as it did until someone states it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_expenses', function (Blueprint $table) {
            $table->string('paid_from', 32)->nullable()->after('tax_code');
            $table->foreignId('bank_account_id')->nullable()->after('paid_from')
                ->constrained('bank_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recurring_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropColumn('paid_from');
        });
    }
};
