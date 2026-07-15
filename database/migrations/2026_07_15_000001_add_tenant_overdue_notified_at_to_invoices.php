<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Idempotency stamp for the tenant-facing overdue reminder — kept
            // separate from owner_overdue_notified_at so the tenant reminder and
            // the owner alert fire (and re-fire safely) independently.
            $table->timestamp('tenant_overdue_notified_at')->nullable()->after('owner_overdue_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('tenant_overdue_notified_at');
        });
    }
};
