<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_sales_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained('leases')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('declared_sales', 14, 2);
            $table->decimal('calculated_percentage_rent', 14, 2)->default(0);
            $table->timestamp('declared_at');
            $table->nullableMorphs('declared_by');
            $table->enum('status', ['submitted', 'locked', 'disputed'])->default('submitted');
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('audit_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['lease_id', 'period_start'], 'tenant_sales_lease_period_unique');
            $table->index(['status', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_sales_declarations');
    }
};
