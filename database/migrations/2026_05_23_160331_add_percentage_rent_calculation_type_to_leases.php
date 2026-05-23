<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->enum('percentage_rent_calculation_type', ['natural_breakpoint', 'artificial'])
                ->nullable()
                ->after('percentage_rent_rate');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('percentage_rent_calculation_type');
        });
    }
};
