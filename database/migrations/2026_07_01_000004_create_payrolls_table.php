<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مسير الرواتب — a monthly payroll run (per-run totals, not per-employee payslips —
 * that's HR's domain). Posts Dr Salaries Expense (gross) / Cr Salary Tax Payable +
 * Cr Social Insurance Payable + Cr Bank|Cash (net). net_paid is DERIVED
 * (gross − tax − insurance), enforced in the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique(); // e.g. "PR-AW-202607-0001"
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_month'); // first day of the payroll month
            $table->text('description')->nullable();
            $table->decimal('gross_salaries', 14, 2);
            $table->decimal('salary_tax', 14, 2)->default(0);        // withheld → liability
            $table->decimal('social_insurance', 14, 2)->default(0);  // withheld → liability
            $table->decimal('net_paid', 14, 2);                      // DERIVED = gross − tax − insurance
            $table->enum('paid_from', ['cash', 'bank'])->default('bank');
            $table->enum('status', ['draft', 'approved', 'cancelled'])->default('draft');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'period_month']);
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
