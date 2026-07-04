<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee payroll lines (module 24, Phase 3). A line breaks a payroll RUN down
 * by employee — the basis for individual payslips. Pure DETAIL: lines are NOT a ledger
 * source; when a run has lines, the run header (gross / tax / insurance / net) DERIVES
 * from Σ lines, and the existing payroll journalizer posts the (unchanged) aggregate —
 * so the GL and its tie-out are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained('payrolls')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->decimal('gross', 12, 2)->default(0);
            $table->decimal('salary_tax', 12, 2)->default(0);
            $table->decimal('social_insurance', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            // One line per employee per run.
            $table->unique(['payroll_id', 'employee_id'], 'payroll_line_run_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
    }
};
