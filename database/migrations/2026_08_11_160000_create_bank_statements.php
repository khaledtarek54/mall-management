<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bank's own record of what moved — slice 2 of bank reconciliation.
 *
 * This is EVIDENCE, not accounting. Nothing here posts, and nothing here changes a balance: a
 * statement is what the ledger will later be checked against, and the whole value of the control is
 * that it comes from outside the system. Matching is slice 3; these tables only have to hold the
 * bank's version faithfully.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();

            $table->date('period_start');
            $table->date('period_end');

            // What the BANK says the account held. The reconciliation's arithmetic ends here:
            // closing balance ± unmatched items must equal the ledger's balance on that date.
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('closing_balance', 14, 2)->default(0);

            $table->string('source_filename')->nullable();
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One statement per account per period. Re-importing the same month must land on the
            // same statement rather than quietly creating a second copy of the bank's truth.
            $table->unique(['bank_account_id', 'period_start', 'period_end'], 'bank_statements_account_period_unique');
            $table->index(['bank_account_id', 'period_end']);
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained()->cascadeOnDelete();

            $table->date('value_date');
            $table->string('description')->nullable();
            // What the bank calls it — a cheque number, a transfer reference. The strongest signal a
            // matcher has after the amount, which is why it is indexed rather than left in the text.
            $table->string('reference')->nullable();

            // SIGNED: positive is money in, negative is money out. One column rather than an amount
            // plus a direction flag, because two columns can disagree and a signed number cannot.
            $table->decimal('amount', 14, 2);

            $table->decimal('running_balance', 14, 2)->nullable();

            $table->timestamps();

            $table->index(['bank_statement_id', 'value_date']);
            $table->index(['bank_statement_id', 'reference']);
            // Idempotency, at the layer that cannot be bypassed: re-importing an overlapping export
            // — which is what operators actually do — must not duplicate a line. A bank CAN issue two
            // genuinely identical rows on one day, so `row_hash` carries an occurrence number to keep
            // the second one importable; see BankStatementLine::hashFor().
            $table->string('row_hash', 64);
            $table->unique(['bank_statement_id', 'row_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');
    }
};
