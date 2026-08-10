<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which invoice carries this cheque's returned-cheque fee (module 33).
 *
 * The link is what makes billing idempotent: "Charge the fee" is an operator action, and two clicks
 * — or two operators — must not mint two invoices for one bounce. Same guard as the violation fine's
 * `billed_invoice_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_dated_cheques', function (Blueprint $table) {
            $table->foreignId('nsf_fee_invoice_id')->nullable()->after('cleared_payment_id')
                ->constrained('invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('post_dated_cheques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nsf_fee_invoice_id');
        });
    }
};
