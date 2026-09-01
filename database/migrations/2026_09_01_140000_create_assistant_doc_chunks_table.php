<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operator-facing documentation, chunked by heading, so the assistant can reach past the
 * screen guides.
 *
 * A build artefact, not data: every row is derived from a markdown file in the repository and the
 * whole table is rebuilt by `atriom:rebuild-assistant-index`. Nothing an operator does writes here,
 * and losing it loses nothing — the assistant simply stops offering its third tier until the
 * command runs again.
 *
 * **Kept in a table rather than a cached in-memory index** because it is inspectable: "why did it
 * answer that" is a SELECT, not a debugger. And it is queried with the same folded `LIKE` every
 * other search in this codebase uses, rather than a driver-specific FULLTEXT — the suite runs on
 * SQLite and production runs MySQL, and this project has been bitten three times by a search that
 * was green on one and wrong on the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_doc_chunks', function (Blueprint $table) {
            $table->id();

            // Where it came from, so a bad answer can be traced to the paragraph that caused it.
            $table->string('path');
            $table->string('locale', 8);
            $table->string('heading');

            // Where the reader goes. Null for a source that is not published anywhere a browser
            // can reach — the training walkthroughs live in the repository, so their excerpt IS
            // the answer rather than a pointer to one.
            $table->string('url')->nullable();

            $table->text('excerpt');
            $table->text('search_blob');

            $table->timestamps();

            $table->index(['locale', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_doc_chunks');
    }
};
