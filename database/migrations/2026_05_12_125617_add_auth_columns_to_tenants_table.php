<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');
            $table->timestamp('email_verified_at')->nullable()->after('password');
            $table->rememberToken()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['password', 'email_verified_at', 'remember_token']);
        });
    }
};
