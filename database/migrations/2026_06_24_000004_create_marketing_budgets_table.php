<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-property, per-year marketing fund (FR MKT-3/5). Income-side analogue of
 * cam_expense_pools: accrued_amount accumulates the 5% marketing levy from
 * leases; spent_amount accumulates marketing spend. Balance is derived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->decimal('accrued_amount', 14, 2)->default(0);
            $table->decimal('spent_amount', 14, 2)->default(0);
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['asset_id', 'period_year'], 'marketing_budget_asset_year_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_budgets');
    }
};
