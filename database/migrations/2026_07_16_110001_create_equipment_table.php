<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The maintainable-asset (equipment) register — module 26, FR-PPM-03/04/05.
 *
 * The FRD is asset-centric: every AC unit, escalator and pump carries a unique code, with
 * sub-codes for its components. Nothing in Atriom expressed that — `Asset` is the *mall*,
 * `Unit` is a *storefront*, and `FixedAsset` is a *depreciation record*. This is the
 * missing grain: "chiller CH-01" and "CH-01-PMP, its pump".
 *
 * **Not an extension of `FixedAsset`.** That is an accounting object under `fixed_assets.*`
 * RBAC — a maintenance engineer cannot even see it — and it exists to be depreciated. Not
 * every maintainable machine is capitalised, and not every capitalised asset is
 * maintainable. `fixed_asset_id` links the two when they happen to be the same object, so
 * finance and maintenance each keep their own register without double data entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            // Sub-codes (FR-PPM-04). nullOnDelete, NOT cascade: deleting a parent must not
            // silently take its components' maintenance history with it — the children are
            // promoted to roots instead. The same-property + acyclicity rules live in the
            // model, which is the only writer.
            $table->foreignId('parent_id')->nullable()->constrained('equipment')->nullOnDelete();

            $table->string('code', 40);                   // ESC-01 · ESC-01-MOT
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('category')->nullable();       // reuses the module's category labels
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete(); // null = common area
            $table->string('location')->nullable();       // free text: "Roof, zone B"
            $table->foreignId('fixed_asset_id')->nullable()->constrained('fixed_assets')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Per-property, mirroring warehouses.code and fixed_assets.tag. A portfolio-wide
            // unique would force a mall prefix into every code, which is exactly what the
            // asset_id column is for.
            $table->unique(['asset_id', 'code'], 'equipment_asset_code_unique');
            $table->index(['asset_id', 'parent_id'], 'equipment_asset_parent_index');
            $table->index('is_active');
        });

        // Compatible spare parts (FR-PPM-05) — "which parts fit escalator ESC-01?".
        // A pivot, not a column on inventory_items: that catalog is deliberately SHARED and
        // unscoped ("a pump seal is the same item everywhere"), so it cannot carry a
        // property-owned FK — PropertyIsolationConformanceTest enforces exactly that.
        Schema::create('equipment_inventory_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['equipment_id', 'inventory_item_id'], 'equipment_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_inventory_item');
        Schema::dropIfExists('equipment');
    }
};
