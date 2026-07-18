<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facility zones (module 30) — a mall is divided into operational areas
 * (Ground Floor, Food Court, Parking, Roof Plant…) so that, in a later slice,
 * incoming requests / work orders can be routed to the zone's supervisor(s).
 *
 * An Area stands in exactly one property (direct `asset_id`, like Unit /
 * Warehouse / Equipment), and its `code` is unique within that property — a
 * portfolio-wide unique would force a mall prefix into every code, which is
 * exactly what the asset_id column is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 40);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Per-property, mirroring warehouses.code / equipment (asset_id, code).
            $table->unique(['asset_id', 'code'], 'areas_asset_code_unique');
            $table->index('is_active');
        });

        // Supervisors (many-to-many with staff Users). A supervisor may cover
        // many areas; an area may have many supervisors. No model of its own —
        // a plain pivot, exactly like equipment_inventory_item, so it stays
        // outside the PropertyIsolation model registry (nothing to classify).
        Schema::create('area_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained('areas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['area_id', 'user_id'], 'area_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_user');
        Schema::dropIfExists('areas');
    }
};
