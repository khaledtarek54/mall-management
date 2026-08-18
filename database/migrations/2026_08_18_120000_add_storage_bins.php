<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bin locations inside a warehouse (FR-INV, the last open piece of phase 5).
 *
 * A warehouse answers "which mall's storeroom"; a bin answers "which shelf". Without one, a
 * storeroom holding four hundred parts is a single undifferentiated box and "we have six of those"
 * is true but useless — nobody can find them.
 *
 * **Master data, not free text.** The cheap version is a `bin` string on the movement. It drifts on
 * the first typo: `A-03-2` and `A032` become two locations that both look real, and the count is
 * split between them with nothing to reconcile against. A row with a unique code per warehouse
 * cannot be typo'd into existence.
 *
 * `stock_movements.bin_id` is NULLABLE and stays that way: an operator who does not rack their
 * storeroom should pay nothing for this, and every movement written before today has no bin by
 * definition. On-hand per bin is DERIVED by grouping the movements, never stored — the same rule
 * the item-level on-hand already follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name')->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Unique WITHIN a warehouse, not globally: "A-01" is a perfectly ordinary aisle in
            // every storeroom in the portfolio, and a global unique would make the second mall to
            // rack its shelves unable to use its own labels.
            $table->unique(['warehouse_id', 'code']);
            $table->index(['warehouse_id', 'is_active']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            // nullOnDelete, not cascade: a bin can be retired, and losing the MOVEMENT with it
            // would rewrite stock history to make the shelf's contents vanish.
            $table->foreignId('bin_id')->nullable()->after('warehouse_id')
                ->constrained('bins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bin_id');
        });

        Schema::dropIfExists('bins');
    }
};
