<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mall's rule book — seven values in a PHP `const` on `Violation`.
 *
 * `2026_07_23_180000_add_category_to_violations` said, in writing, that "the operator's set of
 * violation types is theirs to extend without a migration". It was not true: the set lived in
 * `Violation::CATEGORIES`, was labelled from `admin.violations.categories` in two languages, and was
 * read by a form, a filter and a fine invoice. Adding "blocked fire exit" or "unlicensed subletting"
 * was a five-file deploy.
 *
 * It is a rule book, which is exactly the kind of thing an operator revises: a mall publishes house
 * rules in its tenant handbook, amends them when a problem recurs, and its field officers cite the
 * clause on the notice. Seven generic buckets cannot carry that.
 *
 * `violations.category` also had NO `ValueSets` entry, so the column accepted anything — an import
 * or a typo saved cleanly and then matched no filter and no report, while looking correct on the
 * record. That is the same defect the expense-category and retail-category work found.
 *
 * ## Why the row names a FINE
 *
 * Every catalogue in this codebase names the thing its code decides — a rail names its bank
 * account, an expense category names its P&L account, a request subcategory names its trade. A
 * violation category names the standard fine, because a rule book is a schedule of penalties and a
 * field officer should not be recalling the tariff from memory at the shop door.
 *
 * It is a PREFILL and nothing more. `violations.fine_amount` stays the operator's number, is never
 * recomputed from here, and a category whose tariff changes leaves every recorded violation alone —
 * for the same reason an issued invoice keeps the VAT rate it was billed at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('violation_categories', function (Blueprint $table) {
            $table->id();

            // The value the violation rows already store, so no data migration is needed.
            $table->string('code', 40)->unique();

            $table->string('name_en', 96);
            $table->string('name_ar', 96);

            // Nullable: "no standard fine" and "the standard fine is zero" are different claims, and
            // most house rules are warned about before they are charged for.
            $table->decimal('default_fine_amount', 12, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'violation_cat_active_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('violation_categories');
    }
};
