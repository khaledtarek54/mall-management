<?php

use App\Services\SendAnnouncementAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An announcement stops being a notification and becomes something a tenant can READ.
 *
 * Until now `announcements` held four content columns — `title`, `body`, `asset_id`,
 * `created_by` — and the record existed only to be fanned out as a bell row and an FCM push.
 * There was no API endpoint, no portal screen, and no way back to it: once the notification
 * scrolled out of the inbox the notice was gone. It was also monolingual, in a market whose
 * tenants read Arabic, so every recipient read whatever language the operator happened to type.
 *
 * This adds the seven columns that turn the row into a post:
 *
 *   title_ar / body_ar   Both languages ship on every payload and the reader picks — the same
 *                        convention as `marketing_posts`. This is the column set that makes
 *                        `BellChannel`'s per-locale re-render mean something for announcements:
 *                        the bell already stores every supported language, but with one text
 *                        column there was only ever one answer to store.
 *   category             What KIND of notice (operations / event / emergency / hours / general).
 *                        A feed of undifferentiated notices is a feed nobody scans.
 *   status               draft | scheduled | sent. "Composing IS sending" was the old rule and it
 *                        cost the operator the one thing they most obviously want: writing the
 *                        Ramadan-hours notice on the 20th and having it go out on the 1st.
 *   publish_at           When a `scheduled` notice broadcasts. Null = send on command.
 *   expires_at           When it drops off the tenant feed. A "garage closed Friday" notice that
 *                        sits at the top of the app forever is worse than no feed.
 *   is_pinned            Ahead of the window, for the one notice that must stay first.
 *
 * **`sent_at` keeps its meaning and its authority.** `status` says what the operator intends;
 * `sent_at` records what actually happened. They are stamped together by
 * {@see SendAnnouncementAction} and only ever by it.
 *
 * **Backfill:** existing rows become `sent` when they carry a `sent_at`, and `draft` when they
 * do not. A null `sent_at` used to mean "queued, and if it stays null the worker never ran" —
 * an unrecoverable state, because the job is `tries=1` and there was no edit page or re-send.
 * Landing those rows in `draft` makes them repairable: the operator sees them, and the new Send
 * action broadcasts them for real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            // Arabic siblings. Nullable, not required: an operator composing in a hurry writes
            // one language, and refusing the notice would mean it does not go out at all.
            $table->string('title_ar', 120)->nullable()->after('title');
            $table->text('body_ar')->nullable()->after('body');

            // Plain strings, never DB enums (house rule) — the value sets live in
            // App\Support\ValueSets and are enforced on every model save.
            $table->string('category', 32)->default('general')->after('body_ar');
            $table->string('status', 32)->default('draft')->after('category');

            $table->timestamp('publish_at')->nullable()->after('status');
            $table->timestamp('expires_at')->nullable()->after('publish_at');
            $table->boolean('is_pinned')->default(false)->after('expires_at');

            // The scheduler's sweep: "scheduled notices whose time has come".
            $table->index(['status', 'publish_at'], 'announcements_due_idx');
            // The admin list, which is always inside one property and usually inside one state.
            $table->index(['asset_id', 'status'], 'announcements_asset_status_idx');
        });

        // Everything that was already broadcast is `sent`; everything stranded is a repairable
        // `draft`. Written as two statements rather than a CASE so it reads as the two rules it is.
        DB::table('announcements')->whereNotNull('sent_at')->update(['status' => 'sent']);
        DB::table('announcements')->whereNull('sent_at')->update(['status' => 'draft']);
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex('announcements_due_idx');
            $table->dropIndex('announcements_asset_status_idx');
            $table->dropColumn([
                'title_ar',
                'body_ar',
                'category',
                'status',
                'publish_at',
                'expires_at',
                'is_pinned',
            ]);
        });
    }
};
