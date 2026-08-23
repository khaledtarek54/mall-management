<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **A standing cost may be owed to a SUPPLIER, not paid out of the bank** — EG-33's other half.
 *
 * EG-33 built Yardi's Recurring Payables for costs the operator simply incurs: a real-estate tax
 * assessment, a government levy, an insurance premium. Those mint an {@see App\Models\Expense} —
 * paid from cash or bank, no counterparty document, no AP.
 *
 * The other kind is the one a mall actually has most of: a **fixed cleaning retainer, a security
 * contract, a lift maintenance contract**. That is a payable owed to a named vendor, usually under
 * a `vendor_contracts` row — and it was still typed in by hand every month, because
 * `vendor_contracts` generated nothing and the schedule could only mint an expense. The pre-staging
 * verification against Yardi found it: Voyager's recurring payables post to a VENDOR.
 *
 * ## `vendor_id` is the discriminator, and it is not an arbitrary one
 *
 * `expenses` carries no `vendor_id` at all — an expense is money leaving, with no creditor. So
 * naming a supplier on the schedule IS the statement that this cost is a payable, and the generator
 * branches on exactly that. Null keeps every existing schedule minting an expense, so nothing an
 * install books today moves.
 *
 * ## The generated bill is a DRAFT, deliberately
 *
 * A `draft` vendor bill does not post ({@see App\Models\VendorBill::NON_POSTABLE_STATUSES}), and
 * that is the right state for a document the COUNTERPARTY issues. Two reasons, and neither is
 * timidity:
 *
 *  - `vendor_bills.reference` is the supplier's own invoice number, unique per vendor, and cannot
 *    be invented. The bill is waiting for it.
 *  - Posting `Dr Expense / Cr AP` for an invoice nobody received is the system inventing a
 *    creditor's claim. A statutory levy is different: the operator knows they owe it and no one
 *    has to send anything.
 *
 * This is also what Voyager does — its recurring payable batch is staged and then posted by a
 * person. What the schedule removes is the RE-TYPING: the vendor, contract, category, property,
 * amount, tax code and date arrive filled in, and approving is one click.
 *
 * ## `payment_terms_days` lives on the schedule
 *
 * NOT NULL, default 0 = due on the bill date. `BillingSettings::default_payment_terms_days` is the
 * AR figure — what a TENANT is given — and reusing it for AP would be one number answering two
 * unrelated questions. Net-30 on the cleaning contract is a term of that agreement, so it belongs
 * on the row that represents it, exactly as `leases.payment_terms_days` does on the revenue side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_expenses', function (Blueprint $table) {
            // nullOnDelete, not cascade: a vendor is `#[DeletableWhenUnused]` and losing the
            // SCHEDULE with them would silently stop billing a cost that still recurs.
            $table->foreignId('vendor_id')->nullable()->after('asset_id')
                ->constrained()->nullOnDelete();

            $table->foreignId('vendor_contract_id')->nullable()->after('vendor_id')
                ->constrained()->nullOnDelete();

            // NOT NULL default 0, matching `leases.payment_terms_days` — its sibling on the
            // revenue side — rather than nullable. Null would have meant exactly what 0 means (due
            // on issue), so it bought nothing; and a name that is NOT NULL in one table and
            // nullable in another defeats `SettingsReachConformanceTest`, which keys on the column
            // NAME and counts a name as NOT NULL only when every table carrying it says so.
            $table->unsignedSmallInteger('payment_terms_days')->default(0)->after('day_of_month');
        });

        Schema::table('vendor_bills', function (Blueprint $table) {
            $table->foreignId('recurring_expense_id')->nullable()->after('vendor_contract_id')
                ->constrained()->nullOnDelete();

            // The same belt-and-braces as the expense side: the lock and the re-derived due date
            // stop two workers agreeing a period is due, and this makes a third attempt impossible
            // rather than unlikely. Generating a supplier bill twice is a real creditor balance.
            $table->unique(['recurring_expense_id', 'bill_date'], 'vendor_bills_recurring_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_bills', function (Blueprint $table) {
            $table->dropUnique('vendor_bills_recurring_period_unique');
            $table->dropConstrainedForeignId('recurring_expense_id');
        });

        Schema::table('recurring_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_contract_id');
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropColumn('payment_terms_days');
        });
    }
};
