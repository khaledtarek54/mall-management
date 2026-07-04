<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custodies (عهدة — module 25, Treasury Phase 1). Cash placed in a custodian's hands
 * to spend on the company's behalf. The grant posts Dr Custodies (an asset) / Cr
 * Cash|Bank; settlements (expenses with receipts, or cash returns) reduce it.
 * `asset_id` is denormalised from the custodian employee so the GL dimension survives
 * the employee being archived. Single-currency (EGP) — multi-currency is deferred (Q-F).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custodies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete(); // the custodian
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('reference')->nullable();       // optional custody ref/purpose
            $table->decimal('amount', 12, 2);              // amount granted
            $table->date('custody_date');
            $table->string('paid_from')->default('cash');  // cash | bank
            $table->text('purpose')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'custody_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custodies');
    }
};
