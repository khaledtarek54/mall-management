<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // e.g., "PAY-202511-0001"
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('EGP');
            $table->enum('method', [
                'card',
                'bank_transfer',
                'instapay',
                'wallet',
                'cash',
                'cheque',
                'other',
            ]);
            $table->enum('status', [
                'initiated',
                'authorized',
                'captured',
                'reconciled',
                'settled',
                'failed',
                'refunded',
                'bounced',
            ])->default('captured');
            $table->date('payment_date');
            $table->string('gateway')->nullable(); // PSP name e.g., "Paymob"
            $table->string('gateway_transaction_id')->nullable();
            $table->json('gateway_response')->nullable();
            $table->string('cheque_number')->nullable();
            $table->date('cheque_clearance_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'payment_date']);
            $table->index('tenant_id');
            $table->index('method');
        });

        // Pivot table: payments can be allocated across multiple invoices
        Schema::create('invoice_payment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->decimal('allocated_amount', 12, 2);
            $table->timestamps();

            $table->unique(['invoice_id', 'payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payment');
        Schema::dropIfExists('payments');
    }
};
