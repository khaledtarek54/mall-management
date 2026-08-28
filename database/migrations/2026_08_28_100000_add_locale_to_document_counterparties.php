<?php

use App\Support\Pdf\DocumentLocale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language a SUPPLIER and an EMPLOYEE read their documents in.
 *
 * `2026_08_12_260000_add_locale_to_notifiables` gave the column to everyone a NOTIFICATION is
 * addressed to — users, portal logins, tenants. That was the right set for a notification and the
 * wrong one for a document: on 2026-08-27 every PDF became answerable to
 * {@see DocumentLocale}, which resolves the recipient's own language before the
 * operator's, and two of the counterparties this system issues documents to were not in that set.
 *
 *   - A **purchase order** and a **withholding-tax certificate** go to a SUPPLIER. The certificate
 *     especially: it is the document a supplier hands to their own accountant to claim tax already
 *     deducted from them, and it was being written in whichever language the operator's panel
 *     happened to be set to.
 *   - A **payslip** goes to a PERSON. An employee who reads only Arabic being handed an English
 *     breakdown of their own deductions is the plainest case the whole change exists for.
 *
 * Both fell through to the operator's language, with the download picker as the only remedy — which
 * works, and asks an operator to remember a fact about the recipient that the recipient could simply
 * have told us.
 *
 * Nullable, and null is the normal state: it means "no preference stated", which resolves to whoever
 * is producing the document. It is not a migration's job to guess what language anybody reads.
 *
 * Neither model is `Notifiable`, so neither implements `HasLocalePreference` — `DocumentLocale`
 * reads a plain `locale` attribute for exactly this case. If either ever starts receiving
 * notifications, adding the interface is what makes Laravel render them in this language too.
 */
return new class extends Migration
{
    /** table => the column to place it after (ignored by SQLite, which is only used in tests). */
    private const TABLES = ['vendors' => 'email', 'employees' => 'phone'];

    public function up(): void
    {
        foreach (self::TABLES as $table => $after) {
            if (Schema::hasColumn($table, 'locale')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($after): void {
                // A BCP-47 primary subtag; 'en' / 'ar' today. String, not an enum — adding a third
                // language must not need a migration (see CLAUDE.md). The set it may hold is
                // registered in `App\Support\ValueSets`, which refuses anything else on save.
                $blueprint->string('locale', 5)->nullable()->after($after);
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            if (! Schema::hasColumn($table, 'locale')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('locale');
            });
        }
    }
};
