<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The store-directory layer on `tenants` — who the retailer is to a SHOPPER.
 *
 * Every column on `tenants` today answers a billing or legal question: `name` is who we invoice,
 * `legal_name` is who signs, `tax_id` is who the ETA knows. None of that is who a shopper is
 * looking for. The tenant we invoice as "Crema Coffee Co. LLC" is the sign above the door that
 * says «كافيه كريما», and a marketing post rendered with the billing name is a card no shopper
 * recognises.
 *
 * `trade_name` is therefore NOT a duplicate of `name`. It is nullable and falls back to `name`
 * (see `Tenant::storeName()`), so nothing has to be backfilled for the feed to work — but the
 * moment a mall has a store whose brand differs from its billing entity (which is most of them),
 * there is somewhere correct to put it.
 *
 * **`is_listed` defaults to true, and that is a considered default.** The alternative — opt-in —
 * ships an empty directory and silently omits stores whose offers are already live, which reads to
 * an operator as a bug in the feed rather than a setting they never turned on. A tenant that should
 * not appear (an office lease, a back-of-house service) is switched off explicitly, which is the
 * rarer and more deliberate act. Note this exposes only the columns below: nothing added here is
 * confidential, and the public API allowlists fields rather than trusting this flag alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // The sign above the door, in both languages. Falls back to `name` when null.
            $table->string('trade_name')->nullable()->after('legal_name');
            $table->string('trade_name_ar')->nullable()->after('trade_name');

            // What kind of shop it is — how a directory is browsed and a feed is filtered.
            // String, not a DB enum (house rule); the catalogue lives on the model.
            $table->string('retail_category')->nullable()->after('trade_name_ar');

            // One or two sentences for the store page. Not the lease, not the legal description.
            $table->string('public_description', 500)->nullable()->after('retail_category');
            $table->string('public_description_ar', 500)->nullable()->after('public_description');

            $table->string('website_url', 255)->nullable()->after('public_description_ar');
            // Egyptian retail is Instagram-first; a handle is what a store actually gives you.
            $table->string('instagram_handle', 60)->nullable()->after('website_url');

            // Whether this retailer appears in the shopper-facing directory at all.
            // NOT NULL with a default — an unchecked Filament Toggle sends false, never null.
            $table->boolean('is_listed')->default(true)->after('instagram_handle');

            // The directory's query: listed stores of one category.
            $table->index(['is_listed', 'retail_category']);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['is_listed', 'retail_category']);
            $table->dropColumn([
                'trade_name',
                'trade_name_ar',
                'retail_category',
                'public_description',
                'public_description_ar',
                'website_url',
                'instagram_handle',
                'is_listed',
            ]);
        });
    }
};
