<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salary structure (module 24, Phase 4a). Two additive money columns on both the
 * payroll RUN and each per-employee LINE:
 *
 *  - `allowances` (بدلات) — the allowance PORTION of gross. `gross` stays the single
 *    source of truth for total earnings (already Dr Salaries Expense), so nothing that
 *    writes `gross` changes; this just itemises how much of it is allowances, for the
 *    payslip (and future tax treatment). basic = gross − allowances (a derived accessor).
 *
 *  - `employer_social_insurance` (حصة صاحب العمل في التأمينات) — the EMPLOYER's social-
 *    insurance contribution. Unlike the employee `social_insurance` (withheld from pay),
 *    this is a COMPANY cost that does NOT reduce net pay: it posts Dr Social Insurance
 *    Expense / Cr Social Insurance Payable, a balanced pair added to the existing entry.
 *
 * Both default 0 (existing rows: gross unchanged, allowances 0, employer SI 0 — a valid
 * lump-sum/legacy line), so the change is backward-compatible with no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->decimal('allowances', 12, 2)->default(0)->after('gross');
            $table->decimal('employer_social_insurance', 12, 2)->default(0)->after('social_insurance');
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('allowances', 14, 2)->default(0)->after('gross_salaries');
            $table->decimal('employer_social_insurance', 14, 2)->default(0)->after('social_insurance');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->dropColumn(['allowances', 'employer_social_insurance']);
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['allowances', 'employer_social_insurance']);
        });
    }
};
