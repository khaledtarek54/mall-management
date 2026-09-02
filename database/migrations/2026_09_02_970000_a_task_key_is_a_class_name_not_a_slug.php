<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `top_key` widens, because a task's key is a fully-qualified class name.
 *
 * The column was 64 characters, sized when every key was a short slug — `ar_aging`,
 * `credit_notes`. The task tier keys on the RESOURCE, and
 * `App\Filament\Admin\Resources\PostDatedCheques\PostDatedChequeResource` is 68, so asking about
 * post-dated cheques threw SQLSTATE[22001] and the whole question 500'd. Several resources are
 * longer still.
 *
 * Widened rather than truncated: the key is what the report groups on, and a truncated class name
 * groups two different resources together while looking like a legitimate value.
 *
 * SQLite has no length limit on a varchar, so the suite never saw it — the same driver divergence
 * this project records for enums, for `select tbl.*, x, *`, and for the doc-chunk TEXT column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_questions', function (Blueprint $table) {
            $table->string('top_key', 191)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('assistant_questions', function (Blueprint $table) {
            $table->string('top_key', 64)->nullable()->change();
        });
    }
};
