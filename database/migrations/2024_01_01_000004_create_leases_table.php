<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // e.g., "LSE-HW-2025-0001"
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->enum('status', [
                'draft',
                'pending_approval',
                'active',
                'expired',
                'renewed',
                'terminated',
                'cancelled',
            ])->default('draft');
            $table->date('commencement_date');
            $table->date('expiry_date');
            $table->unsignedSmallInteger('term_months');
            $table->decimal('base_rent_monthly', 12, 2);
            $table->decimal('service_charge_monthly', 12, 2)->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->decimal('security_deposit', 12, 2)->default(0);
            $table->boolean('security_deposit_received')->default(false);
            $table->decimal('escalation_rate', 5, 2)->default(0); // % per year
            $table->enum('escalation_type', ['none', 'fixed_percent', 'cpi'])->default('none');
            $table->date('next_escalation_date')->nullable();
            $table->boolean('has_percentage_rent')->default(false);
            $table->decimal('percentage_rent_threshold', 12, 2)->nullable();
            $table->decimal('percentage_rent_rate', 5, 2)->nullable();
            $table->date('billing_day')->nullable(); // day of month to issue invoice
            $table->unsignedSmallInteger('payment_terms_days')->default(7);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'expiry_date']);
            $table->index('unit_id');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leases');
    }
};
