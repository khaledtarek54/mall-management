<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the model said, and what it cost to say it (phase B).
 *
 * Recorded on the question itself rather than in a separate table, because every read of these
 * columns is a read of the question beside them: "which answers did the model write" and "what has
 * this month cost" are both questions about the same rows the miss list already ranks.
 *
 * All nullable and all zero-defaulted: with the `none` driver — the shipped default — every row
 * written looks exactly as it did before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_questions', function (Blueprint $table) {
            // Kept so a bad answer can be read back and argued with. It is the only record of what
            // an operator was actually shown; the passages can be re-derived, this cannot.
            $table->text('model_answer')->nullable()->after('result_count');

            // The ceiling is summed from these, so they are NOT NULL with a zero default — a null
            // would make SUM() quietly skip a call that really happened.
            $table->unsignedInteger('model_input_tokens')->default(0)->after('model_answer');
            $table->unsignedInteger('model_output_tokens')->default(0)->after('model_input_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_questions', function (Blueprint $table) {
            $table->dropColumn(['model_answer', 'model_input_tokens', 'model_output_tokens']);
        });
    }
};
