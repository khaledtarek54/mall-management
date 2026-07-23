<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Violations were classified only by free-text `description` (module 31).
 *
 * An operator can't filter or report "how many signage violations this quarter" or "which tenants
 * repeat safety breaches" off free text — and a field officer wants to pick the kind, not retype it.
 * `category` is that classification. String, not a DB enum (project convention) — the operator's set
 * of violation types is theirs to extend without a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('violations', function (Blueprint $table) {
            $table->string('category')->nullable()->after('tenant_id');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::table('violations', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
