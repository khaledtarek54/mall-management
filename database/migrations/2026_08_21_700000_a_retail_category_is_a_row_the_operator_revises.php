<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The merchandising mix — twelve values in a PHP `const` on `Tenant`, driving the store directory,
 * the public shopper API's category filter, and every tenant-mix analysis an owner is shown.
 *
 * In Yardi and MRI this is a row, revised per mall and per season, because the mix is the leasing
 * team's working vocabulary: a mall that lands a cinema, a clinic cluster or a co-working floor
 * wants to say so in the directory that afternoon, not next release. Twelve categories also flatten
 * real differences an Egyptian operator cares about — a pharmacy and a gym are both `health_beauty`,
 * a phone shop and a white-goods showroom are both `electronics`.
 *
 * `tenants.retail_category` also had NO `ValueSets` entry, so the column accepted anything: a typo'd
 * or imported value saved cleanly and then matched no filter in the shopper app, appearing nowhere
 * while looking correct on the tenant record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retail_categories', function (Blueprint $table) {
            $table->id();

            // The value the tenant rows already store, so no data migration is needed.
            $table->string('code', 40)->unique();

            $table->string('name_en', 64);
            $table->string('name_ar', 64);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'retail_cat_active_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retail_categories');
    }
};
