<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Withholding tax on vendor payments — خصم وإضافة (module 12b).
 *
 * Egyptian entities must withhold tax at source on local supplier payments (Income Tax Law 91/2005,
 * art. 59) and remit it to the ETA. Atriom paid vendors GROSS: the operator was non-compliant, and
 * the un-withheld amount becomes their own liability. This is the AP-side twin of the VAT already
 * handled correctly on the AR side.
 *
 * The mechanics that matter:
 *   - `vendor_bill_payments.amount` stays the amount that SETTLES THE PAYABLE (gross). The vendor's
 *     claim is discharged in full — part in cash, part by tax paid to the ETA on their behalf — so
 *     `VendorBill::recompute()` and every balance derived from it keep working untouched.
 *   - `withholding_amount` is the slice that goes to the ETA rather than the vendor. Net cash out is
 *     `amount − withholding_amount`, which is what the journalizer credits to bank/cash.
 *
 * Rates are per-vendor with a settings-driven default, never hardcoded: they vary by the nature of
 * the payment (supplies / services / contracting / professional fees) and the operator's accountant
 * owns them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_bill_payments', function (Blueprint $table) {
            $table->decimal('withholding_amount', 15, 2)->default(0)->after('amount');
        });

        Schema::table('vendors', function (Blueprint $table) {
            // Null = fall back to the system default rate. 0 = explicitly exempt (e.g. a
            // foreign supplier outside Egyptian withholding), which is NOT the same thing.
            $table->decimal('withholding_tax_rate', 5, 2)->nullable()->after('tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('withholding_tax_rate');
        });

        Schema::table('vendor_bill_payments', function (Blueprint $table) {
            $table->dropColumn('withholding_amount');
        });
    }
};
