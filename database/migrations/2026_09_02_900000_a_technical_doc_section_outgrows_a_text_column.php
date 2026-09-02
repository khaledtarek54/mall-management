<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `search_blob` becomes MEDIUMTEXT, because a developer-documentation section is far longer than
 * an operator-facing one.
 *
 * MySQL's `TEXT` holds 65,535 BYTES — not characters — and Arabic spends two to four bytes each.
 * The operator corpus never came close; switching `assistant.index_technical_docs` on and indexing
 * `docs/modules/` failed on the sixty-first row, because a module doc's `##` section is a chapter
 * rather than a paragraph.
 *
 * Widened rather than truncated: the blob IS the search index, so a cap would make the tail of a
 * long section silently unfindable — the reader would get "no answer" for text that is sitting in
 * the file. MEDIUMTEXT is 16 MB, which no heading-sized section will ever approach.
 *
 * SQLite, where the suite runs, has no such limit and never saw this — the same driver divergence
 * this project records for enums and for `select tbl.*, x, *`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_doc_chunks', function (Blueprint $table) {
            $table->mediumText('search_blob')->change();
        });
    }

    public function down(): void
    {
        Schema::table('assistant_doc_chunks', function (Blueprint $table) {
            $table->text('search_blob')->change();
        });
    }
};
