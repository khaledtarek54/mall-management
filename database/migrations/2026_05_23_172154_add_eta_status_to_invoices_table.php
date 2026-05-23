<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('eta_status', ['pending', 'submitted', 'valid', 'invalid', 'rejected', 'cancelled'])
                ->nullable()
                ->after('eta_response');
            $table->string('eta_long_id')->nullable()->after('eta_status');
            $table->index('eta_status');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['eta_status']);
            $table->dropColumn(['eta_status', 'eta_long_id']);
        });
    }
};
