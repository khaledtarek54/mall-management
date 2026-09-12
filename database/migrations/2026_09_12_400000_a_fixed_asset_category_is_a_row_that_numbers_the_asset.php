<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The asset CLASS — SAP's word for it — as a row (meeting 2026-09-02, points 11 · 13 · 14).
 *
 * `fixed_assets.category` was free text with six seeded suggestions, the asset number (`tag`) was
 * typed by hand, and the salvage value, the useful life and the tax pool were each typed per asset
 * with nothing proposing them. The accountant asked for what every asset register does: pick the
 * category FIRST and let it carry the rest — the number series, the useful life, the memo value,
 * the tax class. SAP's asset class drives the number range, the depreciation key and the account
 * determination; Yardi Fixed Assets and Odoo's asset model carry the same defaults; auto-numbering
 * by class is universal.
 *
 * ## What a row names
 *
 * - `tag_prefix` — the number series. An asset entered with no tag is numbered `{prefix}-0001`,
 *   `-0002` … per PROPERTY (two malls each number their chillers from 1 — the identity the
 *   importer already keys on), under the document-number lock, and a tag the operator or a
 *   migrating register supplies is KEPT (the `AllocatesPartyCode` rule).
 * - `default_useful_life_months` — the life a new asset of this kind is proposed with; the form
 *   also reads it as an annual rate (12 ÷ months). Stored in months — one truth — shown both ways.
 * - `default_salvage_value` — the MEMO value. SAP depreciates a class to a memo value of one
 *   currency unit precisely so a fully-depreciated asset never shows at nil; the accountant's
 *   "salvage default 1" is that rule, and every shipped row carries 1.00. It is a PREFILL, never a
 *   derivation: what is on the asset is what depreciates.
 * - `default_tax_pool` — the Law 91/2005 class an asset of this kind usually falls in.
 *
 * ## The rows that already exist keep saving
 *
 * `fixed_assets.category` joins `ValueSets` with this migration, so a value with no row would be
 * REFUSED on the next save of an asset that carries it — the operator could not fix its name.
 * Every distinct value already stored is therefore seeded as a row here, labelled from the lang
 * group where we ship a label and from the value itself where the operator invented it, with a
 * prefix derived from the code. Nothing an install already holds is rewritten.
 */
return new class extends Migration
{
    /**
     * The codes `FixedAssetCategorySeeder` shipped on the day this ran — a SNAPSHOT, not a reference
     * to the seeder, because a migration is frozen and the seeder is not.
     */
    private const SHIPPED = ['furniture', 'equipment', 'HVAC', 'IT', 'vehicles', 'fit-out', 'generator', 'elevator'];

    public function up(): void
    {
        Schema::create('fixed_asset_categories', function (Blueprint $table) {
            $table->id();

            // The value `fixed_assets.category` stores, so no data migration is needed on the
            // register itself. 64 rather than 40: the column was free text, and a value an operator
            // typed is kept as its own code.
            $table->string('code', 64)->unique();

            $table->string('name_en', 96);
            $table->string('name_ar', 96);

            // The number series. Unique: two classes sharing a series would number into each
            // other, and the whole point of the prefix is that the tag says what kind of thing it is.
            $table->string('tag_prefix', 12)->unique();

            $table->unsignedSmallInteger('default_useful_life_months')->nullable();
            $table->decimal('default_salvage_value', 12, 2)->nullable();
            $table->string('default_tax_pool', 32)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'fixed_asset_cat_active_sort_index');
        });

        $this->backfillFromRegister();
    }

    /**
     * Every value the register already carries becomes a row, so the column's new value-set
     * guard refuses nothing an install already holds — and the register is REWRITTEN to the row's
     * spelling, because the guard compares strictly and the old form (free text, `trim()`-less)
     * stored whatever was typed.
     *
     * The SHIPPED codes are deliberately skipped: `FixedAssetCategorySeeder` (run by
     * `atriom:install --force` on every deploy and by `migrate --seed`) creates those with their
     * proposed life, memo value, pool and prefix, and it leaves an EXISTING row's defaults alone —
     * so a row this migration created for `HVAC` on a box that already held an HVAC asset would
     * have shipped the class with no life, no pool and a derived prefix, on exactly the installs
     * that have assets (the review caught it against the staging register). A shipped code stays
     * offered between the two steps through the per-code floor. Spellings are grouped
     * case-insensitively in PHP, because MySQL's `utf8mb4_unicode_ci` unique index would refuse
     * `HVAC` beside `hvac` while sqlite would not, and the seeder's own lookup is by the shipped
     * spelling.
     */
    public function backfillFromRegister(): void
    {
        $inUse = DB::table('fixed_assets')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        // Every spelling in use, grouped by its normalised form: the first spelling seen (in
        // `ORDER BY category`, so it is deterministic) is the canonical one.
        $groups = [];

        foreach ($inUse as $value) {
            $canonical = mb_substr(trim((string) $value), 0, 64);

            if ($canonical === '') {
                continue;
            }

            $groups[mb_strtolower($canonical)] ??= ['canonical' => $canonical, 'variants' => []];
            $groups[mb_strtolower($canonical)]['variants'][] = (string) $value;
        }

        $shippedByLower = [];
        foreach (self::SHIPPED as $shipped) {
            $shippedByLower[mb_strtolower($shipped)] = $shipped;
        }

        $taken = [];
        $sort = 1000;

        foreach ($groups as $lower => ['canonical' => $canonical, 'variants' => $variants]) {
            // A shipped code, in any spelling, is the seeder's row: only the register is corrected.
            $code = $shippedByLower[$lower] ?? $canonical;

            $rewrite = array_values(array_filter($variants, fn (string $v): bool => $v !== $code));
            if ($rewrite !== []) {
                DB::table('fixed_assets')->whereIn('category', $rewrite)->update(['category' => $code]);
            }

            if (isset($shippedByLower[$lower]) || DB::table('fixed_asset_categories')->where('code', $code)->exists()) {
                continue;
            }

            $prefix = $this->prefixFor($code, $taken);
            $taken[] = $prefix;
            $sort += 10;

            DB::table('fixed_asset_categories')->insert([
                'code' => $code,
                // The operator's own word, in both languages, until they rename it: a label we
                // never shipped has no translation, and inventing one is worse than repeating it.
                'name_en' => mb_substr($code, 0, 96),
                'name_ar' => mb_substr($code, 0, 96),
                'tag_prefix' => $prefix,
                'default_salvage_value' => 1.00,
                'is_active' => true,
                'sort_order' => $sort,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_categories');
    }

    /**
     * A series prefix derived from a code the operator typed — the letters and digits of it,
     * upper-cased, at most four, made unique against the prefixes already taken.
     *
     * @param  list<string>  $taken
     */
    private function prefixFor(string $code, array $taken): string
    {
        $base = strtoupper(substr((string) preg_replace('/[^A-Za-z0-9]/', '', $code), 0, 4)) ?: 'FA';
        $candidate = $base;
        $n = 1;

        while (in_array($candidate, $taken, true) || DB::table('fixed_asset_categories')->where('tag_prefix', $candidate)->exists()) {
            $candidate = substr($base, 0, 3).(++$n);
        }

        return $candidate;
    }
};
