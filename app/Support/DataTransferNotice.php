<?php

namespace App\Support;

use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * **What an operator is told when a bulk import or export finishes — in the language they work in.**
 *
 * All ten importers and all nine exporters wrote this sentence as an ENGLISH LITERAL, each holding
 * its own copy of the same three clauses and not one of them calling `__()`. The other half of the
 * very same notification IS translated: Filament's `Importer::getCompletedNotificationTitle()`
 * returns `__('filament-actions::import.notifications.completed.title')` and the package ships
 * Arabic for it (`vendor/filament/actions/resources/lang/ar/import.php:46`, «اكتمل الاستيراد»). So
 * an operator working the panel in Arabic read an Arabic heading over "Your chart of accounts
 * import has completed and 167 rows imported." — one notification in two languages, on the screen
 * that tells them whether a cut-over worked. (167 is the dev database's chart, measured 2026-09-04,
 * i.e. the sentence a real import of it produces.)
 *
 * ## The sentence is written per transfer, never composed from a noun
 *
 * A `:records` placeholder inside one template sentence is right for half a catalogue and wrong for
 * the other half in Arabic, which governs a noun by definiteness and case — the same reason the
 * pickers' empty-state wording is written out rather than templated. WHICH sentence to use is
 * DERIVED from the class name (`LedgerAccountImporter` → `admin.data_transfer.import.ledger_account`),
 * so a new importer needs a key in both lang files and no code here. A missing key renders as the
 * key itself, which is why `ABulkTransferSaysSoInTheOperatorsLanguageTest` fails the build on one
 * rather than letting it reach an operator.
 *
 * ## It renders in the CURRENT locale, exactly as Filament's title does — and deliberately not in
 * ## the recipient's
 *
 * With `IMPORT_QUEUE_CONNECTION` / `EXPORT_QUEUE_CONNECTION` set, Filament builds this notification
 * inside the queue worker (`ImportAction`'s batch `finally`, `ExportCompletion::handle()`), where
 * the title AND this body resolve to `config('app.locale')` rather than to whoever pressed the
 * button. Resolving the recipient here and not there would put an Arabic body under an English
 * heading — worse than the uniform result, and the same half-fix this project already recorded for
 * the financial statements ("fixing the screen and CSV and not the PDF is worse than fixing
 * neither"). The title is Filament's to render; recorded here rather than half-fixed. On the
 * shipped default both connections are `sync`, so the notification is built inside the operator's
 * own request and this resolves to their session language.
 */
final class DataTransferNotice
{
    public static function forImport(Import $import): string
    {
        return self::compose(
            'import',
            (string) $import->importer,
            (int) $import->successful_rows,
            $import->getFailedRowsCount(),
        );
    }

    public static function forExport(Export $export): string
    {
        return self::compose(
            'export',
            (string) $export->exporter,
            (int) $export->successful_rows,
            $export->getFailedRowsCount(),
        );
    }

    /** The lang-key stem a transfer class answers to: `LedgerAccountImporter` → `ledger_account`. */
    public static function keyFor(string $class): string
    {
        return Str::snake((string) preg_replace('/(Importer|Exporter)$/', '', class_basename($class)));
    }

    private static function compose(string $direction, string $class, int $successful, int $failed): string
    {
        $key = self::keyFor($class);

        // `:rows`, never `:count`. `Translator::choice()` sets `$replace['count'] = $number` AFTER
        // merging the caller's replacements, so a thousands-separated figure passed as `count` is
        // silently discarded and a 12,500-row import reports "12500".
        $sentences = [
            __("admin.data_transfer.{$direction}.{$key}"),
            trans_choice("admin.data_transfer.rows.{$direction}", $successful, ['rows' => number_format($successful)]),
        ];

        // Only when something failed. A "0 rows failed" clause on every clean run is noise that
        // trains the reader to stop reading the sentence that matters.
        if ($failed > 0) {
            $sentences[] = trans_choice("admin.data_transfer.failed.{$direction}", $failed, ['rows' => number_format($failed)]);
        }

        // What to do NEXT — the one thing a transfer may add for itself, and a KEY rather than an
        // override so it is stated in both languages or not at all. Keyed by direction as well as
        // by transfer, so an exporter can never inherit an importer's advice.
        if (Lang::has($followUp = "admin.data_transfer.followup.{$direction}.{$key}")) {
            $sentences[] = __($followUp);
        }

        return implode(' ', $sentences);
    }
}
