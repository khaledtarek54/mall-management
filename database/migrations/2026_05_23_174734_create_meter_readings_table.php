<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utility_meter_id')->constrained('utility_meters')->cascadeOnDelete();
            $table->date('reading_date');
            $table->decimal('reading_value', 14, 2);
            $table->decimal('consumption', 14, 2)->default(0);
            $table->decimal('cost', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['utility_meter_id', 'reading_date'], 'meter_reading_period_unique');
            $table->index('reading_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_readings');
    }
};
