<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One depreciation charge per fixed asset per month (module 23). Accumulated
 * depreciation is DERIVED as SUM(amount) — never a cached count — so it
 * reconciles. Unique per (asset, month) makes the monthly run idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depreciation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->date('period_month');            // first day of the depreciated month
            $table->decimal('amount', 14, 2);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['fixed_asset_id', 'period_month'], 'depreciation_asset_month_unique');
            $table->index('period_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_entries');
    }
};
