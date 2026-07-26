<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ad-hoc / penalty deductions (module 24, Phase 4c — خصومات). A payslip line can carry an
 * OTHER deduction beyond tax / social insurance / advance installment — absence (غياب),
 * penalties (جزاءات), damages, or any miscellaneous withholding — with an optional note.
 *
 * The deduction reduces net pay and the payroll GL entry credits a liability (Employee
 * Deductions Payable) — a neutral holding account the accountant can reclassify via the
 * account mapping (fund / other income / expense-reduction, per the deduction's nature).
 *
 * All default 0 / null → backward-compatible, no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->decimal('other_deductions', 12, 2)->default(0)->after('advance_deduction');
            $table->string('deduction_note')->nullable()->after('other_deductions');
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('other_deductions', 14, 2)->default(0)->after('advance_deductions');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->dropColumn(['other_deductions', 'deduction_note']);
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn('other_deductions');
        });
    }
};
