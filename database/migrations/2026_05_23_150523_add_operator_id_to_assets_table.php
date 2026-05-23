<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('operator_id')
                ->nullable()
                ->after('id')
                ->constrained('operators')
                ->nullOnDelete();

            $table->index('operator_id');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['operator_id']);
            $table->dropIndex(['operator_id']);
            $table->dropColumn('operator_id');
        });
    }
};
