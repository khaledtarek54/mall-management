<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            // Which operator department owns this work-order. Nullable — a
            // request can sit unassigned until triaged (FR MNT-2). Redirecting
            // to another department just updates this column (FR MNT-3).
            $table->foreignId('department_id')
                ->nullable()
                ->after('assigned_to_vendor_id')
                ->constrained('departments')
                ->nullOnDelete();
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropIndex(['department_id']);
            $table->dropColumn('department_id');
        });
    }
};
