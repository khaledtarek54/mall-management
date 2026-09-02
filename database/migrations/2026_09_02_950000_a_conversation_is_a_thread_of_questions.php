<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groups the assistant's questions into conversations.
 *
 * **A column, not a second table.** `assistant_questions` already records every turn — what was
 * asked, what the model answered, by whom, in which property and language. A separate
 * `assistant_messages` table would be a second copy of the same rows, and the miss list would then
 * have to read both or silently under-report whichever it did not. One truth, grouped.
 *
 * Nullable, because every row written before this belongs to no thread and inventing one would
 * merge months of unrelated questions into a single conversation nobody had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_questions', function (Blueprint $table) {
            $table->uuid('conversation_id')->nullable()->after('id');

            // The one read this exists for: "this reader's current thread, oldest first".
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('assistant_questions', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'id']);
            $table->dropColumn('conversation_id');
        });
    }
};
