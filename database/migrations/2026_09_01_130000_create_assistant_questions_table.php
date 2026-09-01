<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What people actually asked the assistant, and whether it had an answer.
 *
 * This table IS the deliverable of the A phase. The assistant itself is a search box over material
 * that already exists; what does not exist anywhere is a record of the questions an operator asks
 * that nothing in the system answers. That list — `matched = false`, grouped by `question_folded` —
 * is the only honest input to whether a language model is worth paying for, and to what the missing
 * screen guides are.
 *
 * **The folded form is stored beside the raw one.** Two people asking the same thing in different
 * spellings («فاتورة» / «فاتوره», "Credit Note" / "credit note") must group as one question, and
 * folding at read time would mean re-folding the whole table on every analysis. The raw text stays
 * because the folding is lossy and the exact wording is what tells you how somebody thinks about
 * the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_questions', function (Blueprint $table) {
            $table->id();

            // The property the question was asked in. Nullable because the column is written from
            // the panel's tenant and a future caller (a console replay of the miss list, the portal)
            // may legitimately have none — a NOT NULL here would turn that into a crash rather than
            // a blank cell.
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('question', 500);
            $table->string('question_folded', 500);
            $table->string('locale', 8);

            // Did anything score above the floor. Stored rather than derived from `top_key` being
            // null, because the floor is a tunable and yesterday's answer must not change when it
            // moves — the miss list would silently rewrite itself.
            $table->boolean('matched')->default(false);

            $table->string('top_kind', 32)->nullable();
            $table->string('top_key', 64)->nullable();
            $table->unsignedInteger('top_score')->default(0);
            $table->unsignedSmallInteger('result_count')->default(0);

            $table->timestamps();

            // The two reads this table exists for: "what did nobody get an answer to" and
            // "what is asked most".
            $table->index(['matched', 'created_at']);
            $table->index('question_folded');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_questions');
    }
};
