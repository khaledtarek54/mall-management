<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('code'); // e.g., "A-01"
            $table->string('floor')->nullable(); // "Ground", "1", "2"
            $table->enum('category', [
                'retail',
                'food_beverage',
                'wellness',
                'service',
                'kiosk',
                'office',
                'storage',
            ])->default('retail');
            $table->decimal('area_sqm', 10, 2);
            $table->enum('status', ['vacant', 'reserved', 'occupied', 'maintenance'])
                  ->default('vacant');
            $table->text('description')->nullable();
            $table->json('features')->nullable(); // ['corner_unit', 'glass_facade', 'outdoor_seating']
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['asset_id', 'code']);
            $table->index(['asset_id', 'status']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
