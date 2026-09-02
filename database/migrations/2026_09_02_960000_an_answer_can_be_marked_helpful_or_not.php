<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the reader found the answer useful.
 *
 * **This replaces a signal that has quietly stopped working.** The A phase's deliverable was the
 * list of questions that matched NOTHING — and measured on 45 real questions, that list is now
 * empty: with 189 corpus entries and 1,050 documentation sections, something always matches. So
 * "did it find anything" can no longer distinguish a good answer from a confident wrong one, and
 * without a replacement the feature would look permanently healthy while being wrong.
 *
 * Nullable and three-valued on purpose: `null` is "not asked", not "no". Most answers will never be
 * rated, and treating silence as a negative would make the first useful metric a lie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_questions', function (Blueprint $table) {
            $table->boolean('was_helpful')->nullable()->after('model_output_tokens');

            // The read this exists for: "what did people mark wrong, most recent first".
            $table->index(['was_helpful', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('assistant_questions', function (Blueprint $table) {
            $table->dropIndex(['was_helpful', 'created_at']);
            $table->dropColumn('was_helpful');
        });
    }
};
