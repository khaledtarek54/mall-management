<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فواتير الموردين — Accounts Payable. A vendor bill is the AP counterpart of an
 * invoice: it recognises an expense and a payable. Bill payments settle it.
 * paid_amount / balance are DERIVED (VendorBillService::recompute) — never set
 * directly, mirroring the Invoice AR invariant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_bills', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique(); // e.g. "BILL-AW-202607-0001"
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete(); // property the expense belongs to
            $table->string('category', 40); // maintenance | utilities | cleaning_security | marketing | admin | other
            $table->enum('status', ['draft', 'approved', 'partially_paid', 'paid', 'cancelled'])->default('draft');
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->string('reference')->nullable(); // the vendor's own invoice reference
            $table->text('description')->nullable();
            $table->decimal('subtotal', 14, 2);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0); // DERIVED
            $table->decimal('balance', 14, 2)->default(0);      // DERIVED = total - paid_amount
            $table->string('currency', 3)->default('EGP');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'bill_date']);
            $table->index('vendor_id');
            $table->index('asset_id');
        });

        Schema::create('vendor_bill_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_bill_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->nullable(); // e.g. "BILLPAY-202607-0001"
            $table->decimal('amount', 14, 2);
            $table->enum('method', ['cash', 'bank_transfer', 'cheque', 'card', 'other'])->default('bank_transfer');
            $table->date('payment_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('vendor_bill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_bill_payments');
        Schema::dropIfExists('vendor_bills');
    }
};
