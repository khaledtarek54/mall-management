<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // "Base Rent", "Service Charge", "Utilities"
            $table->enum('type', [
                'base_rent',
                'service_charge',
                'utility',
                'parking',
                'percentage_rent',
                'other',
            ]);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('EGP');
            $table->enum('frequency', ['monthly', 'quarterly', 'annually', 'one_time'])
                  ->default('monthly');
            $table->boolean('vat_applicable')->default(true);
            $table->decimal('vat_rate', 5, 2)->default(14.00);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['lease_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};
