<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preventive-maintenance work orders (module 26) — a scheduled facility job, raised
 * from a plan (recurring) or ad-hoc. Carries a checklist (its items) the engineer
 * completes, then the order is marked done. Internal (no tenant); scoped per property.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_plan_id')->nullable()->constrained('maintenance_plans')->nullOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('reference')->nullable()->unique();
            $table->string('title');
            $table->string('category')->default('other');
            $table->string('status')->default('open');      // open|in_progress|done|cancelled
            $table->date('scheduled_for');
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'scheduled_for']);
            $table->index('maintenance_plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_work_orders');
    }
};
