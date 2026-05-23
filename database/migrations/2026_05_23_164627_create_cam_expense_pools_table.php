<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cam_expense_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->decimal('total_actual_expense', 14, 2)->default(0);
            $table->decimal('total_estimated_collected', 14, 2)->default(0);
            $table->enum('status', ['draft', 'reconciling', 'reconciled', 'closed'])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['asset_id', 'period_year'], 'cam_pool_asset_year_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cam_expense_pools');
    }
};
