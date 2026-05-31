<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // Hex colour (e.g. "#0F766E") used as the panel's primary accent
            // when the tenant is active. Null = fall back to platform Atriom
            // teal. Logo + favicon live on the MediaLibrary `logo` /
            // `favicon` collections (no schema change needed for those).
            $table->string('primary_color', 7)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('primary_color');
        });
    }
};
