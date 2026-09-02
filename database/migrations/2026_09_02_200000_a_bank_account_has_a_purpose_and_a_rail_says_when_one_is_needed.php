<?php

use App\Models\BankAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which bank account did this money move through? — asked, defaulted, and required.
 *
 * EG-12 (2026-08-22) gave six money documents a `bank_account_id` and made
 * `App\Support\MoneyAccount` the one resolver. What it did NOT do is make anybody answer: the field
 * is optional on every form, defaults to nothing, and null is the normal state — so on a real
 * install almost every document names no account, every posting falls to the generic `bank` role,
 * and the two-bank separation the whole feature exists for stays theoretical.
 *
 * ## What Yardi does, and the one thing to copy
 *
 * Voyager makes the cash account **mandatory on every money movement** — a receipt names its Bank,
 * an AP payment run is *driven* by the Bank it pays from (which is also what picks the cheque
 * series). It is tolerable there for exactly one reason: the **property carries default cash
 * accounts** — operating, security-deposit trust, reserve — so the operator almost never types it.
 *
 * **Required without a default is not the Yardi behaviour, it is the worst half of it**: an
 * operator picking the same value three hundred times a month eventually picks the wrong one, and
 * a wrong bank account is worse than none — `MatchBankStatementLineService::candidatesFor()` finds
 * candidates BY the chart account, so it presents the mistake as a real match on the wrong
 * statement. So both halves ship together or neither does.
 *
 * ## Three columns
 *
 * **`payment_methods.requires_bank_account`** — the rail says whether naming an account is part of
 * recording money on it. A ROW, not a rule: `RecurringExpenseForm` already asked this question and
 * answered it with a hardcoded `!== 'cash'`, which is the shape CLAUDE.md names as a filter written
 * twice. It is also simply wrong the day the operator activates Fawry — a collection network is not
 * cash and the money does not land in a bank the same day either.
 *
 * Backfilled as `code <> 'cash'`, which is verbatim the literal it replaces and verbatim the floor
 * `PaymentMethod::accountIdOrFloor()` already applies, so no rail changes meaning. `cash` is the
 * only shipped code whose money genuinely never touches a bank.
 *
 * **`bank_accounts.purpose`** — `operating` · `deposits` · `payroll`. Yardi's own split, and the
 * `deposits` row is the one that earns its place: tenant deposit money is a liability the operator
 * holds, not their working cash, and Egyptian malls commonly bank it apart. `payroll` is here
 * because Egyptian banks require a salary account for a payroll transfer file.
 *
 * **`bank_accounts.is_default`** — which account a document on this property defaults to, per
 * purpose. NOT a unique index: MySQL has no partial index, so "one default per (asset, purpose)" is
 * kept by {@see BankAccount::booted()} demoting the previous holder on write. An index
 * that cannot express the rule would only lie about enforcing it.
 *
 * ## Nothing moves on deploy
 *
 * Every existing document keeps the `bank_account_id` it has (usually null → the rail's floor,
 * unchanged). `is_default` is false everywhere until an operator flags one, and the defaulting and
 * the requirement both read that flag — so an install that ignores this screen behaves exactly as
 * it did yesterday. The first thing that changes is the first NEW document on a property whose
 * operator has said where its money lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // Default FALSE so a row inserted by anything that does not know about this column is
            // unchanged. The model's `$attributes` default is true, which is the right answer for a
            // rail a person is adding — an operator registering a new way to be paid is registering
            // a bank-borne one far more often than a second till.
            $table->boolean('requires_bank_account')
                ->default(false)
                ->after('for_outbound');
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->string('purpose', 32)->default('operating')->after('currency');
            $table->boolean('is_default')->default(false)->after('purpose');

            // The defaulting read is `(asset_id, purpose, is_default)` on every money create, and
            // the register lists by property. One composite covers both.
            $table->index(['asset_id', 'purpose', 'is_default'], 'bank_accounts_default_index');
        });

        // Verbatim the literal this replaces — `RecurringExpenseForm`'s `!== 'cash'` — so no rail
        // changes what it means. An operator who has already added their own rails gets the same
        // reading applied to them.
        DB::table('payment_methods')->where('code', '<>', 'cash')->update(['requires_bank_account' => true]);
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('requires_bank_account');
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropIndex('bank_accounts_default_index');
            $table->dropColumn(['purpose', 'is_default']);
        });
    }
};
