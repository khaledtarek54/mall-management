<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A general-ledger view saved before "Every account" existed means ONE account.
 *
 * `GeneralLedger::$allAccounts` shipped on 2026-09-12 and is a saved-view parameter. A view saved
 * earlier carries no such key, and `ReportParameters::apply()` deliberately leaves an absent key
 * alone — so on the delivery path the value came from `ReportPreferences::restore()` in `mount()`,
 * i.e. from whatever the OWNER last had the toggle at. Measured: a view saved as "cash, monthly"
 * emailed the whole ledger the month after its owner browsed every account once, to the external
 * accountant. Stating the key on every older view closes the window; views saved since always
 * carry it (the snapshot keeps `false`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('saved_reports')
            ->where('report', 'general_ledger')
            ->orderBy('id')
            ->each(function (object $view): void {
                $parameters = json_decode((string) $view->parameters, true) ?: [];

                if (array_key_exists('allAccounts', $parameters)) {
                    return;
                }

                $parameters['allAccounts'] = false;

                DB::table('saved_reports')->where('id', $view->id)->update(['parameters' => json_encode($parameters)]);
            });
    }

    public function down(): void
    {
        // The key is harmless to leave: it states what those views always meant.
    }
};
