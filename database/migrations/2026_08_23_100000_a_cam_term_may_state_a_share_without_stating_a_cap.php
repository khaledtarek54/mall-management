<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **A CAM term may state a SHARE without stating a CAP.**
 *
 * `lease_cam_terms.cap_type` shipped `string(16)` NOT NULL with the set `absolute | yoy | both`.
 * There is no `none`, and the column cannot be null — so a term recording only a
 * `stated_share_pct` (the percentage the parties simply agreed, which
 * `2026_08_09_170000_choose_the_cam_denominator` added the column for) could not be written at all.
 * The row this table exists to hold was refused by the table.
 *
 * The MODEL already reads it the right way: `LeaseCamTerm::effectiveCap()` matches `cap_type`
 * against `['absolute', 'both']` and `['yoy', 'both']`, so a value in neither list yields no
 * ceiling. Null has always meant "no cap" to the logic; only the schema disagreed.
 *
 * Nullable rather than adding a `'none'` value, for the reason `charges.vat_applicable` and
 * `charges.billing_timing` are nullable: a fourth enum value would be a second way to say the same
 * thing, and every `in_array($this->cap_type, …)` in the codebase would have to learn it. Null is
 * already the absence the code handles.
 *
 * Nothing moves on deploy — every existing row holds one of the three values and keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_cam_terms', function (Blueprint $table) {
            $table->string('cap_type', 16)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Not reversible without inventing a value for the rows this change makes possible. A term
        // with a stated share and no cap has no honest answer among absolute|yoy|both, and guessing
        // one would silently give it a ceiling nobody agreed to.
        throw new RuntimeException(
            'Irreversible: rows written with a null cap_type have no valid value in the old set.'
        );
    }
};
