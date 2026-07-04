<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed-asset disposal (module 23, Phase 2b) — one row per disposed asset. It is a
 * dedicated GL source (distinct from the FixedAsset acquisition source, since the
 * ledger keys one posted entry per source document) that journalizes the write-off:
 * remove gross cost + accumulated depreciation, recognise proceeds and the gain/loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_disposals', function (Blueprint $table) {
            $table->id();
            // One disposal per asset (disposal is terminal).
            $table->foreignId('fixed_asset_id')->unique()->constrained('fixed_assets')->cascadeOnDelete();
            $table->date('disposed_on');
            $table->decimal('proceeds', 14, 2)->default(0);      // sale proceeds; 0 = scrapped / written off
            $table->string('proceeds_account')->default('cash'); // cash|bank — where proceeds landed
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_disposals');
    }
};
