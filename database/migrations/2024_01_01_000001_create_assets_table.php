<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Haya Walk"
            $table->string('code')->unique(); // e.g., "HW"
            $table->enum('type', ['mall', 'retail_walk', 'mixed_use', 'office', 'residential'])
                ->default('mall');
            $table->string('address')->nullable();
            $table->string('city')->default('Cairo');
            $table->string('country')->default('Egypt');
            $table->decimal('total_area_sqm', 12, 2)->nullable();
            $table->decimal('leasable_area_sqm', 12, 2)->nullable();
            $table->string('currency', 3)->default('EGP');
            $table->json('metadata')->nullable(); // owner info, branding, etc.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['city', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
