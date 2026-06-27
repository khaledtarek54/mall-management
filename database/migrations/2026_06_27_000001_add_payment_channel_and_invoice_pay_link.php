<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which surface initiated the payment — keeps the online payment-link
        // flow and the in-app mobile flow cleanly separated (different return
        // handling, reporting, session reuse scoping).
        Schema::table('payments', function (Blueprint $table) {
            $table->string('channel')->nullable()->after('gateway')->index();
        });

        // Stable, unguessable token behind the public pay link /pay/{token}.
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_link_token', 64)->nullable()->unique()->after('number');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['payment_link_token']);
            $table->dropColumn('payment_link_token');
        });
    }
};
