<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee advances & loans (سلف — module 24, Phase 2). Money paid to an employee
 * against future salary (advance) or as a repayable loan. Each grant posts to the GL
 * as Dr Employee Advances (a receivable) / Cr Cash|Bank; repayments reverse it.
 * `asset_id` is denormalised from the employee so the GL dimension survives even if
 * the employee record is later archived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('type')->default('advance');   // advance | loan
            $table->decimal('amount', 12, 2);
            $table->date('advance_date');
            $table->string('paid_from')->default('cash');  // cash | bank
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'advance_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_advances');
    }
};
