<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cost that comes round every period whether or not anyone remembers it (EG-33 / T-8).
 *
 * **There was no recurring-expense concept anywhere in this system.** Recurrence existed only on the
 * revenue side — `charges` bill a lease every cycle — so every cost that arrives on a calendar
 * rather than on an invoice was somebody's reminder: real-estate tax, municipal levies, the annual
 * civil-defence licence, a fixed cleaning retainer. Yardi calls these Recurring Payables and has
 * shipped them for twenty years, because a property company's outgoings are mostly known in advance.
 *
 * ## The schedule is NOT a money document
 *
 * It mints `Expense` rows and those post to the ledger through the journalizer that already exists.
 * Registering the schedule itself in `LedgerPoster::JOURNALIZERS` would post every levy TWICE and
 * balance both times — the same reasoning that keeps a facility work order a cost object rather than
 * a GL source.
 *
 * ## `expenses.recurring_expense_id` is what makes generation safe to repeat
 *
 * The scheduled sweep runs daily. Without a link back to the schedule the only way to ask "has this
 * period already been generated" is to match on a description and a date, which is exactly the kind
 * of guess that double-books a statutory cost — real money, in the GL, on a government levy nobody
 * re-reads. The unique index makes the second attempt impossible rather than unlikely.
 *
 * ## What this deliberately does NOT model
 *
 * Egyptian real-estate tax has a rate, a rental-value basis, a 32% non-residential maintenance
 * deduction and an assessment issued per property by the tax authority. **None of that is invented
 * here.** The assessed figure is a fact the operator holds, and a system that computed it from
 * guessed rates would produce a confident wrong number on a statutory filing. This is the schedule;
 * the amount is theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();

            // Property-owned: a real-estate tax assessment is issued against a building.
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();

            $table->string('description', 200);

            // The expense category, which is what points the cost at its P&L account — the same
            // catalogue an ad-hoc expense uses, so a generated cost books exactly where a
            // hand-entered one would.
            $table->string('category', 40);

            $table->decimal('amount', 15, 2);

            // A levy may be outside VAT entirely; null means the expense form's own default applies.
            $table->string('tax_code', 32)->nullable();

            // monthly · quarterly · semiannually · annually. Registered in `ValueSets`.
            // Semiannual earns its place: Egyptian real-estate tax is payable in two instalments.
            $table->string('frequency', 20);

            // Which day of the period the cost falls on, clamped to the month's length so a 31 does
            // not skip February — the same rule `BillingDay` learned the hard way.
            $table->unsignedTinyInteger('day_of_month')->default(1);

            $table->date('starts_on');

            // Null = runs until switched off. A licence fee has no end date; a fixed-term retainer
            // does.
            $table->date('ends_on')->nullable();

            // The last period actually generated — the idempotency stamp the sweep re-checks INSIDE
            // its transaction, under a lock.
            $table->date('last_generated_on')->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['asset_id', 'is_active']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            // Which schedule minted this cost — the audit trail, and the idempotency key.
            $table->foreignId('recurring_expense_id')->nullable()->after('asset_id')
                ->constrained()->nullOnDelete();

            // One expense per schedule per period. The sweep is idempotent by design; this makes a
            // double-generation impossible rather than merely unlikely, which is the standard a
            // statutory cost deserves.
            $table->unique(['recurring_expense_id', 'expense_date'], 'expenses_recurring_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique('expenses_recurring_period_unique');
            $table->dropConstrainedForeignId('recurring_expense_id');
        });

        Schema::dropIfExists('recurring_expenses');
    }
};
