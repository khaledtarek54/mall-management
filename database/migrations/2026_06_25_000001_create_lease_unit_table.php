<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-unit leases (FR — master unit / #7): a lease may span several units,
 * one designated the master. This pivot is the source of truth for "which
 * units"; leases.unit_id stays as a denormalised pointer to the MASTER unit so
 * all existing single-unit code (lease->unit, occupancy, billing) keeps working.
 *
 * Backfill: every existing lease becomes a single-unit lease whose one unit is
 * the master — so all current leases stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_unit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->boolean('is_master')->default(false);
            $table->timestamps();

            $table->unique(['lease_id', 'unit_id']);
            $table->index('unit_id');
        });

        // Backfill: one master pivot row per existing lease, from leases.unit_id.
        DB::table('leases')->orderBy('id')->select('id', 'unit_id')->chunkById(500, function ($leases) {
            $now = now();
            $rows = [];
            foreach ($leases as $lease) {
                if ($lease->unit_id === null) {
                    continue;
                }
                $rows[] = [
                    'lease_id' => $lease->id,
                    'unit_id' => $lease->unit_id,
                    'is_master' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows) {
                DB::table('lease_unit')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_unit');
    }
};
