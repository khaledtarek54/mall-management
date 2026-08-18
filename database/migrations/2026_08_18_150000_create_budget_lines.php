<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operating budget: what each P&L account is EXPECTED to do, per property, per month.
 *
 * The income statement could already compare a period against the one before it or the same one a
 * year earlier (`ComparativeStatementService`). Both answer "is this normal?" — neither answers
 * "is this what we PLANNED?", which is the question a mall's monthly review is actually built
 * around, and the one an owner asks first.
 *
 * **Monthly, not annual.** A mall is seasonal — Ramadan and back-to-school move footfall and
 * therefore turnover rent — so an annual figure divided by twelve would report a variance every
 * month that is really just the season. The importer still accepts an annual number and spreads it,
 * because that is how a first budget is usually written, but the STORED grain is the month so a
 * refined budget can say what it means.
 *
 * A budget is a plan, not a transaction: nothing here posts, nothing derives from it, and deleting
 * a line changes no reported result. That is why it carries no posting-date guard and no GL
 * registry entry — it is the only number in this schema an operator may freely rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('month');           // 1–12
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            // One figure per account per month per property. Re-importing a budget overwrites the
            // plan rather than adding a second one beside it — which is what "the budget" means.
            $table->unique(['asset_id', 'ledger_account_id', 'fiscal_year', 'month'], 'budget_lines_unique_cell');
            $table->index(['asset_id', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
    }
};
