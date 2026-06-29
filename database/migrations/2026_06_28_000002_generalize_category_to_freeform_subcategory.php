<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 1, Phase 1: the maintenance `category` (a DB enum of electrical/plumbing/
 * …) becomes a free-form nullable string so it can hold any request type's
 * sub-category (parking, lease_copy, …) — the values now live in
 * TenantRequestType::subcategories(), not the DB. Column name kept as `category`
 * for now; a cosmetic rename to `subcategory` can follow later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->string('category')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        $allowed = ['electrical', 'plumbing', 'hvac', 'structural', 'cleaning', 'safety', 'other'];

        // Rows added since up() may hold null or a non-maintenance sub-category
        // (parking, lease_copy, …) that the original NOT-NULL enum can't store —
        // normalise them to 'other' first so restoring the constraint can't fail
        // (MySQL strict mode) or silently lose data.
        DB::table('maintenance_requests')
            ->where(fn ($q) => $q->whereNull('category')->orWhereNotIn('category', $allowed))
            ->update(['category' => 'other']);

        Schema::table('maintenance_requests', function (Blueprint $table) use ($allowed) {
            $table->enum('category', $allowed)->default('other')->change();
        });
    }
};
