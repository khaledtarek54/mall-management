<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            // Idempotency stamp for the tenant-facing lease-expiry reminder — each
            // lease row reminds once as it approaches expiry. A renewal is a NEW
            // lease row (previous_lease_id), so it gets its own fresh reminder.
            $table->timestamp('expiry_reminder_notified_at')->nullable()->after('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('expiry_reminder_notified_at');
        });
    }
};
