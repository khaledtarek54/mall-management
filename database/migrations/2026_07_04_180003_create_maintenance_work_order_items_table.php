<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checklist items on a preventive-maintenance work order (module 26). Copied from the
 * plan's checklist template when the order is raised; the engineer ticks each done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_work_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_work_order_id')->constrained('maintenance_work_orders')->cascadeOnDelete();
            $table->string('label');
            $table->boolean('is_done')->default(false);
            $table->dateTime('done_at')->nullable();
            $table->foreignId('done_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('maintenance_work_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_work_order_items');
    }
};
