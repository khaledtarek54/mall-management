<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preventive-maintenance plans (module 26). A recurring schedule for facility upkeep —
 * e.g. "HVAC filter check, every 30 days". When due, the scan raises a work order with
 * the plan's checklist. Internal/facility (no tenant), so it's distinct from the
 * tenant-facing maintenance requests (module 11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete(); // null = common / asset-wide
            $table->string('title');
            $table->string('category')->default('other');   // electrical|plumbing|hvac|...
            $table->text('description')->nullable();
            $table->string('frequency_unit')->default('months'); // days|weeks|months
            $table->unsignedInteger('frequency_value')->default(1);
            $table->json('checklist')->nullable();          // template item labels
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->date('next_due_date');
            $table->dateTime('last_generated_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'next_due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_plans');
    }
};
