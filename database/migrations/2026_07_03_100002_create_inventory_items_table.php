<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory — the item catalog (shared reference data). A "pump seal" is the same
 * item everywhere; on-hand quantity is tracked PER warehouse via stock_movements,
 * so the catalog itself is not property-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 50)->unique();
            $table->string('name');
            $table->string('category')->nullable(); // free-form
            $table->string('unit', 20)->default('each'); // unit of measure (each/litre/kg…)
            $table->decimal('unit_cost', 14, 2)->default(0); // standard/last cost per unit
            $table->decimal('reorder_level', 14, 3)->default(0); // low-stock threshold
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
