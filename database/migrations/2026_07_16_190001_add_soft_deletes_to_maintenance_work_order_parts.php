<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A part record must be removable.
 *
 * A `recorded` external purchase has no approval step to catch a fat-finger — it is typed in
 * and it counts immediately — so a mistyped 99,999 EGP gasket was charged to the job forever:
 * there was no void, no delete, and no soft-delete column. Every other user-managed record in
 * this system soft-deletes; this one shipped without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_work_order_parts', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_work_order_parts', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
