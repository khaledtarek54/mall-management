<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advance repayment via payroll deduction (module 24, Phase 4b — closes the سلف loop).
 *
 * A payslip LINE can repay ONE of the employee's outstanding advances: `advance_deduction`
 * is the installment, `employee_advance_id` the advance it repays. The installment reduces
 * net pay and the payroll GL entry credits Employee Advances directly — so the receivable
 * drops without a separate cash repayment (which would double-count the credit). The
 * advance's outstanding derives to include APPROVED-run deductions (EmployeeAdvance::outstanding),
 * so a cancelled/deleted run's deduction stops counting automatically.
 *
 * The RUN header carries the aggregate `advance_deductions` (Σ line installments) that the
 * journalizer posts and that reduces `net_paid`. All default 0 → backward-compatible, no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            // The advance being repaid (nullOnDelete: if the advance is force-deleted the line
            // survives with the amount but no link; soft-deleting the advance keeps the FK).
            $table->foreignId('employee_advance_id')->nullable()->after('employee_id')
                ->constrained('employee_advances')->nullOnDelete();
            $table->decimal('advance_deduction', 12, 2)->default(0)->after('social_insurance');
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('advance_deductions', 14, 2)->default(0)->after('social_insurance');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_advance_id');
            $table->dropColumn('advance_deduction');
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn('advance_deductions');
        });
    }
};
