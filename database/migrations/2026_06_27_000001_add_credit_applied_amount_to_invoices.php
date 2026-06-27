<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credit notes settle AR but were never recorded in a place Invoice::recompute
 * Totals() reads (it sums only the captured payments pivot), so a credit applied
 * to an invoice was silently erased the next time a payment recomputed the
 * invoice. Track applied credit in its own column and fold it into recompute.
 *
 * Backfill: existing paid_amount = captured payments + any applied credit, so
 * the applied credit for each invoice is paid_amount − captured payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('credit_applied_amount', 14, 2)->default(0)->after('paid_amount');
        });

        DB::table('invoices')->orderBy('id')->select('id', 'paid_amount')->chunkById(500, function ($invoices) {
            foreach ($invoices as $invoice) {
                $captured = (float) DB::table('invoice_payment')
                    ->join('payments', 'payments.id', '=', 'invoice_payment.payment_id')
                    ->where('invoice_payment.invoice_id', $invoice->id)
                    ->where('payments.status', 'captured')
                    ->sum('invoice_payment.allocated_amount');

                $credit = round(max(0, (float) $invoice->paid_amount - $captured), 2);
                if ($credit > 0) {
                    DB::table('invoices')->where('id', $invoice->id)->update(['credit_applied_amount' => $credit]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('credit_applied_amount');
        });
    }
};
