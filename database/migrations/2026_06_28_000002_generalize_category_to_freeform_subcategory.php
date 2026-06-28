<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->enum('category', [
                'electrical', 'plumbing', 'hvac', 'structural', 'cleaning', 'safety', 'other',
            ])->default('other')->change();
        });
    }
};
