<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add 'marketing' to the charges.type enum so the 5% marketing levy is a
 * first-class charge type (FR MKT-2), billed by the existing engine alongside
 * rent / service charge / CAM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->enum('type', [
                'base_rent', 'service_charge', 'utility', 'parking',
                'percentage_rent', 'marketing', 'other',
            ])->change();
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->enum('type', [
                'base_rent', 'service_charge', 'utility', 'parking',
                'percentage_rent', 'other',
            ])->change();
        });
    }
};
