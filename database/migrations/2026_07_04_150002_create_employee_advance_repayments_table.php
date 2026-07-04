<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repayments of an employee advance/loan (module 24, Phase 2). Each repayment posts
 * Dr Cash|Bank / Cr Employee Advances, reducing the receivable. Outstanding on an
 * advance = amount − Σ(repayments). `asset_id` is denormalised from the advance for
 * the GL dimension. A repayment is a CHILD ledger source of the advance — its GL
 * follows the advance's lifecycle via the parent-lifecycle cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_advance_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_advance_id')->constrained('employee_advances')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('repaid_on');
            $table->string('method')->default('cash');     // cash | bank
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('employee_advance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_advance_repayments');
    }
};
