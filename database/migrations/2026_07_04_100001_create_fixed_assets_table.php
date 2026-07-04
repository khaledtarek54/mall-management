<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed-asset register (module 23) — depreciable assets the operator owns
 * (furniture, equipment, HVAC, IT …), per property. Distinct from `assets`
 * (which are the malls/properties). Straight-line depreciation for now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('name');
            $table->string('tag', 40);                    // asset tag / label
            $table->string('category')->nullable();       // free-form (furniture / HVAC / IT …)
            $table->date('acquisition_date');
            $table->decimal('acquisition_cost', 14, 2);
            $table->decimal('salvage_value', 14, 2)->default(0);
            $table->unsignedInteger('useful_life_months');
            $table->string('method')->default('straight_line');
            $table->string('funded_from')->default('cash'); // cash|bank — the acquisition credit (Phase 2 GL)
            $table->enum('status', ['active', 'disposed'])->default('active');
            $table->date('disposed_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['asset_id', 'tag'], 'fixed_asset_asset_tag_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
