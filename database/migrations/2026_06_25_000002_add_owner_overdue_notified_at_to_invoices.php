<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency stamp for the "overdue invoice → notify Jawad owner" alert
 * (req #4, invoice half). Mirrors maintenance.sla_breach_notified_at so each
 * overdue invoice surfaces to the owner once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('owner_overdue_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('owner_overdue_notified_at');
        });
    }
};
