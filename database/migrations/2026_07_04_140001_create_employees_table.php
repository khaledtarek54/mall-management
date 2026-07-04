<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee master (module 24 — HR, Phase 1). The register of the operator's own
 * staff, scoped per property (like units / fixed assets), optionally tagged to a
 * department. Foundation for advances/loans (Phase 2) and per-employee payslips
 * (Phase 3). Distinct from `users` (admin logins) and `tenant_users` (portal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('code', 40);                  // staff number (unique per property)
            $table->string('name');
            $table->string('national_id', 20)->nullable(); // الرقم القومي
            $table->string('position')->nullable();       // job title
            $table->date('hire_date');
            $table->decimal('base_salary', 12, 2)->default(0);
            $table->string('payment_method')->default('bank'); // cash|bank — how they're paid
            $table->string('phone', 30)->nullable();
            $table->enum('status', ['active', 'terminated'])->default('active');
            $table->date('terminated_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['asset_id', 'code'], 'employee_asset_code_unique');
            $table->index('national_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
