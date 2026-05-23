<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_meters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('meter_number')->unique();
            $table->enum('type', ['electric', 'water', 'gas']);
            $table->string('provider')->nullable();
            $table->enum('status', ['active', 'inactive', 'faulty'])->default('active');
            $table->string('unit_of_measurement', 16)->nullable(); // kWh, m3, etc.
            $table->timestamps();
            $table->softDeletes();

            $table->index(['asset_id', 'type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_meters');
    }
};
