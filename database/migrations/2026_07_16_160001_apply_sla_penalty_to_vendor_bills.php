<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Charging an SLA penalty to the vendor's bill (FR-CM-08, money half) — module 26.
 *
 * `vendor_bills` has no line items, and `balance` is DERIVED (`total − paid_amount`) by
 * `VendorBill::recompute()`, the single source of truth for AP settlement — the mirror of
 * the Invoice AR invariant. A penalty therefore cannot be "a line on the bill": it is a
 * second thing that reduces what is payable, exactly as `credit_applied_amount` does on the
 * tenant side of the ledger.
 *
 * **Accounting treatment: a cost reduction, not income.** Money received from a supplier is
 * presumed to adjust the price paid to them rather than to be separate revenue, unless it
 * buys a distinct good or service. So the penalty credits the SAME expense the bill debited
 * — the penalty follows the cost. See docs/BUSINESS-RULES.md for the assumptions this rests
 * on, including the CAM consequence, which needs the operator's sign-off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_bills', function (Blueprint $table) {
            // DERIVED, like paid_amount: only VendorBill::recompute() writes it.
            $table->decimal('penalty_applied_amount', 14, 2)->default(0)->after('paid_amount');
        });

        Schema::table('maintenance_penalties', function (Blueprint $table) {
            // Which bill this penalty was charged against. nullOnDelete: if the bill is ever
            // removed the penalty reverts to merely chargeable rather than vanishing.
            $table->foreignId('vendor_bill_id')->nullable()->after('vendor_contract_id')
                ->constrained('vendor_bills')->nullOnDelete();
            $table->dateTime('applied_at')->nullable()->after('finalised_at');
        });

        // 'applied' = charged to a bill. Kept distinct from 'final' so "assessed and owed"
        // and "actually deducted" are never confused — the second is what hits the books.
        //
        // ->change(), not a raw MODIFY COLUMN: that is MySQL-only syntax and the test suite
        // runs SQLite, where Laravel rebuilds the table instead.
        Schema::table('maintenance_penalties', function (Blueprint $table) {
            $table->enum('status', ['pending', 'final', 'applied', 'waived'])->default('pending')->change();
        });
    }

    public function down(): void
    {
        DB::table('maintenance_penalties')->where('status', 'applied')->update(['status' => 'final']);

        Schema::table('maintenance_penalties', function (Blueprint $table) {
            $table->enum('status', ['pending', 'final', 'waived'])->default('pending')->change();
        });

        Schema::table('maintenance_penalties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_bill_id');
            $table->dropColumn('applied_at');
        });

        Schema::table('vendor_bills', function (Blueprint $table) {
            $table->dropColumn('penalty_applied_amount');
        });
    }
};
