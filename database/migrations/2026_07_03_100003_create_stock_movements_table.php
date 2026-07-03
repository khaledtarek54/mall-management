<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory — the stock ledger (append-only, like the GL). Every receipt /
 * consumption / adjustment / transfer is an immutable row; on-hand quantity is
 * DERIVED as SUM(quantity) per item+warehouse (never a cached mutable count),
 * so it reconciles and can't silently drift. `quantity` is SIGNED: positive adds
 * stock, negative removes it. `source` links a movement to its origin (e.g. a
 * maintenance ticket for consumption — Phase 2, or a vendor bill — Phase 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->enum('type', ['receipt', 'consumption', 'adjustment', 'transfer_in', 'transfer_out']);
            $table->decimal('quantity', 14, 3); // signed
            $table->decimal('unit_cost', 14, 2)->default(0); // cost per unit at movement time
            $table->string('reference')->nullable();
            $table->nullableMorphs('source'); // TenantRequest (consumption) / VendorBill (receipt) …
            $table->foreignId('moved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('moved_on');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['inventory_item_id', 'warehouse_id']); // on-hand sums
            $table->index('warehouse_id');
            $table->index('type');
            $table->index('moved_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
