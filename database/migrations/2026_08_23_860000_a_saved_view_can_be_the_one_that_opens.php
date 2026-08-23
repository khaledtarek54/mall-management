<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **A saved view can be the one that opens** — the second half of UX-11.
 *
 * The row asks for two things: *"let a user save a table's filter+column state **and set one as
 * their default**"*. Only the first shipped. An operator could build *"active leases in this mall
 * whose option window shuts this quarter"*, name it, share it — and still land on the unfiltered
 * list every morning and pick it out of a menu.
 *
 * ## One boolean, two real cases
 *
 * `table_views.is_default` means *"this is the default for whoever can see it"*, which resolves
 * both defaults an operator actually wants:
 *
 *  - a PERSONAL default — an unshared view, seen only by its owner;
 *  - a TEAM default — a shared view the manager marks, so everyone lands on the arrears pack.
 *
 * A personal default WINS over a team one ({@see App\Models\TableView::defaultFor()}), so marking a
 * team view never overrides a colleague who has stated their own preference.
 *
 * The alternative — a `user_id × resource → view_id` preference table — expresses one more case
 * (adopting a colleague's shared view as your personal default without copying it) at the cost of a
 * whole model, its registries and a second place a default can live. Recorded rather than silently
 * dropped: if that case turns up, it is a table, not a rework.
 *
 * ## Why no unique index
 *
 * "At most one default per user per resource" is enforced in the write path, which clears the
 * user's siblings inside the same transaction. A partial unique index — `UNIQUE (user_id, resource)
 * WHERE is_default` — is the tighter guard and MySQL does not have partial indexes, so it would be
 * a unique on a nullable duplicate column, i.e. a second truth about the same fact. The failure
 * this could produce is two defaults for one user, whose consequence is that one of them opens;
 * that is a preference, not money.
 *
 * ## It stays a URL
 *
 * The default is applied by REDIRECTING to the view's own link, never by setting Livewire state.
 * `SavesTableViews` states that rule in writing — *"there is no second code path that sets Livewire
 * state directly"* — and honouring it here is what keeps the address bar honest: the operator can
 * see which view they are on, and paste it. The menu grows an *"All records"* escape carrying
 * `?tableView=none`, because a link with an EMPTY query string is exactly what triggers the
 * redirect, so the obvious "just link to the plain list" reset would bounce straight back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('table_views', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_shared');

            // The lookup the mount hook runs on every bare page load of every list that opts in.
            $table->index(['resource', 'is_default'], 'table_views_default_idx');
        });
    }

    public function down(): void
    {
        Schema::table('table_views', function (Blueprint $table) {
            $table->dropIndex('table_views_default_idx');
            $table->dropColumn('is_default');
        });
    }
};
