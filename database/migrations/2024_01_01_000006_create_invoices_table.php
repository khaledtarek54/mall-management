<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique(); // e.g., "INV-HW-202511-0001"
            $table->foreignId('lease_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->enum('status', [
                'draft',
                'issued',
                'partially_paid',
                'paid',
                'overdue',
                'disputed',
                'cancelled',
                'credited',
            ])->default('draft');
            $table->date('issue_date');
            $table->date('due_date');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->string('eta_submission_id')->nullable(); // Egyptian Tax Authority ID
            $table->timestamp('eta_submitted_at')->nullable();
            $table->json('eta_response')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'due_date']);
            $table->index('tenant_id');
            $table->index('lease_id');
            $table->index('issue_date');
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->enum('type', [
                'base_rent',
                'service_charge',
                'utility',
                'parking',
                'percentage_rent',
                'late_fee',
                'other',
            ]);
            $table->decimal('amount', 12, 2);
            $table->decimal('vat_rate', 5, 2)->default(14.00);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
