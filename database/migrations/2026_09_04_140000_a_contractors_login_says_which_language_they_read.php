<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language a CONTRACTOR's login reads.
 *
 * `2026_08_12_260000_add_locale_to_notifiables` gave this column to everyone a NOTIFICATION is
 * addressed to — users, portal logins, tenants — and `2026_08_28_100000` to the two counterparties
 * a DOCUMENT goes to, a supplier and an employee. The vendor portal shipped on 2026-08-28 with its
 * own guard and its own login model and got neither, so `/locale/{locale}` had nowhere to write a
 * contractor's choice: it went into the session and was gone at the next sign-in, and no scheduled
 * notification could ever know it.
 *
 * `vendors.locale` is the COMPANY's language, for its purchase orders and withholding certificates.
 * This is the PERSON's, for what they read on screen and in the mail addressed to them.
 *
 * Nullable, and null is the normal state — "nobody has said", which resolves to whoever is looking.
 * It is not a migration's job to guess what language anybody reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vendor_contacts', 'locale')) {
            return;
        }

        Schema::table('vendor_contacts', function (Blueprint $table): void {
            // A BCP-47 primary subtag; 'en' / 'ar' today. String, not an enum — a third language
            // must not need a migration (see CLAUDE.md). What it may hold is registered in
            // `App\Support\ValueSets`, which refuses anything else on save.
            $table->string('locale', 5)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vendor_contacts', 'locale')) {
            return;
        }

        Schema::table('vendor_contacts', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
