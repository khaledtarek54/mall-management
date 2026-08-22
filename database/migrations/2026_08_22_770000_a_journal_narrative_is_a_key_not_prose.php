<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A journal entry's narrative becomes a KEY plus its data (EG-36, finding S-12).
 *
 * Twenty-four journalizers wrote Arabic and English prose literals into `description_ar` /
 * `description_en` at post time — *"فاتورة INV-0001"* — which contradicts this project's own rule
 * for the activity log, stated in CLAUDE.md as **"it stores DATA, never PROSE"**. The consequences
 * are the same ones that rule exists to prevent: a wording fix needs a deploy, it never reaches a
 * row already posted, and a third language means re-posting history.
 *
 * The pattern is already here twice — `ActivityVocabulary` resolves a stored `event` and field keys
 * at READ time, and `name_en`/`name_ar` on the catalogues carry an operator's own words. This is the
 * first of those, for the ledger.
 *
 * ## The prose columns STAY, and are still written
 *
 * They become a snapshot and a floor rather than the truth. Three reasons, and the third is the one
 * that decided it:
 *
 *   1. Every row posted before today has prose and no key — it must keep reading correctly for ever,
 *      because a ledger is evidence and history is never rewritten here.
 *   2. `search_text` folds the narrative, and the blob is *"a pure function of the row's OWN
 *      attributes"*; a resolved string is still that, but the stored copy keeps a raw reader honest.
 *   3. A read site nobody converted degrades to **today's wording**, not to a blank cell. On a
 *      general ledger a missing description is indistinguishable from an entry nobody described.
 *
 * So `JournalNarrative::resolve()` prefers the key and falls back to the prose, and a wording change
 * reaches every read that goes through it — which is what the finding asked for.
 *
 * Nothing re-posts. `LedgerPoster::sync()`'s `matches()` compares lines, date and asset and
 * deliberately not text (`ChangeImpact` classifies these columns DESCRIPTIVE), so adding a key
 * cannot void and re-post an entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            // Registered in `App\Support\JournalNarrative::KEYS`; null means "prose only", which is
            // every row posted before this migration and every hand-written manual entry.
            $table->string('description_key', 64)->nullable()->after('description_ar');

            // The placeholders the narrative needs — `{"number": "INV-0001"}`. A json column rather
            // than a second string, because a narrative that names two things (an SLA penalty names
            // its reference AND the bill) would otherwise need parsing back out of one.
            $table->json('description_data')->nullable()->after('description_key');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropColumn(['description_key', 'description_data']);
        });
    }
};
