<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give a vendor payment a way back.
 *
 * `DeletionPolicy` has always named the correction for a wrongly-recorded vendor payment as
 * "void the payment — money left the bank", and `VendorBillService::cancel` refuses a bill with
 * payments by telling the operator to "reverse the payments first". Neither existed: the payments
 * relation manager is read-only, the model is unconditionally committed (so even the soft-delete
 * that would self-heal the GL is refused), and there was no service. A cheque keyed against the
 * wrong bill was permanent.
 *
 * A void is a status flip, not a delete — the same shape as VoidInvoiceService / VoidPaymentService
 * on the AR side: the journalizer stops returning a payload, the sweep posts the reversing entry,
 * and the bill's balance re-opens. The row stays on the books with its reason, which is the whole
 * point of voiding rather than deleting.
 *
 * (Change-impact plan — Phase 0, F1.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_bill_payments', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('notes');
            $table->text('void_reason')->nullable()->after('voided_at');
            $table->foreignId('voided_by_user_id')->nullable()->after('void_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_bill_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by_user_id');
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
