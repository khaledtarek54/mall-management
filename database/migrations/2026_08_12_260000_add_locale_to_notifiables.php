<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A language preference that OUTLIVES the session, on everyone a notification is addressed to.
 *
 * Until now the choice lived in `session('locale')` and nowhere else, which is fine for the screen
 * you are looking at and useless for everything that arrives while you are not looking:
 *
 *   - a scheduled command has no session at all, so every alert it raised — overdue invoices, SLA
 *     breaches, expiring documents, the whole nightly sweep — rendered in `config('app.locale')`,
 *     i.e. English, for every reader including Arabic-only tenants;
 *   - a notification raised inside a REQUEST rendered in the SENDER's language, so an operator
 *     working in Arabic issued invoices whose emails reached English-speaking tenants in Arabic.
 *
 * With a stored preference, Laravel does the rest itself: `NotificationSender` wraps every
 * notifiable's channel dispatch in `withLocale($notifiable->preferredLocale())` when the model
 * implements `HasLocalePreference`. Mail, push and the database row all render in the recipient's
 * language rather than in whoever's language happened to be current.
 *
 * Nullable on purpose — null means "no preference stated", which resolves to the app default. It is
 * not a migration's job to guess what language an existing user reads.
 */
return new class extends Migration
{
    /** Every table whose rows can be the target of a notification. */
    private const TABLES = ['users', 'tenant_users', 'tenants'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'locale')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                // A BCP-47 primary subtag; 'en' / 'ar' today. String, not an enum — adding a third
                // language must not need a migration (see CLAUDE.md).
                $blueprint->string('locale', 5)->nullable()->after('email');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'locale')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('locale');
            });
        }
    }
};
