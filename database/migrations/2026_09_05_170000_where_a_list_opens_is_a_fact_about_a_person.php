<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which saved view a list opens on is a fact about a PERSON, not a column on a shared row.
 *
 * `table_views.is_default` answered two questions at once — "this is where I start" and "this is
 * where the team starts" — and a view its owner had shared AND marked was both at the same time.
 * Everything that followed came from that:
 *
 *  - a colleague adopting a shared view wrote the flag on somebody else's row, so one click moved
 *    the landing screen for every colleague who had not set their own;
 *  - clearing "other shared defaults" to keep one team default — which reads as tidy — wiped a
 *    preference its owner had stated, because no query can tell the two meanings apart;
 *  - and a person with no view of their own could not opt OUT of a team default at all, because
 *    there was nowhere to record "not for me".
 *
 * A row per (person, list) answers all three: a `table_view_id` is where I start, and a NULL one
 * is the explicit "no default for me" that had nowhere to live. `is_default` keeps the other
 * meaning alone — the team's starting point, set by the view's owner.
 *
 * Modelled on `report_preferences`, the existing per-user preference table: same shape, same
 * reasoning, not activity-logged (it changes what one person SEES, never what anyone is charged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_view_defaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The resource key, matching `table_views.resource` — a list is identified the same
            // way on both tables or a default could never be found again.
            $table->string('resource', 64);

            // NULL is an ANSWER, not a missing row: "open this list plainly, whatever the team
            // default says".
            //
            // **`cascadeOnDelete`, NOT `nullOnDelete`** — and the difference is the whole point of
            // the table. Nulling the pointer when a view is deleted would produce, byte for byte,
            // the state that means "I deliberately chose no default", so deleting a shared view
            // would silently opt every one of its followers OUT of the team default too. Absence
            // of a row means "has not chosen" and falls through to the team; a stored NULL means
            // they chose nothing on purpose. Two facts, two representations — collapsing them is
            // exactly the conflation `is_default` was suffering from.
            $table->foreignId('table_view_id')->nullable()->constrained('table_views')->cascadeOnDelete();

            $table->timestamps();

            // One answer per person per list. The invariant `makeDefault()` had to keep by hand
            // on the old column — and could not, because the column was shared.
            $table->unique(['user_id', 'resource']);
        });

        // Every existing default becomes its OWNER's personal default, so nobody's list changes
        // where it opens on the day this ships. A SHARED row keeps `is_default` as well: that flag
        // now means only "the team starts here", which is what it meant for everyone else already.
        // **`orderBy('id')` is load-bearing**, not tidiness. One user can legitimately hold TWO
        // flagged rows on one list today — they own a private view marked as their default, and a
        // colleague adopted their SHARED view, which set the flag on it while leaving the private
        // one standing. The rule this replaces resolved that by `orderBy('id')->first()`, so the
        // backfill has to insert the same one; `insertOrIgnore` would otherwise swallow whichever
        // the driver happened to return second, and the promise below would quietly be false.
        foreach (DB::table('table_views')->where('is_default', true)->orderBy('id')->get() as $view) {
            DB::table('table_view_defaults')->insertOrIgnore([
                'user_id' => $view->user_id,
                'resource' => $view->resource,
                'table_view_id' => $view->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // `is_default` now means ONE thing — the team starts here — and that is meaningless on a
        // row nobody else can see. Left standing it would be dead data that a rollback resurrects
        // as a personal default nobody set, so it is cleared once its meaning has been carried
        // into the pivot above.
        DB::table('table_views')->where('is_default', true)->where('is_shared', false)
            ->update(['is_default' => false]);
    }

    public function down(): void
    {
        Schema::dropIfExists('table_view_defaults');
    }
};
