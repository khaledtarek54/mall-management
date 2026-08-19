<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of this item we buy at a time.
 *
 * `reorder_level` says WHEN to buy; nothing said HOW MUCH, which is why `inventory:scan-low-stock`
 * could only ever ring a bell. Ordering the shortfall — enough to reach the reorder level — is the
 * one answer that is definitely wrong: it lands the item exactly on its own threshold, so the next
 * scan alerts again and the operator buys filters one at a time forever.
 *
 * NULLABLE, and null is a real answer meaning "we have not said". A drafted line then carries the
 * shortfall and the operator types the real figure before submitting — which is the whole reason
 * the scan drafts rather than submits. Inventing a multiple of the reorder level here would be
 * inventing a purchasing policy, and a number nobody chose is worse in a draft than a blank,
 * because a blank gets filled in and a plausible wrong number gets approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->decimal('reorder_quantity', 12, 3)->nullable()->after('reorder_level');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('reorder_quantity');
        });
    }
};
