<?php

use App\Models\VendorBillPayment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier payment is a money document, and until now it had no identity of its own.
 *
 * `vendor_bill_payments.reference` was created for the document's number — its own migration says
 * "e.g. BILLPAY-202607-0001" — and nothing ever wrote it, so every AP payment read "Vendor payment
 * #9" on the ledger, "—" on the bill's payments tab and "#9" in the bank-reconciliation picker.
 * `VendorBillPayment` allocates `PMT-{mall}-{period}-NNNN` into it on create now, and this
 * numbers every payment already on file, in id order, each series continued from whatever it
 * holds (`VendorBillPayment::backfillMissingNumbers()`, idempotent).
 *
 * `bank_reference` is the OTHER identity — what the bank printed, the cheque number or transfer
 * reference the operator types when recording the payment. Two questions, two columns, exactly
 * as the receipt keeps `reference` (RCT-…) apart from `cheque_number`. Nullable: a cash payment
 * and every payment recorded before today have none.
 *
 * The narrative on entries already posted keeps its "#9": `LedgerPoster::matches()` compares
 * lines, date and property and never text, so nothing re-posts, and a posted entry's wording is
 * a snapshot by design (EG-36). New entries name the number.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Three steps, each guarded, because MySQL DDL autocommits: a failure at the second or
        // third leaves the first in place, and an unguarded re-run then dies on "duplicate
        // column" with the fix by hand — the shape 2026_09_12_600000 had to be retrofitted after
        // it died mid-way on staging. The backfill is idempotent by construction.
        if (! Schema::hasColumn('vendor_bill_payments', 'bank_reference')) {
            Schema::table('vendor_bill_payments', function (Blueprint $table) {
                $table->string('bank_reference', 100)->nullable()->after('reference');
            });
        }

        VendorBillPayment::backfillMissingNumbers();

        // The backstop every document series has: two writers computing one number from one
        // stale snapshot fail loudly instead of filing two payments under it. After the backfill,
        // so no pre-existing row is null when the index lands.
        if (! Schema::hasIndex('vendor_bill_payments', 'vendor_bill_payments_reference_unique')) {
            Schema::table('vendor_bill_payments', function (Blueprint $table) {
                $table->unique('reference', 'vendor_bill_payments_reference_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('vendor_bill_payments', function (Blueprint $table) {
            if (Schema::hasIndex('vendor_bill_payments', 'vendor_bill_payments_reference_unique')) {
                $table->dropUnique('vendor_bill_payments_reference_unique');
            }
            $table->dropColumn('bank_reference');
        });
    }
};
