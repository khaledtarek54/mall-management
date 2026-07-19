<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post-dated cheques (شيكات آجلة) — strengthen item #4. The Egyptian norm: a tenant lodges a
 * year of PDCs up front. Atriom captured only one cheque number on a *received* payment; there was
 * no forward-instrument register, maturity schedule, or bounce lifecycle. No Western benchmark
 * fills this — a differentiator.
 *
 * v1 (operator decision): REGISTER-ONLY, SETTLE-ON-CLEAR. A lodged cheque is tracked (maturity +
 * bounce lifecycle) but the tenant's invoice stays OPEN until the cheque clears; clearing records a
 * normal cheque Payment through the existing payment flow (so Invoice::recomputeTotals stays the AR
 * single source of truth). The Notes-Receivable-on-receipt accrual is a documented future refinement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_dated_cheques', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();                 // PDC-YYYY-NNNN
            $table->foreignId('asset_id')->constrained('assets');  // the property (isolation dimension)
            $table->foreignId('tenant_id')->constrained('tenants'); // the drawer
            $table->foreignId('lease_id')->nullable()->constrained('leases')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete(); // what it's meant to pay

            $table->string('cheque_number', 100);
            $table->string('bank_name', 200)->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->date('cheque_date');                           // maturity (the post-date)
            $table->date('received_date');                         // when the operator took it in

            $table->string('status')->default('held');             // held | deposited | cleared | bounced | cancelled
            $table->foreignId('cleared_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'cheque_date']);
            $table->index(['tenant_id', 'status']);
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_dated_cheques');
    }
};
